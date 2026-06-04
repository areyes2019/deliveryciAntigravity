Actúa como arquitecto full-stack (PHP CodeIgniter 4 + Vue 3).

CONTEXTO:

Este prompt es OPCIONAL y tiene condiciones de aplicación estrictas.

Estado actual: `POST /driver/location` actualiza la base de datos pero NO emite
ningún evento Pusher. El Dashboard detecta cambios de posición únicamente via polling.

Evidencia del código actual (DriverApiController.php):
```php
public function updateLocation()
{
    // ... valida input, identifica driver ...
    $this->driverModel->update($driver['id'], [
        'current_lat' => $input['lat'],
        'current_lng' => $input['lng']
    ]);
    return $this->respondSuccess('Location updated successfully.');
    // ← SIN PusherService::trigger()
}
```

CONDICIONES PARA APLICAR ESTE PROMPT:

1. Los prompts 01, 02 y 03 deben estar aplicados y validados.
2. Verificar el plan de Pusher contratado ANTES de activar:
   - Si hay 10 conductores activos enviando ubicación cada 2s → 300 eventos Pusher/minuto.
   - El plan gratuito de Pusher tiene límite de 200k mensajes/día (~138 mensajes/minuto).
   - Con 10 conductores activos 8 horas/día = 144,000 mensajes solo de ubicación.
   - Si el volumen de conductores es bajo (<5 simultáneos), el riesgo es manejable.
3. Considerar rate limiting: el driver puede enviar ubicación cada 2s, pero el trigger
   podría emitirse solo cada 5-10s para reducir volumen.

OBJETIVO:

Agregar un trigger Pusher en `updateLocation()` y conectar el listener en el
Dashboard para recibir posiciones de conductores en tiempo real.

---

## PARTE 1 — BACKEND: Agregar trigger en updateLocation()

ARCHIVO A MODIFICAR:

app/Controllers/Api/DriverApiController.php

CÓDIGO ACTUAL (método updateLocation, después del update):

```php
$this->driverModel->update($driver['id'], [
    'current_lat' => $input['lat'],
    'current_lng' => $input['lng']
]);
return $this->respondSuccess('Location updated successfully.');
```

CAMBIO REQUERIDO:

```php
$this->driverModel->update($driver['id'], [
    'current_lat' => $input['lat'],
    'current_lng' => $input['lng']
]);

PusherService::trigger(
    'trips.' . $driver['client_id'],
    'driver-location',
    [
        'driver_id' => (int) $driver['id'],
        'lat'       => (float) $input['lat'],
        'lng'       => (float) $input['lng'],
    ]
);

return $this->respondSuccess('Location updated successfully.');
```

REQUISITOS BACKEND:

- Agregar el trigger DESPUÉS del update de la base de datos.
- El canal es `trips.{client_id}` — ya existe y el Dashboard se suscribirá a él.
- El evento se llama `driver-location` — nombre nuevo, no choca con eventos existentes.
- El payload incluye `driver_id`, `lat`, `lng` — lo mínimo para actualizar un marcador.
- `PusherService::trigger()` es no-fatal por diseño — si Pusher falla, el update
  de DB ya se completó y la función retorna éxito normalmente.
- NO modificar la lógica de validación ni de respuesta.
- NO agregar lógica de rate limiting en esta primera versión.

---

## PARTE 2 — FRONTEND: Escuchar driver-location en DashboardView

ARCHIVO A MODIFICAR:

frontend/src/views/DashboardView.vue

CONTEXTO:

El prompt 02 ya conectó el bloque `setupRealtimeListeners` que suscribe al canal
`trips.{clientId}`. Ahora hay que añadir el binding del nuevo evento `driver-location`
en ese mismo composable.

ARCHIVO A MODIFICAR PRIMERO:

frontend/src/composables/useRealtimeSync.js

Dentro de `setupRealtimeListeners`, agregar el tercer parámetro `onDriverLocation`
y su binding en el canal `tripsChannel`:

ANTES (en setupRealtimeListeners, después de los parámetros existentes):
```js
const setupRealtimeListeners = ({ clientId, onOrderCancelled, onNewTrip, onTripTaken }) => {
```

DESPUÉS:
```js
const setupRealtimeListeners = ({ clientId, onOrderCancelled, onNewTrip, onTripTaken, onDriverLocation }) => {
```

Y dentro del bloque de tripsChannel, después de `trip-taken`:
```js
if (onDriverLocation) {
  tripsChannel.bind('driver-location', (data) => {
    onDriverLocation(data)
  })
}
```

LUEGO en DashboardView.vue, dentro de `setupRealtimeListeners({...})`:

Agregar el handler `onDriverLocation` al objeto de configuración:

```js
onDriverLocation: ({ driver_id, lat, lng }) => {
  const driver = drivers.value.find(d => d.id === driver_id)
  if (driver) {
    driver.current_lat = lat
    driver.current_lng = lng
    updateMapMarkers(mapCtx())
  }
}
```

REQUISITOS FRONTEND:

- El handler actualiza directamente el driver en el array reactivo `drivers.value`
  (mutación puntual, no reemplaza el array completo).
- Solo llama `updateMapMarkers()` — NO hace `fetchDashboardData()` (sería costoso
  por cada actualización de ubicación).
- Si el `driver_id` no se encuentra en `drivers.value`, ignora silenciosamente.
- El handler es opcional en `setupRealtimeListeners` (guarded con `if (onDriverLocation)`).

---

## VALIDACIÓN ESPERADA

- En Network DevTools: NO hay requests HTTP adicionales al mover un driver.
- En el mapa: el marcador del conductor se mueve en tiempo real sin polling.
- Si se bloquea WebSocket: el polling de 15s resincroniza posiciones desde la DB.
- En Pusher Debug Console: aparecen eventos `driver-location` al moverse un driver.

## CUÁNDO NO APLICAR ESTE PROMPT

- Si el plan de Pusher no soporta el volumen de mensajes esperado.
- Si hay más de 10 conductores activos simultáneos sin un plan paid de Pusher.
- Si el polling de 15s es suficiente para la operación actual.

El polling de 15s (prompt 03) ya mejora significativamente la carga del servidor.
Este prompt es optimización adicional, no requisito crítico.
