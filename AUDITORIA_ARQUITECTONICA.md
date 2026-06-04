# AUDITORÍA ARQUITECTÓNICA — DASHBOARDVIEW.VUE
**Fecha:** 2026-06-03 | **Auditor:** Arquitecto Senior | **Proyecto:** Sello Pronto Delivery

---

## 1. RESPONSABILIDADES ACTUALES

Basado en `frontend/src/views/DashboardView.vue`:

| Responsabilidad | Complejidad | Riesgo | Debe separarse |
|---|---|---|---|
| Orquestación de roles (superadmin vs client_admin) | Media | Medio | No — es el contrato del componente |
| Fetch inicial de datos (`fetchDashboardData`) | Alta | **Alto** | Parcialmente — la lógica de agregación puede ir a un composable |
| Inicialización del mapa con `setTimeout(800)` | Media | **Alto** | No — pero el magic number debe eliminarse |
| Coordinación entre `useOrders` ↔ `useDashboardMap` | Alta | **Alto** | Sí — acoplamiento indirecto oculto via `mapCtx()` |
| Toggle vista Mapa ↔ ActivityFeed | Baja | Bajo | No — es estado local puro |
| Gestión de modales de creación de pedido | Baja | Bajo | No — flags locales simples |
| Cálculo de `pendingOrders` / `scheduledOrders` / `activeOrdersList` | Media | Bajo | Sí — pertenece a `useOrders` |
| Construcción de `mapCtx()` en cada llamada | Baja | Medio | Sí — objeto efímero con referencias cruzadas |
| Inicio/parada del polling | Baja | Bajo | Ya delegado a `useRealtimeSync` |
| Destrucción del mapa en unmount | Baja | Bajo | Ya delegado a `useDashboardMap` |

---

## 2. MAPA DE DEPENDENCIAS

### Dependencias directas

```
DashboardView.vue (frontend/src/views/DashboardView.vue)
│
├── [STORE] useAuthStore (stores/auth.js)
│     └── localStorage (token, user)
│
├── [HTTP]  api (api.js → Axios + JWT interceptor)
│
├── [COMPOSABLE] useOrders (composables/useOrders.js)
│     ├── api (fetch /orders, PUT /orders/:id/cancel)
│     ├── MapService ← ⚠️  ACOPLAMIENTO DIRECTO MAP-ORDERS
│     └── MODULE-LEVEL STATE: orders, selectedOrder, routeInfo
│
├── [COMPOSABLE] useDrivers (composables/useDrivers.js)
│     └── MODULE-LEVEL STATE: drivers
│
├── [COMPOSABLE] useToast (composables/useToast.js)
│     └── LOCAL STATE: toasts array
│
├── [COMPOSABLE] useDashboardMap (composables/useDashboardMap.js)
│     └── MapService (services/maps/MapService.js)
│           └── GoogleProvider (services/maps/GoogleProvider.js)
│                 ├── google.maps SDK (carga async lazy)
│                 ├── DirectionsService
│                 └── DirectionsRenderer
│
├── [COMPOSABLE] useRealtimeSync (composables/useRealtimeSync.js)
│     ├── api (fetch /orders, /drivers cada 3s)
│     ├── realtime.js (Pusher singleton)
│     ├── orders.value (REF EXTERNA desde useOrders) ← ⚠️
│     ├── drivers.value (REF EXTERNA desde useDrivers) ← ⚠️
│     └── MODULE-LEVEL STATE: refreshInterval
│
├── [COMPONENTE] StatsGrid.vue — props only, sin side effects
├── [COMPONENTE] OrdersSidebar.vue → OrderCard.vue — emit: select-order
├── [COMPONENTE] DashboardMap.vue — solo <div id="map-root">
├── [COMPONENTE] OrderDetailPanel.vue — props + emit: close, cancel
├── [COMPONENTE] FleetSidebar.vue → DriverCard.vue — emit: focus-driver
│
├── [COMPONENTE] ActivityFeed.vue
│     └── useActivityFeed (composables/useActivityFeed.js) ← ⚠️ ISLA SEPARADA
│           ├── api (fetch /orders, /drivers INDEPENDIENTE)
│           ├── MODULE-LEVEL STATE: orders, drivers, globalClock
│           ├── simulatedStartCache (Map global)
│           └── milestonesCache (Map global)
│
├── [COMPONENTE] ToastContainer.vue — consume useToast
├── [COMPONENTE] CreateOrderModal.vue — emit: created
└── [COMPONENTE] CreateOrderManualModal.vue — emit: created
```

