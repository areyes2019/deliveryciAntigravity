Actúa como desarrollador senior Vue 3 Composition API.

CONTEXTO:

Con Pusher activo en el Dashboard (prompt 06 aplicado), el polling de 3 segundos
pasa a ser un fallback de seguridad, no el mecanismo principal.
Mantenerlo a 3s genera 40 requests HTTP por minuto innecesariamente.

El modelo correcto ya existe en DriverAppView.vue:
  Pusher → cambios instantáneos
  Polling → red de seguridad a 8 segundos

Para el Dashboard (que tiene menos urgencia que el driver), 15 segundos es adecuado.

PREREQUISITO:

Los prompts 05 y 06 deben estar aplicados antes de ejecutar este.
Pusher debe estar activo y validado en el Dashboard.

ARCHIVO A MODIFICAR:

frontend/src/composables/useRealtimeSync.js

CÓDIGO ACTUAL (línea 44):

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

- Cambiar únicamente el valor `3000` → `15000`.
- No tocar ninguna otra línea.
- No modificar `silentUpdate`.
- No modificar `setupRealtimeListeners`.

CUÁNDO NO APLICAR ESTE PROMPT:

Si el prompt 06 no está aplicado todavía, NO reducir el polling.
Con Pusher desconectado, 3 segundos es el único mecanismo de actualización.

VALIDACIÓN ESPERADA:

- En Network DevTools: requests a `/orders` y `/drivers` cada 15s (no cada 3s).
- Al crear una orden, el Dashboard la muestra inmediatamente vía Pusher.
- Si se simula fallo de Pusher (bloquear WebSocket en DevTools),
  el Dashboard se actualiza cada 15 segundos máximo.

Devuelve únicamente la función `startPolling` completa con el cambio aplicado.
