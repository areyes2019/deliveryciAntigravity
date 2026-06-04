Actúa como arquitecto Vue 3 Composition API.

CONTEXTO:

El Dashboard usa polling cada 3 segundos como único mecanismo de actualización.
Pusher ya está operativo: el backend emite eventos y DriverAppView los consume
correctamente. `setupRealtimeListeners` en `useRealtimeSync.js` ya fue corregida
(prompt 05) y tiene los nombres de eventos correctos.

OBJETIVO:

Conectar Pusher al DashboardView para que los tres eventos críticos
lleguen en tiempo real, manteniendo el polling como fallback.

PREREQUISITO:

El prompt 05 debe estar aplicado antes de ejecutar este.
`setupRealtimeListeners` debe recibir: `{ clientId, onOrderCancelled, onNewTrip, onTripTaken }`.

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

PASO 2 — Agregar la suscripción Pusher dentro de `onMounted`, DESPUÉS de `fetchDashboardData()`:

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
        onOrderCancelled: async ({ order_id }) => {
          if (selectedOrder.value?.id === order_id) clearSelection()
          await fetchDashboardData()
          updateMapMarkers(mapCtx())
        },
        onNewTrip: async () => {
          await fetchDashboardData()
          updateMapMarkers(mapCtx())
        },
        onTripTaken: async () => {
          await fetchDashboardData()
          updateMapMarkers(mapCtx())
        }
      })
    }
  }
})
```

REQUISITOS:

- El polling de 3 segundos debe seguir activo — Pusher es complemento, no reemplazo.
- `onMounted` pasa a ser `async` para poder `await fetchDashboardData()`.
- Los handlers solo hacen `fetchDashboardData` + `updateMapMarkers` — nada más.
- No agregar lógica de estado local nueva.
- No crear nuevas variables reactivas.
- No modificar `onUnmounted` — ya limpia correctamente.
- No modificar ningún otro método ni composable.
- Mantener el bloque `onUnmounted` existente sin cambios:
  ```js
  onUnmounted(() => { stopPolling(); destroyMap() })
  ```

NOTAS IMPORTANTES:

- `authStore.user?.client_id` es el ID de cliente para nombrar el canal.
- La limpieza de los canales Pusher la maneja el `onUnmounted` interno
  de `setupRealtimeListeners` (ya existente en el composable).
- Si `fetchDashboardData` falla, el polling de 3 segundos actuará como red de seguridad.

VALIDACIÓN ESPERADA:

- Crear una orden desde otra sesión → DashboardView actualiza sin esperar 3s.
- Un driver tomar un viaje → el mapa refleja el cambio en tiempo real.
- Cancelar una orden → el marcador del mapa desaparece inmediatamente.
- Desconectar Pusher (en DevTools) → el polling de 3s sigue actualizando.

Devuelve únicamente el script section completo de DashboardView.vue (de `<script setup>` a `</script>`).
