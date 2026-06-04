Actúa como arquitecto Vue 3 Composition API.

CONTEXTO:

El Dashboard usa polling cada 3 segundos como único mecanismo de actualización.
Pusher ya está operativo: el backend emite 7 eventos y DriverAppView los consume
exitosamente (4 eventos, 2 canales, polling reducido a fallback de 8s).

Los 3 eventos críticos para el Dashboard ya existen en el backend y no requieren
ningún trabajo PHP adicional:

  trips.{client_id}  → 'new-trip'        (OrderService crea orden publicada)
  trips.{client_id}  → 'trip-taken'      (Driver acepta un viaje)
  orders.{client_id} → 'order-cancelled' (Admin cancela orden desde el panel)

`setupRealtimeListeners` en `useRealtimeSync.js` ya fue corregida (prompt 01)
y tiene los callbacks correctos: `onOrderCancelled`, `onNewTrip`, `onTripTaken`.

PREREQUISITO:

El prompt 01 debe estar aplicado. `setupRealtimeListeners` debe recibir:
`{ clientId, onOrderCancelled, onNewTrip, onTripTaken }`.

OBJETIVO:

Conectar Pusher al DashboardView manteniendo el polling como fallback activo.
El modelo es el mismo que DriverAppView: Pusher para cambios instantáneos,
polling como red de seguridad.

ARCHIVO A MODIFICAR:

frontend/src/views/DashboardView.vue

PASO 1 — Agregar `setupRealtimeListeners` al destructuring del import:

ANTES (línea 29):
```js
const { startPolling, stopPolling } = useRealtimeSync()
```

DESPUÉS:
```js
const { startPolling, stopPolling, setupRealtimeListeners } = useRealtimeSync()
```

PASO 2 — Modificar `onMounted` para agregar la suscripción Pusher:

ANTES (líneas 120-123):
```js
onMounted(() => {
  fetchDashboardData()
  startPolling({ role, orders, drivers, stats, showToast, updateMapMarkers: () => updateMapMarkers(mapCtx()) })
})
```

DESPUÉS:
```js
onMounted(async () => {
  await fetchDashboardData()

  startPolling({ role, orders, drivers, stats, showToast, updateMapMarkers: () => updateMapMarkers(mapCtx()) })

  if (role.value === 'client_admin') {
    const clientId = authStore.user?.client_id
    if (clientId) {
      setupRealtimeListeners({
        clientId,
        onNewTrip: async () => {
          await fetchDashboardData()
          updateMapMarkers(mapCtx())
        },
        onTripTaken: async () => {
          await fetchDashboardData()
          updateMapMarkers(mapCtx())
        },
        onOrderCancelled: async ({ order_id }) => {
          if (selectedOrder.value?.id === order_id) clearSelection()
          await fetchDashboardData()
          updateMapMarkers(mapCtx())
        }
      })
    }
  }
})
```

REQUISITOS:

- `onMounted` pasa a ser `async` para poder `await fetchDashboardData()`.
- El polling de 3 segundos DEBE seguir activo — Pusher es complemento, no reemplazo.
- Los handlers solo hacen `fetchDashboardData()` + `updateMapMarkers()` — nada más.
- `onOrderCancelled` también limpia `selectedOrder` si la orden cancelada estaba seleccionada.
- `authStore.user?.client_id` es el ID de cliente para construir el nombre del canal.
- La limpieza de canales Pusher la maneja el `onUnmounted` interno de `setupRealtimeListeners`.
- NO agregar lógica de estado local nueva.
- NO crear nuevas variables reactivas.
- NO modificar `onUnmounted` — debe quedar exactamente como está:
  ```js
  onUnmounted(() => { stopPolling(); destroyMap() })
  ```
- NO modificar ningún otro método ni composable.

NOTA SOBRE EL PAYLOAD:

El evento `order-cancelled` solo trae `{ order_id }` (payload mínimo del backend).
El handler resuelve esto haciendo `fetchDashboardData()` completo en lugar de
actualizar solo esa orden — es el mismo patrón que DriverAppView usa con `new-trip`.

VALIDACIÓN ESPERADA:

- Crear una orden desde otra sesión → DashboardView actualiza sin esperar el polling.
- Driver toma un viaje → el mapa refleja el cambio en tiempo real.
- Cancelar una orden → el marcador del mapa desaparece inmediatamente.
- Desconectar Pusher (bloquear WebSocket en DevTools) → el polling de 3s sigue actualizando.
- En Network DevTools: los eventos Pusher llegan antes de que el polling se dispare.

Devuelve únicamente el script section completo de DashboardView.vue
(de `<script setup>` a `</script>`).