### Dependencias ocultas

| Dependencia | Localización | Por qué es oculta |
|---|---|---|
| `useOrders` importa `MapService` directamente | `composables/useOrders.js:3` | Un composable de pedidos no debería conocer el mapa |
| `useRealtimeSync` recibe refs externas y las muta | `composables/useRealtimeSync.js:27` | `orders.value = newOrders` muta estado de otro composable |
| `useActivityFeed` mantiene su propia copia de `orders` y `drivers` | `composables/useActivityFeed.js:67` | Duplica el estado de `useOrders` y `useDrivers` |
| `globalClock` en módulo-nivel con ref-counting manual | `composables/useActivityFeed.js:40` | Timer global que sobrevive al desmontaje si el contador falla |

---

## 3. ACOPLAMIENTOS CRÍTICOS

### 3.1 — useOrders ↔ MapService (acoplamiento directo)

```
selectOrder(order, context)       [useOrders.js:10]
  ↓
MapService.clearRoutes()          [useOrders.js:15]
MapService.removeMarker()         [useOrders.js:16]
MapService.addMarker()            [useOrders.js:41]
MapService.drawRoute()            [useOrders.js:50]
```

**Por qué es peligroso:** Si MapService no está inicializado cuando se llama `selectOrder`, fallará silenciosamente o con error. Un composable de dominio (`useOrders`) no debería importar directamente una dependencia de infraestructura (`MapService`). Impide testear `useOrders` sin un mapa inicializado.

### 3.2 — mapCtx() reconstruido en cada llamada

```js
// DashboardView.vue:80-84
const mapCtx = () => ({
  drivers: drivers.value,
  orders: orders.value,
  isDriverEnRoute: d => isDriverEnRoute(d, orders.value),
  selectedOrder: selectedOrder.value,
  clearSelection,
  showToast
})
```

**Cadena resultante:**
```
handleSelectOrder(order)          [DashboardView.vue:92]
  → selectOrder(order, { redrawDrivers: () => updateMapMarkers(mapCtx()) })
      → redrawDrivers()
          → updateMapMarkers(mapCtx())  ← mapCtx() evaluado AQUÍ, no en selectOrder
```

**Por qué es peligroso:** `mapCtx()` captura `drivers.value` y `orders.value` en el momento de invocación, no cuando se pasa como argumento. Si hay una actualización asíncrona entre `selectOrder` y `redrawDrivers`, el contexto puede ser stale.

### 3.3 — Module-level state en composables (BUG LATENTE)

```js
// useOrders.js:5-7
const orders = ref([])           // ← FUERA de useOrders()
const selectedOrder = ref(null)  // ← FUERA de useOrders()
const routeInfo = ref(null)      // ← FUERA de useOrders()

export function useOrders() { ... }
```

Mismo patrón en:
- `useDrivers.js` — `drivers` y `activeDrivers`
- `useRealtimeSync.js:5` — `refreshInterval`
- `useActivityFeed.js:67-70` — `orders`, `drivers`, `loading`, `error`

**Por qué es peligroso:** El estado persiste entre montajes del componente. Si el usuario navega fuera del Dashboard y regresa, el estado anterior sigue cargado. Si en el futuro el composable se usa en dos componentes simultáneos, comparten el mismo estado. No es lo mismo que un store (que es intencionalmente global). Es **estado global accidental**.

