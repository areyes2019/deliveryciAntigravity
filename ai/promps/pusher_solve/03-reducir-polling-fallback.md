Actúa como desarrollador senior Vue 3 Composition API.

CONTEXTO:

Con Pusher activo en el Dashboard (prompt 02 aplicado y validado), el polling
de 3 segundos pasa a ser un fallback de seguridad, no el mecanismo principal.

Mantenerlo a 3s genera ~40 requests HTTP por minuto por sesión activa
(20 GET /orders + 20 GET /drivers). Con Pusher entregando los eventos críticos,
ese costo es innecesario.

El modelo correcto ya existe en DriverAppView.vue (evidencia en código):
  // DriverAppView.vue:510
  // Pusher maneja los cambios instantáneos; el polling es solo red de seguridad (fallback).
  pollInterval = setInterval(() => {
    if (!activeOrder.value && !isAccepting.value) loadAvailableOrders()
  }, 8000)  // ← 8 segundos, NO 3

Para el Dashboard (menos crítico en latencia que el driver), 15 segundos es adecuado.

PREREQUISITO:

Los prompts 01 y 02 deben estar aplicados ANTES de ejecutar este.
Pusher debe estar activo y VALIDADO en el Dashboard en producción o staging.

ADVERTENCIA: Si el prompt 02 no está aplicado, NO reducir el polling.
Con Pusher desconectado, 3 segundos es el único mecanismo de actualización.

ARCHIVO A MODIFICAR:

frontend/src/composables/useRealtimeSync.js

CÓDIGO ACTUAL (línea 48):

```js
refreshInterval.value = setInterval(() => {
  if (role.value === 'client_admin') {
    silentUpdate()
  }
}, 3000)
```

CAMBIO REQUERIDO:

```js
refreshInterval.value = setInterval(() => {
  if (role.value === 'client_admin') {
    silentUpdate()
  }
}, 15000)
```

REQUISITOS:

- Cambiar ÚNICAMENTE el valor `3000` → `15000` en la llamada a `setInterval`.
- No tocar ninguna otra línea del archivo.
- No modificar `silentUpdate`.
- No modificar `setupRealtimeListeners`.
- No modificar `startPolling` ni `stopPolling`.

BENEFICIO ESPERADO:

| Métrica | Antes (3s) | Después (15s) |
|---|---|---|
| Requests HTTP/min por sesión | ~40 | ~8 |
| Carga en servidor en hora pico | Alta | Significativamente menor |
| Latencia máxima si Pusher falla | 3s | 15s |

VALIDACIÓN ESPERADA:

- En Network DevTools: requests a `/orders` y `/drivers` cada ~15s (no cada 3s).
- Al crear una orden, el Dashboard la muestra inmediatamente vía Pusher (no espera 15s).
- Si se simula fallo de Pusher (bloquear WebSocket en DevTools),
  el Dashboard se actualiza cada 15 segundos máximo — comportamiento aceptable.

Devuelve únicamente la función `startPolling` completa con el cambio aplicado.
