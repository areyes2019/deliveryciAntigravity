Actúa como desarrollador senior Vue 3 Composition API.

CONTEXTO:

`useRealtimeSync.js` define `setupRealtimeListeners()` con nombres de eventos y
callbacks incorrectos — el backend nunca emite lo que esta función espera.
Este es el desacuerdo exacto encontrado en la auditoría:

CANAL: orders.{clientId}
  Frontend espera evento:  'order-updated'     ← EL BACKEND NUNCA EMITE ESTO
  Backend emite evento:    'order-cancelled'   ← OrderController.php:150

CANAL: trips.{clientId}
  Frontend espera evento:  'driver-moved'      ← EL BACKEND NUNCA EMITE ESTO
  Backend emite evento:    'new-trip'          ← OrderService.php:213 y 361, OrderController.php:210
  Backend emite evento:    'trip-taken'        ← DriverApiController.php:140

PAYLOADS QUE EMITE EL BACKEND:

  orders.{clientId} → 'order-cancelled':
    { order_id: number }

  trips.{clientId}  → 'new-trip':
    { trip_id: number }

  trips.{clientId}  → 'trip-taken':
    { trip_id: number, driver_id: number, status: 'tomado' }

ARCHIVO A MODIFICAR:

frontend/src/composables/useRealtimeSync.js

CÓDIGO ACTUAL (líneas 68-88):

```js
const setupRealtimeListeners = ({ clientId, onOrderUpdated, onDriverMoved }) => {
  if (!clientId) return

  const channel = subscribe(`orders.${clientId}`)
  channel.bind('order-updated', (data) => {
    if (onOrderUpdated) onOrderUpdated(data)
  })

  const driverChannel = subscribe(`trips.${clientId}`)
  driverChannel.bind('driver-moved', (data) => {
    if (onDriverMoved) onDriverMoved(data)
  })

  // Limpiar listeners al desmontar
  onUnmounted(() => {
    unsubscribe(`orders.${clientId}`)
    unsubscribe(`trips.${clientId}`)
  })

  return { channel, driverChannel }
}
```

CAMBIO REQUERIDO:

```js
const setupRealtimeListeners = ({ clientId, onOrderCancelled, onNewTrip, onTripTaken }) => {
  if (!clientId) return

  const channel = subscribe(`orders.${clientId}`)
  channel.bind('order-cancelled', (data) => {
    if (onOrderCancelled) onOrderCancelled(data)
  })

  const tripsChannel = subscribe(`trips.${clientId}`)
  tripsChannel.bind('new-trip', (data) => {
    if (onNewTrip) onNewTrip(data)
  })
  tripsChannel.bind('trip-taken', (data) => {
    if (onTripTaken) onTripTaken(data)
  })

  // Limpiar listeners al desmontar
  onUnmounted(() => {
    unsubscribe(`orders.${clientId}`)
    unsubscribe(`trips.${clientId}`)
  })

  return { channel, tripsChannel }
}
```

REQUISITOS:

- Renombrar el parámetro `onOrderUpdated` → `onOrderCancelled`.
- Renombrar el parámetro `onDriverMoved` → `onNewTrip`.
- Agregar el parámetro `onTripTaken`.
- Renombrar el evento `'order-updated'` → `'order-cancelled'` en el bind.
- Renombrar el evento `'driver-moved'` → `'new-trip'` en el bind.
- Agregar el binding de `'trip-taken'` en el canal trips.{clientId}.
- Renombrar la variable interna `driverChannel` → `tripsChannel` (claridad semántica).
- Mantener el `onUnmounted` con `unsubscribe` — es correcto aquí porque
  `setupRealtimeListeners` se llama durante el setup del componente.
- NO modificar `startPolling` ni `stopPolling`.
- NO cambiar imports.
- NO modificar ninguna otra función.

NOTA IMPORTANTE:

Ningún componente llama a `setupRealtimeListeners` todavía — este prompt
solo corrige los nombres para que coincidan con lo que el backend emite.
El prompt 02 conectará esta función al Dashboard.

VALIDACIÓN ESPERADA:

El archivo compila sin errores. Como ningún componente llama a
`setupRealtimeListeners` aún, no hay cambio de comportamiento observable.
El test es visual: el código de la función tiene los nombres correctos.

Devuelve el archivo `useRealtimeSync.js` completo.
