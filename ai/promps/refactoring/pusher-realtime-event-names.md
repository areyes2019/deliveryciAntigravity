# SPEC: Sincronización de Nombres de Eventos Pusher — useRealtimeSync.js

## Objetivo

Corregir el desacuerdo entre los eventos que el backend emite y los que el frontend escucha en `setupRealtimeListeners`.

---

## Contexto

`useRealtimeSync.js` contiene `setupRealtimeListeners()` con nombres de eventos y parámetros de callback que nunca coinciden con lo que el backend emite. El resultado es que ningún evento en tiempo real llega al frontend.

---

## Contrato de Eventos (fuente de verdad: backend)

| Canal | Evento backend | Archivo origen (CI4) | Payload |
|---|---|---|---|
| `orders.{clientId}` | `order-cancelled` | `OrderController.php:150` | `{ order_id: number }` |
| `trips.{clientId}` | `new-trip` | `OrderService.php:213, 361` / `OrderController.php:210` | `{ trip_id: number }` |
| `trips.{clientId}` | `trip-taken` | `DriverApiController.php:140` | `{ trip_id: number, driver_id: number, status: 'tomado' }` |

---

## Firma actual (incorrecta)

```js
// frontend/src/composables/useRealtimeSync.js — líneas 68-88
const setupRealtimeListeners = ({ clientId, onOrderUpdated, onDriverMoved }) => {
  const channel = subscribe(`orders.${clientId}`)
  channel.bind('order-updated', (data) => {     // ← backend nunca emite esto
    if (onOrderUpdated) onOrderUpdated(data)
  })

  const driverChannel = subscribe(`trips.${clientId}`)
  driverChannel.bind('driver-moved', (data) => { // ← backend nunca emite esto
    if (onDriverMoved) onDriverMoved(data)
  })

  onUnmounted(() => {
    unsubscribe(`orders.${clientId}`)
    unsubscribe(`trips.${clientId}`)
  })

  return { channel, driverChannel }
}
```

---

## Firma requerida (correcta)

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

---

## Cambios requeridos

| Elemento | Antes | Después | Motivo |
|---|---|---|---|
| Parámetro | `onOrderUpdated` | `onOrderCancelled` | Refleja el evento real del backend |
| Parámetro | `onDriverMoved` | `onNewTrip` | Refleja el evento real del backend |
| Parámetro nuevo | — | `onTripTaken` | Evento no contemplado anteriormente |
| Evento bind | `'order-updated'` | `'order-cancelled'` | Coincide con `OrderController.php:150` |
| Evento bind | `'driver-moved'` | `'new-trip'` | Coincide con `OrderService.php:213` |
| Bind nuevo | — | `'trip-taken'` | Evento no contemplado anteriormente |
| Variable interna | `driverChannel` | `tripsChannel` | Claridad semántica — el canal maneja trips, no solo drivers |
| Retorno | `{ channel, driverChannel }` | `{ channel, tripsChannel }` | Consistencia con el renombre |

---

## Fuera de alcance

- No modificar `startPolling` ni `stopPolling`.
- No cambiar imports.
- No modificar ninguna otra función del archivo.
- No conectar esta función a ningún componente (eso corresponde al paso siguiente).

---

## Criterios de aceptación

1. El archivo `useRealtimeSync.js` compila sin errores TypeScript/ESLint.
2. La función `setupRealtimeListeners` tiene exactamente tres parámetros de callback: `onOrderCancelled`, `onNewTrip`, `onTripTaken`.
3. Los tres eventos bindeados coinciden exactamente con los de la tabla de contrato.
4. No hay cambio de comportamiento observable porque ningún componente llama a `setupRealtimeListeners` aún.

---

## Notas de implementación

- `onUnmounted` es correcto en este contexto — `setupRealtimeListeners` se llama durante el setup del componente, por lo que el ciclo de vida de Vue aplica.
- Verificar que `subscribe` y `unsubscribe` ya estén importados (no agregar imports nuevos).
- Devolver el archivo completo al finalizar.