### 3.4 — onUnmounted registrado fuera del setup context

```js
// useRealtimeSync.js:51-55
const startPolling = ({ ... }) => {
  // ...
  onUnmounted(() => {          // ← ⚠️ Hook registrado dentro de función, no en setup
    clearInterval(refreshInterval.value)
  })
}
```

**Por qué es peligroso:** `onUnmounted` en Vue 3 debe llamarse sincrónicamente durante el setup del componente. Llamarlo dentro de `startPolling` (que se invoca dentro de `onMounted`) puede no asociarse al lifecycle del componente correcto. En la práctica, `DashboardView.vue` tiene su propio `onUnmounted` que llama `stopPolling()` explícitamente (línea 125), lo que mitiga el problema pero deja código engañoso.

### 3.5 — ActivityFeed como isla de estado independiente

```
DashboardView polling (useRealtimeSync):
  → GET /orders cada 3s → orders.value (useOrders)

ActivityFeed (useActivityFeed):
  → GET /orders en fetchData() → orders.value (useActivityFeed)
  → GET /drivers en fetchData() → drivers.value (useActivityFeed)
```

**Cuando ActivityFeed está visible Y el polling está activo:** Se ejecutan dos fetches a `/orders` y `/drivers` en paralelo, con estado duplicado y sin coordinación. El estado visible al usuario (el de `useActivityFeed`) puede diferir del estado del polling (el de `useOrders`).

### 3.6 — fetchDashboardData con setTimeout(800)

```js
// DashboardView.vue:71-74
if (viewMode.value === 'map' && !showFeed.value) {
  await nextTick()
  setTimeout(() => initDashboardMap({...}), 800)  // ← magic number
}
```

**Por qué es peligroso:** El delay de 800ms es un workaround para esperar a que el DOM esté listo, pero **nunca se ejecuta en la práctica** porque `showFeed` inicia en `true` (línea 36). Es código muerto que oculta un problema de timing original ya resuelto por el `watch(showFeed)`.

---

## 4. RIESGOS DE REFACTORIZACIÓN

### 🔴 ALTO

**R1 — MapService.destroy() no nullifica provider**

```js
// MapService.js:97-101
destroy() {
  if (this.provider) {
    this.provider.destroy()    // ← destruye el mapa
    // this.provider = null    ← FALTA ESTO
  }
}
// En re-inicialización:
initialize(containerId, options) {
  if (!this.provider) { ... }  // ← provider sigue existiendo, se saltea creación
  return this.provider.initialize(containerId, options) // ← sobre una instancia destruida
}
```

**Impacto:** Al alternar entre Mapa y ActivityFeed repetidamente, el mapa puede no re-inicializarse correctamente.

**Mitigación antes de refactorizar:** Agregar `this.provider = null` en `MapService.destroy()`.

---

**R2 — useRealtimeSync muta refs externas**

```js
// useRealtimeSync.js:27
orders.value = newOrders               // ← muta ref que pertenece a useOrders
drivers.value = driversRes.data.data   // ← muta ref que pertenece a useDrivers
```

**Impacto:** No hay contrato claro de ownership del estado. Si se cambia el origen de las refs, el polling romperá el state.

**Mitigación:** Extraer callbacks `onOrdersUpdated(orders)` y `onDriversUpdated(drivers)` en lugar de recibir refs mutables.

---

**R3 — cancelOrder usa confirm() nativo**

