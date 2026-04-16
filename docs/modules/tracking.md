# Módulo: Tracking (Seguimiento GPS)

## Problema que resuelve

Permite al panel de administración ver la posición en tiempo real de cada conductor y el estado actualizado de las órdenes en curso, sin necesidad de llamar al conductor por teléfono.

## Funcionalidades principales

- Envío periódico de coordenadas GPS desde la app del conductor.
- Visualización en mapa de la posición del conductor asignado.
- Trazado de ruta real entre pickup y drop (Google Directions API).
- Actualización de estado de la orden en cada etapa del viaje.
- Visualización del estado actual de todas las órdenes en el dashboard.

## Entidades involucradas

| Entidad | Tabla | Campo GPS |
|---|---|---|
| Driver | `drivers` | `current_lat`, `current_lng` |
| Order | `orders` | `status`, `pickup_lat/lng`, `drop_lat/lng` |
| OrderStatusLog | `order_status_log` | Historial de cambios con timestamp |

## Arquitectura del tracking

### Flujo de actualización de posición
```
App conductor (PWA)
    ↓ POST /api/v1/driver/location { lat, lng }
    ↓ cada N segundos (polling activo)
CI4 — DriverApiController::updateLocation()
    ↓
UPDATE drivers SET current_lat=?, current_lng=? WHERE id=?
    ↓
Panel admin (Vue)
    ↓ polling: GET /api/v1/orders (lee current_lat/lng de drivers)
    ↓
Google Maps — updateMarker(driverId, { lat, lng })
```

### Tecnología de tiempo real
El sistema usa **polling HTTP** (no WebSockets). El frontend consulta el backend periódicamente para obtener la posición actualizada.

- **Ventaja**: simple, sin infraestructura adicional (no requiere Ratchet, Socket.io, etc.).
- **Desventaja**: latencia de N segundos entre actualización real y visualización.
- **Mejora futura**: migrar a SSE (Server-Sent Events) o WebSockets para tracking sub-segundo.

## Cálculo de distancia

El sistema usa dos métodos según el contexto:

### Google Maps Directions API (preferido)
- El frontend calcula la ruta real por calles al crear la orden.
- Envía `distance_km` en el payload de creación.
- Más preciso — considera calles reales, no línea recta.

### Fórmula de Haversine (fallback)
- Usada cuando el frontend no envía `distance_km`.
- Calcula la distancia en línea recta entre dos coordenadas GPS.
- Implementada en `GeoHelper::haversineDistance()`.
- Precisa a nivel geodésico pero no considera el trayecto real por calles.

## Pricing por zonas (relacionado con tracking)

Cuando el cliente usa `pricing_mode = 'zone'`, el sistema detecta qué zonas cruza el viaje:

1. Si el frontend envía la polyline de la ruta (puntos GPS del trayecto), se evalúa el punto medio de cada segmento para detectar las zonas cruzadas.
2. Si no hay polyline, se evalúan solo el punto de origen y destino.

Algoritmos usados (en `GeoHelper`):
- **Ray-Casting**: determina si un punto está dentro de un polígono.
- **Distancia a segmento**: determina si un punto está cerca del borde de una zona (tolerancia de 100m configurable).

## Reglas de negocio

1. La posición GPS solo se actualiza si el conductor está autenticado con rol `driver`.
2. La posición se almacena en `drivers.current_lat/lng` — solo se guarda la última posición conocida.
3. No hay historial de posiciones GPS (no se guarda el rastro completo del viaje).
4. Si el conductor pierde conexión, su última posición conocida permanece en la BD.
5. Al desconectarse (`go-offline`), `is_active` pasa a 0 pero `current_lat/lng` mantiene la última posición.

## Casos de uso

### UC-01: Panel muestra posición del conductor en tiempo real
1. `client_admin` abre la vista de órdenes con el mapa.
2. El frontend consulta las órdenes cada N segundos.
3. Cada orden activa incluye la posición del conductor asignado.
4. El mapa actualiza el marcador del conductor.

### UC-02: Conductor envía su posición
1. La app del conductor llama a `POST /api/v1/driver/location` periódicamente.
2. El backend actualiza `current_lat/lng` en la tabla `drivers`.

### UC-03: Ruta dibujada en el mapa
1. Al aceptar un viaje, la app del conductor llama a `MapService.drawRoute()`.
2. `GoogleProvider.drawRoute()` usa Directions API para trazar la ruta real por calles.
3. Si Directions API falla, dibuja una línea recta como fallback.

## Configuración relevante

```env
# frontend/.env
VITE_GOOGLE_MAPS_API_KEY=tu_api_key_aqui
```

```php
// app/Config/Pricing.php
public float $boundaryToleranceMeters = 100.0; // Tolerancia en límites de zona
public float $crossZoneThresholdKm    = 3.0;   // Umbral viaje corto/largo
```

## Mejoras futuras sugeridas

- **WebSockets / SSE**: para reducir latencia del tracking a < 1 segundo.
- **Historial de ruta**: guardar el rastro GPS completo del viaje en una tabla separada.
- **Geofencing**: alertas automáticas cuando el conductor entra/sale de una zona.
- **ETA**: mostrar tiempo estimado de llegada al destinatario.
