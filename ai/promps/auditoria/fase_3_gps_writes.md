# SPEC — Fase 3: Filtro de Distancia en Escrituras de GPS

**Origen**: Auditoría técnica 2026-06-18  
**Alcance**: Un solo método en un controlador PHP.  
**Riesgo**: Bajo — el Pusher trigger sigue disparando en cada ping; solo se protege
la escritura en MySQL con un umbral de distancia.  
**Tiempo estimado**: 1-2 horas incluyendo prueba con la app de conductor.

---

## Contexto

Cada vez que la app del conductor envía su posición GPS, el endpoint
`PUT /api/v1/driver/location` ejecuta la siguiente secuencia:

```
1. SELECT drivers WHERE user_id = ?      ← leer coordenadas actuales del driver
2. UPDATE drivers SET current_lat, current_lng  ← escribir en MySQL
3. POST https://api.pusher.com/...       ← HTTP externo (sincrónico)
```

No hay ningún umbral de distancia mínima. Si la app envía pings cada 3 segundos y el
conductor está detenido, cada ping genera un SELECT + UPDATE contra MySQL aunque las
coordenadas sean idénticas o difieran en centímetros.

**`app/Controllers/Api/V1/Driver/DriverApiController.php` líneas 333–364:**

```php
public function updateLocation()
{
    $userData = $this->request->jwtPayload;
    $driver = $this->driverModel->where('user_id', $userData['id'])->first();

    if (!$driver) {
        return $this->respondError('Driver profile not found.', [], 404);
    }

    $input = $this->request->getJSON(true) ?? $this->request->getPost();

    if (!isset($input['lat']) || !isset($input['lng'])) {
        return $this->respondError('Latitude and longitude are required.');
    }

    $this->driverModel->update($driver['id'], [   // ← siempre escribe
        'current_lat' => $input['lat'],
        'current_lng' => $input['lng']
    ]);

    PusherService::trigger(                        // ← siempre dispara HTTP externo
        'trips.' . $driver['client_id'],
        'driver-location',
        [
            'driver_id' => (int) $driver['id'],
            'lat'       => (float) $input['lat'],
            'lng'       => (float) $input['lng'],
        ]
    );

    return $this->respondSuccess('Location updated successfully.');
}
```

---

## Decisión de diseño — qué filtramos y qué no

El filtro de distancia aplica **solo a la escritura en MySQL**. El trigger de Pusher
sigue disparando en cada ping porque el mapa del panel necesita fluidez visual aunque
el conductor esté casi parado. Silenciar la señal Pusher degradaría la experiencia del
mapa sin ningún beneficio real (Pusher es un HTTP no-bloqueante en la ruta crítica; la
latencia la añade principalmente el UPDATE en MySQL).

Umbral propuesto: **30 metros**. Por debajo de esa distancia se considera ruido GPS
o movimiento estacionario y se omite el UPDATE. Este valor puede ajustarse cambiando
la constante en el propio método.

---

## Corrección