```js
// useOrders.js:72
const confirmCancel = confirm(`¿Estás seguro de que deseas cancelar el viaje #${orderId}?`)
```

**Impacto:** Bloquea el hilo principal. No se puede testear automáticamente. Inconsistente con la UX del resto del sistema.

**Mitigación antes de extraer:** Reemplazar con un modal Vue antes de mover la lógica.

---

### 🟡 MEDIO

**R4 — Estado duplicado orders/drivers (Dashboard vs ActivityFeed)**

**Qué puede romperse:** Si se sincroniza el estado, los filtros de `useActivityFeed` (solo activos) y los de `useOrders` (todos) pueden colisionar.

**Mitigación:** Unificar en un store Pinia con selectors, o asegurarse de que `useActivityFeed` consuma las refs de `useOrders`.

---

**R5 — pendingOrders computed con parser manual de fecha**

```js
// DashboardView.vue:43-46
const p = o.scheduled_at.split(/[- :]/)
return new Date(parseInt(p[0]), parseInt(p[1]) - 1, ...) > now
```

**Qué puede romperse:** Si el formato de `scheduled_at` cambia en el backend. Los dos parsers de fecha en el proyecto son inconsistentes:
- `DashboardView.vue:44` — split manual
- `useActivityFeed.js:311` — `.replace(' ', 'T') + 'Z'`

**Mitigación:** Unificar parsing de fechas en un helper antes de mover este computed.

---

**R6 — Coordinación de initDashboardMap vía watch + fetchDashboardData**

**Qué puede romperse:** El mapa se inicializa desde dos lugares (watcher y fetchDashboardData). Si se altera el flujo de mount, puede inicializarse antes de que el DOM exista.

**Mitigación:** Consolidar en un único punto de inicialización antes de refactorizar.

---

### 🟢 BAJO

**R7 — module-level state en composables (impacto en desarrollo, no producción)**

**Qué puede romperse:** Solo si el componente se monta más de una vez en el mismo lifecycle de la app. Actualmente el router re-monta el dashboard limpiamente.

**Mitigación:** Mover estado dentro de las funciones del composable. Seguro de hacer componente por componente.

---

**R8 — globalClock con ref-counting manual**

**Qué puede romperse:** Si `startGlobalClock`/`stopGlobalClock` se desequilibran, el timer puede quedar huérfano.

**Mitigación:** Aceptable como está. No tocar hasta que haya un caso real.

---

## 5. COMPONENTES CANDIDATOS

Ordenados por prioridad de extracción (mayor seguridad primero):

| Componente | Qué extraer | Beneficio | Dificultad | Riesgo |
|---|---|---|---|---|
| `CommandBar.vue` | La barra `.map-command-bar` con greeting + chips + toggle | Elimina 25 líneas de template en DashboardView | Baja | Muy bajo — solo props |
| `DashboardShell.vue` | El layout de 4 columnas (sidebar + map + panel + fleet) | Aisla el grid del orquestador | Media | Bajo — solo layout |
| `MapMarkerPopup` | Los strings HTML de popups en `useDashboardMap.js:79-80` | Elimina HTML concatenado en JS | Baja | Muy bajo |
| `ConfirmCancelModal.vue` | El `confirm()` nativo en `cancelOrder` | UX consistente + testeable | Media | Bajo |

Los componentes `StatsGrid`, `OrdersSidebar`, `DashboardMap`, `OrderDetailPanel`, `FleetSidebar`, `ActivityFeed` **ya están correctamente extraídos**. No requieren cambios estructurales.

---

## 6. COMPOSABLES CANDIDATOS

### 6.1 — `useDashboardData` (NUEVO)

**Responsabilidad:** Consolidar el `fetchDashboardData` que hoy vive en `DashboardView.vue:54-78`.

**Dependencias:** `api`, `useAuthStore`, `useOrders`, `useDrivers`

**Beneficio:** El componente deja de tener lógica de fetch directa.

**Riesgo de extracción:** Bajo. No toca el mapa ni el DOM.

---

### 6.2 — `useOrderFilters` (NUEVO o integrar en useOrders)

**Responsabilidad:** Los computeds `pendingOrders`, `scheduledOrders`, `activeOrdersList` en `DashboardView.vue:41-49`.

**Dependencias:** `orders` ref, lógica de fecha.

**Beneficio:** DashboardView queda libre de lógica de filtrado; los filtros son testeable de forma aislada.

**Riesgo de extracción:** Bajo. Es lógica pura sobre un array.

**Acción requerida:** Primero unificar el parser de `scheduled_at` (ver R5).

---

### 6.3 — Refactorizar `useOrders` para eliminar MapService

**Responsabilidad:** `selectOrder` no debería llamar a `MapService` directamente.

**Propuesta:** `selectOrder` retorna los datos de ruta (coordenadas) y el llamador decide qué hacer con el mapa.

```js
// Antes (useOrders.js:50-58):
const result = await MapService.drawRoute(...)

