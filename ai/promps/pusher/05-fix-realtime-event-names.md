Actúa como desarrollador senior Vue 3 Composition API.

CONTEXTO:

`useRealtimeSync.js` define `setupRealtimeListeners()` pero escucha eventos
con nombres incorrectos — el backend nunca los emite. Este es el desacuerdo:

CANAL: orders.{clientId}
  Frontend espera: 'order-updated'     ← BACKEND NUNCA EMITE ESTO
  Backend emite:   'order-cancelled'   ← EN ESTE CANAL

CANAL: trips.{clientId}
  Frontend espera: 'driver-moved'      ← BACKEND NUNCA EMITE ESTO
  Backend emite:   'new-trip'          ← CUANDO UNA ORDEN SE PUBLICA
  Backend emite:   'trip-taken'        ← CUANDO UN DRIVER ACEPTA

PAYLOADS QUE EMITE EL BACKEND:

orders.{clientId} → 'order-cancelled':
  { order_id: number }

trips.{clientId} → 'new-trip':
  { trip_id: number }

trips.{clientId} → 'trip-taken':
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

  onUnmounted(() => {
    unsubscribe(`orders.${clientId}`)
    unsubscribe(`trips.${clientId}`)
  })

  return { channel, tripsChannel }
}
```

REQUISITOS:

- Renombrar los parámetros del destructuring: `onOrderCancelled`, `onNewTrip`, `onTripTaken`.
- Renombrar el evento `'order-updated'` → `'order-cancelled'`.
- Renombrar el evento `'driver-moved'` → `'new-trip'`.
- Agregar el binding de `'trip-taken'` en el canal `trips.{clientId}`.
- Renombrar la variable interna `driverChannel` → `tripsChannel` para mayor claridad.
- Mantener el `onUnmounted` interno en este caso (es correcto aquí porque
  `setupRealtimeListeners` se llama durante el setup del componente, no en onMounted).
- No modificar `startPolling` ni `stopPolling`.
- No cambiar imports.

VALIDACIÓN ESPERADA:

Ningún componente llama todavía a `setupRealtimeListeners` — este prompt
solo prepara la función para que tenga los nombres correctos.
El siguiente prompt (06) conectará esta función al Dashboard.

Devuelve el archivo `useRealtimeSync.js` completo.