```php
public function updateLocation()
{
    $userData = $this->request->jwtPayload;
    $driver   = $this->driverModel->where('user_id', $userData['id'])->first();

    if (!$driver) {
        return $this->respondError('Driver profile not found.', [], 404);
    }

    $input = $this->request->getJSON(true) ?? $this->request->getPost();

    if (!isset($input['lat']) || !isset($input['lng'])) {
        return $this->respondError('Latitude and longitude are required.');
    }

    $newLat = (float) $input['lat'];
    $newLng = (float) $input['lng'];

    // Solo escribir en MySQL si el conductor se movió más de MIN_DISTANCE_METERS.
    // Evita saturar la tabla drivers con UPDATEs cuando el vehículo está detenido.
    const MIN_DISTANCE_METERS = 30;

    $prevLat = (float) ($driver['current_lat'] ?? 0);
    $prevLng = (float) ($driver['current_lng'] ?? 0);

    if ($prevLat !== 0.0 || $prevLng !== 0.0) {
        $distanceMeters = $this->haversineMeters($prevLat, $prevLng, $newLat, $newLng);
        if ($distanceMeters < MIN_DISTANCE_METERS) {
            // El conductor no se movió lo suficiente — igual notificamos Pusher
            // para que el mapa siga respondiendo, pero no escribimos en DB.
            PusherService::trigger(
                'trips.' . $driver['client_id'],
                'driver-location',
                [
                    'driver_id' => (int) $driver['id'],
                    'lat'       => $newLat,
                    'lng'       => $newLng,
                ]
            );
            return $this->respondSuccess('Location received (no DB update — below threshold).');
        }
    }

    $this->driverModel->update($driver['id'], [
        'current_lat' => $newLat,
        'current_lng' => $newLng,
    ]);

    PusherService::trigger(
        'trips.' . $driver['client_id'],
        'driver-location',
        [
            'driver_id' => (int) $driver['id'],
            'lat'       => $newLat,
            'lng'       => $newLng,
        ]
    );

    return $this->respondSuccess('Location updated successfully.');
}

/**
 * Distancia en metros entre dos coordenadas usando la fórmula de Haversine.
 */
private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R  = 6371000; // radio de la Tierra en metros
    $φ1 = deg2rad($lat1);
    $φ2 = deg2rad($lat2);
    $Δφ = deg2rad($lat2 - $lat1);
    $Δλ = deg2rad($lng2 - $lng1);

    $a = sin($Δφ / 2) ** 2 + cos($φ1) * cos($φ2) * sin($Δλ / 2) ** 2;
    return 2 * $R * asin(sqrt($a));
}
```

> `haversineMeters` es un método privado dentro de `DriverApiController`. No necesita
> ser un servicio separado — el cálculo es de 8 operaciones trigonométricas y no tiene
> dependencias externas.

---

## Impacto esperado

Con un intervalo de ping de 3 segundos y un conductor detenido durante 10 minutos:

| Antes | Después |
|---|---|
| 200 UPDATEs contra MySQL | 0–2 UPDATEs (solo al arrancar y al moverse) |
| 200 Pusher HTTP triggers | 200 Pusher HTTP triggers (sin cambio — mapa fluido) |

Con el conductor en movimiento a velocidad urbana (~30 km/h):
- Cada 3 s recorre ~25 m → **debajo del umbral** → ~0 UPDATEs por semáforo en rojo,
  UPDATEs agrupados cada 4–5 pings cuando sí avanza.

---

## Archivos modificados

| Archivo | Tipo de cambio |
|---|---|
| `app/Controllers/Api/V1/Driver/DriverApiController.php` | Añadir umbral de distancia + método `haversineMeters` |

---

## Criterio de éxito

### Prueba — conductor detenido

1. Desde la app del conductor, iniciar sesión y dejar la app activa sin moverse 2 minutos.
2. En MySQL ejecutar:
   ```sql
   SHOW STATUS LIKE 'Com_update';
   ```
   Anotar el valor inicial. Esperar 2 minutos. Comparar. El incremento debe ser mínimo
   (solo el primer ping al arrancar si el conductor ya tenía coordenadas previas).
3. En el panel web, el marcador del conductor en el mapa debe seguir actualizándose
   visualmente (Pusher sigue enviando pings).

### Prueba — conductor en movimiento

1. Conductor recorre ~100 metros en línea recta.
2. En MySQL: `SELECT current_lat, current_lng, updated_at FROM drivers WHERE id = ?`
   ejecutado cada 30 segundos — las coordenadas deben actualizarse progresivamente.
3. El mapa en el panel debe reflejar la posición en tiempo real.

### Regresión

- `GET /api/v1/driver/current-trip` sigue devolviendo las últimas coordenadas correctas.
- El log no muestra errores de PHP relacionados con `haversineMeters` o `updateLocation`.
