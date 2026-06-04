Actúa como desarrollador senior Vue 3 Composition API.

CONTEXTO:

En DashboardView.vue existe un bloque de código muerto que nunca se ejecuta.
`showFeed` inicia en `true` (línea 36), por lo que la condición
`!showFeed.value` dentro de `fetchDashboardData` siempre es falsa.
El `watch(showFeed)` ya cubre la inicialización del mapa correctamente.

ARCHIVO A MODIFICAR:

frontend/src/views/DashboardView.vue

CÓDIGO ACTUAL (líneas 71-74):

```js
if (viewMode.value === 'map' && !showFeed.value) {
  await nextTick()
  setTimeout(() => initDashboardMap({ orders: orders.value, drivers: drivers.value, isDriverEnRoute: d => isDriverEnRoute(d, orders.value) }), 800)
}
```

CAMBIO REQUERIDO:

Eliminar completamente ese bloque `if`. No reemplazarlo con nada.

REQUISITOS:

- Eliminar solo ese bloque `if` y su contenido.
- No tocar el `watch(showFeed)` — ese es el responsable real.
- No tocar ninguna otra línea de `fetchDashboardData`.
- No cambiar la lógica de inicialización del mapa.
- No modificar imports.

VALIDACIÓN ESPERADA:

El mapa debe seguir inicializándose correctamente cuando el usuario
activa el toggle hacia la vista de mapa (vía `watch(showFeed)`).

Devuelve únicamente la función `fetchDashboardData` completa con el cambio aplicado.