// Después:
const routePoints = { pickup: [lat, lng], drop: [dropLat, dropLng] }
return { routePoints, routeInfo: result }
// DashboardView o useDashboardMap hace el drawRoute
```

**Riesgo de extracción:** Medio — requiere cambiar la firma de `handleSelectOrder` en DashboardView.

---

### 6.4 — Refactorizar `useRealtimeSync` para eliminar mutación de refs externas

**Propuesta:** Recibir callbacks en lugar de refs:

```js
startPolling({
  role,
  onOrdersUpdate: (orders) => { ... },
  onDriversUpdate: (drivers) => { ... },
  onStatusChange: (msg) => showToast(msg)
})
```

**Riesgo de extracción:** Bajo — es un cambio de interfaz interna, no de comportamiento.

---

## 7. SERVICES CANDIDATOS

| Service | Estado actual | Acción requerida |
|---|---|---|
| `MapService.js` | Existe. Facade Singleton correcto. | Agregar `this.provider = null` en `destroy()` |
| `GoogleProvider.js` | Existe. Implementación completa. | Verificar manejo de re-init sobre instancia destruida |
| `realtime.js` | Existe. Singleton Pusher correcto. | Ninguna — bien implementado |
| `DateParser` (util) | **No existe** | Crear: unificar `scheduled_at` y `updated_at` parsing |
| `OrderStatusService` | **No existe** | Considerar: centralizar los strings de status que aparecen hardcodeados en 6+ archivos |

### Fix prioritario en MapService.js

```js
// MapService.js:97-101 — FIX SEGURO
destroy() {
  if (this.provider) {
    this.provider.destroy()
    this.provider = null  // ← agregar esta línea
  }
}
```

---

## 8. ESTADO GLOBAL

### Estado que debe permanecer local (en componente)

| Estado | Dónde | Por qué |
|---|---|---|
| `showFeed` | DashboardView | Toggle UI puro, nadie más lo necesita |
| `showCreateOrder` / `showCreateOrderManual` | DashboardView | Flags de modal, locales por definición |
| `viewMode` | DashboardView | Estado de navegación de esta vista |
| `loading` | DashboardView | Spinner de carga inicial |
| `clientZones` | DashboardView | Solo `hasZones` se pasa a ActivityFeed como boolean |

### Estado que debería estar en composable (como hoy, pero corregido)

| Estado | Composable actual | Problema |
|---|---|---|
| `orders` | `useOrders` (module-level) | Mover dentro de la función |
| `selectedOrder` | `useOrders` (module-level) | Mover dentro de la función |
| `routeInfo` | `useOrders` (module-level) | Mover dentro de la función |
| `drivers` | `useDrivers` (module-level) | Mover dentro de la función |

### Estado que debería ir a Pinia Store

**Actualmente NO hay evidencia de que se necesite Pinia** para orders o drivers, salvo por un caso específico:

Si en el futuro `useActivityFeed` y `useOrders` deben compartir el **mismo array reactivo** sin duplicarlo, ese sería el momento de crear un `useOrdersStore`. Actualmente no es necesario porque ActivityFeed es una vista alternativa, no concurrente con el mapa.

**Lo que sí debería estar en un store y ya está:** `auth.js` — correcto, bien implementado, no cambiar.

---

## 9. PLAN DE REFACTORIZACIÓN

> **Principio rector:** Cada fase debe dejar el sistema funcionando en producción al terminar. Si una fase se suspende, lo avanzado no debe requerir rollback.

---

### FASE 1 — Correcciones de bugs silenciosos (sin cambiar arquitectura)

**Objetivo:** Eliminar los riesgos identificados sin tocar la estructura.

**Duración estimada:** 1-2 días

#### Tareas:

1. **[MapService.js:100]** Agregar `this.provider = null` después de `this.provider.destroy()`
2. **[useOrders.js:72]** Reemplazar `confirm()` nativo con un emit de evento al componente padre para mostrar un modal de confirmación existente
3. **[DashboardView.vue:43-46]** Unificar el parser de fechas con el mismo patrón que usa `useActivityFeed.js:311`
4. **[DashboardView.vue:73]** Eliminar el `setTimeout(800)` muerto (el watcher ya cubre ese caso)
5. **[useRealtimeSync.js:51-55]** Eliminar el `onUnmounted` interno — DashboardView ya tiene el suyo explícito

#### Validaciones:
- Alternar entre Mapa y ActivityFeed 10+ veces sin que el mapa quede en blanco
- Cancelar un pedido activo y confirmar que el modal (o confirm) funciona
- Verificar en consola que no hay `setInterval` huérfanos después de navegar fuera del dashboard

**Riesgos de esta fase:** Muy bajo. Son correcciones quirúrgicas.

---

### FASE 2 — Extracción de componentes visuales puros

**Objetivo:** Reducir el template de DashboardView sin tocar lógica.

**Duración estimada:** 1-2 días

#### Tareas:

1. Extraer `CommandBar.vue` del bloque `.map-command-bar` (líneas 146-161 del template)
   - Props: `userName`, `showFeed`, `stats`
   - Emits: `update:showFeed`
2. Extraer `ConfirmCancelModal.vue` para reemplazar el `confirm()` de `cancelOrder`

#### Validaciones:
- El toggle de vista funciona exactamente igual
- Cancelar pedido muestra un modal Vue en lugar del dialog nativo
- No hay regresión visual en el command bar

**Riesgos de esta fase:** Bajo.

---

### FASE 3 — Corrección de module-level state en composables

**Objetivo:** Hacer que cada instancia del composable tenga su propio estado.

**Duración estimada:** 2-3 días

> **Precaución:** Esta fase requiere verificar que en todo el proyecto cada composable se llama desde un **único lugar**. Si hay usos en otros componentes que dependen del estado compartido (comportamiento de singleton), moverlo dentro romperá esos usos.

#### Tareas:

1. **`useOrders.js`**: Mover `orders`, `selectedOrder`, `routeInfo` dentro de `useOrders()`
2. **`useDrivers.js`**: Mover `drivers` dentro de `useDrivers()`
3. **`useRealtimeSync.js`**: Mover `refreshInterval` dentro de `useRealtimeSync()`
4. **`useOrders.js`**: Refactorizar `selectOrder` para que no importe MapService directamente
5. **`useRealtimeSync.js`**: Cambiar de recibir refs mutables a recibir callbacks

#### Validaciones:
- El polling actualiza el mapa correctamente cada 3 segundos
- Seleccionar un pedido dibuja la ruta en el mapa
- Deseleccionar limpia la ruta y centra en Celaya
- Navegar a otra ruta y volver no deja estado residual (orders vacías al regresar)

**Riesgos de esta fase:** Medio. Requiere verificar todos los consumers de cada composable.

---

### FASE 4 — Unificación del estado de orders entre Dashboard y ActivityFeed

**Objetivo:** Eliminar el doble fetch y el estado duplicado.

**Duración estimada:** 2-3 días

#### Tareas:

1. Hacer que `useActivityFeed` consuma las refs de `useOrders` en lugar de hacer fetch propio
2. O: crear `useOrdersStore` (Pinia) con un único array reactivo
3. `DashboardView` pasa `orders` y `drivers` ya cargados a `ActivityFeed` vía props (ya ocurre parcialmente — `DashboardView.vue:177-180`)
4. `ActivityFeed.vue` usa las props recibidas en lugar de llamar `useActivityFeed.fetchData()` en `onMounted`

#### Validaciones:
- Al cambiar a ActivityFeed, los datos son inmediatamente los mismos que veía el mapa
- No hay doble request a `/orders` cuando ActivityFeed está visible
- El polling sigue actualizando los datos visibles en ActivityFeed

**Riesgos de esta fase:** Medio-Alto. Requiere coordinación cuidadosa de lifecycle.

---

### FASE 5 — Extracción de composables de lógica de negocio

**Objetivo:** DashboardView queda como puro orquestador sin lógica de datos.

**Duración estimada:** 3-4 días

#### Tareas:

1. Crear `useDashboardData` que contenga `fetchDashboardData`
2. Mover `pendingOrders`, `scheduledOrders`, `activeOrdersList` a `useOrders`
3. Crear `useDateParser` helper para unificar parsing de fechas del backend
4. Crear `ORDER_STATUSES` enum compartido para eliminar strings hardcodeados en 6+ archivos

#### Validaciones:
- DashboardView script queda < 50 líneas
- Todos los filtros de órdenes retornan los mismos resultados que antes
- No hay cambio en comportamiento visible

**Riesgos de esta fase:** Bajo. Lógica pura, no toca DOM ni mapa.

---

## 10. EVIDENCIA Y LIMITACIONES

### Evidencia encontrada en código

| Hallazgo | Archivo | Línea |
|---|---|---|
| Module-level state en composables | `useOrders.js` | 5-7 |
| Module-level state en composables | `useDrivers.js` | — |
| Module-level state en composables | `useRealtimeSync.js` | 5 |
| Module-level state en composables | `useActivityFeed.js` | 67-70 |
| `onUnmounted` en contexto incorrecto | `useRealtimeSync.js` | 51 |
| `MapService.destroy()` sin nullify | `MapService.js` | 97-101 |
| Acoplamiento MapService en useOrders | `useOrders.js` | 3, 15, 16, 41, 50 |
| Doble fetch de /orders | `useRealtimeSync.js` + `useActivityFeed.js` | 11 / 376 |
| `confirm()` nativo en lógica de negocio | `useOrders.js` | 72 |
| Parsers de fecha inconsistentes | `DashboardView.vue` + `useActivityFeed.js` | 44 / 311 |
| `setTimeout(800)` dead code | `DashboardView.vue` | 73 |

### NO SE ENCONTRÓ EVIDENCIA SUFICIENTE para concluir

- Si `GoogleProvider.initialize()` maneja re-inicialización sobre instancia destruida (requiere leer `GoogleProvider.js` completo para confirmar)
- Si el ref-counting del `globalClock` ha causado leaks reales en producción
- Si `createDriverMapIcon: undefined` en `DashboardView.vue:93` causa errores en casos reales o si es comportamiento intencional

---

## RESUMEN EJECUTIVO

El sistema está **bien estructurado en su superficie**: la separación en composables es correcta en concepto, el MapService facade es sólido, y el componente orquestador (DashboardView) es notablemente compacto para lo que hace (292 líneas totales).

Los problemas son **tres bugs específicos** (module-level state, MapService.destroy sin null, onUnmounted fuera de contexto) y **un problema de diseño** (estado duplicado entre Dashboard y ActivityFeed) que se puede resolver incrementalmente sin reescribir nada.

**La refactorización más valiosa, por impacto y bajo riesgo, es la Fase 1** (correcciones de bugs) seguida de la **Fase 3** (module-level state). Las fases 4 y 5 son opcionales mientras el sistema funcione en producción.
