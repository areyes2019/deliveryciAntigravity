Actúa como desarrollador senior Vue 3 Composition API.

CONTEXTO:

En `useRealtimeSync.js`, la función `startPolling()` registra un `onUnmounted`
internamente. Esto viola las reglas de Vue 3: los lifecycle hooks deben
llamarse sincrónicamente durante el setup del componente, no dentro de
funciones llamadas desde `onMounted`.

DashboardView.vue ya tiene su propio `onUnmounted` que llama `stopPolling()`
explícitamente (línea 125), por lo que el cleanup interno es redundante
y potencialmente problemático.

ARCHIVO A MODIFICAR:

frontend/src/composables/useRealtimeSync.js

CÓDIGO ACTUAL (líneas 50-57):

```js
refreshInterval.value = setInterval(() => {
  if (role.value === 'client_admin') {
    silentUpdate()
  }
}, 3000)

// Limpiar al desmontar
onUnmounted(() => {
  if (refreshInterval.value) {
    clearInterval(refreshInterval.value)
    refreshInterval.value = null
  }
})
```

CAMBIO REQUERIDO:

Eliminar el bloque `onUnmounted` interno completo (líneas 51-56 del `onUnmounted`).
Dejar solo el `setInterval`.

```js
refreshInterval.value = setInterval(() => {
  if (role.value === 'client_admin') {
    silentUpdate()
  }
}, 3000)
```

REQUISITOS:

- Eliminar únicamente el `onUnmounted` interno de `startPolling`.
- No tocar `stopPolling()` — es la función que DashboardView usa correctamente.
- No cambiar el intervalo (mantener 3000ms por ahora).
- No modificar `setupRealtimeListeners`.
- No modificar imports.

VALIDACIÓN ESPERADA:

Al navegar fuera del Dashboard y volver, no deben quedar
`setInterval` activos en segundo plano. DashboardView.vue
se encarga del cleanup vía su propio `onUnmounted`.

Devuelve únicamente la función `startPolling` completa con el cambio aplicado.
