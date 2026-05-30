# 🏗️ PLAN DE REFACTORIZACIÓN — DashboardView.vue

## 1. RESPONSABILIDADES ACTUALES DEL COMPONENTE (13 responsabilidades en 1 archivo)

| # | Responsabilidad | Líneas | ¿Debe separarse? |
|---|---|---|---|
| 1 | **Inicialización y ciclo de vida del mapa** | 267-304 | ✅ Sí |
| 2 | **Sincronización de marcadores (drivers + orders)** | 128-189 | ✅ Sí |
| 3 | **Selección de pedido + dibujo de ruta** | 306-355 | ✅ Sí |
| 4 | **Cancelación de pedido** | 365-386 | ✅ Sí |
| 5 | **Polling cada 3s (silentUpdate)** | 75-126 | ✅ Sí |
| 6 | **Sistema de notificaciones toast** | 26-33 | ✅ Sí |
| 7 | **Estadísticas del dashboard** | 49-55, 205-251 | ✅ Sí |
| 8 | **Listas de pedidos (pendientes, programados, activos)** | 414-447 | ✅ Sí |
| 9 | **Lista de conductores activos** | 445-447 | ✅ Sí |
| 10 | **Lógica de iconos de conductores** | 9-23 | ✅ Sí |
| 11 | **Detección de cambios de estado + toasts** | 90-104 | ✅ Sí |
| 12 | **Renderizado del template completo (~320 líneas)** | 450-771 | ✅ Sí |
| 13 | **Estilos scoped (~600 líneas)** | 773-1358 | ✅ Sí |

---

## 2. QUÉ DEBE CONVERTIRSE EN COMPONENTES

### Prioridad Alta (ya existen patrones similares en el proyecto)

| Componente | Responsabilidad | Beneficio |
|---|---|---|
| **`OrderCard.vue`** | Tarjeta individual de pedido (pendiente/programado/activo) | Elimina ~60 líneas de template duplicado (las 3 secciones usan casi el mismo markup) |
| **`DriverCard.vue`** | Tarjeta individual de conductor en sidebar | Aísla lógica de avatar, saldo, estado |
| **`OrderDetailPanel.vue`** | Panel lateral de detalle del pedido seleccionado | Aísla ~70 líneas de template + lógica de ruta |
| **`ToastContainer.vue`** | Contenedor de notificaciones toast | Reutilizable en toda la app |

### Prioridad Media

| Componente | Responsabilidad |
|---|---|
| **`StatsGrid.vue`** | Grid de estadísticas (superadmin y client_admin) |
| **`MapControls.vue`** | Barra de controles flotantes del mapa (Generar IA, Manual, etc.) |
| **`MapCommandBar.vue`** | Barra superior con chips de estado |

### Prioridad Baja (puede esperar)

| Componente | Responsabilidad |
|---|---|
| **`OrdersSidebar.vue`** | Sidebar izquierdo completo de pedidos |
| **`FleetSidebar.vue`** | Sidebar derecho completo de flotilla |
| **`RouteVisual.vue`** | Visualización de ruta recogida→entrega |

---

## 3. QUÉ DEBE CONVERTIRSE EN COMPOSABLES

**Nota:** No existe carpeta `composables/` aún. Hay que crearla.

| Composable | Responsabilidad | Líneas a extraer |
|---|---|---|
| **`useOrders.js`** | Fetch, filtrado (pending/scheduled/active), selección, cancelación | ~100 líneas |
| **`useDrivers.js`** | Fetch, filtrado (activeDrivers), focusDriver, isDriverEnRoute | ~60 líneas |
| **`useDashboardMap.js`** | initDashboardMap, updateMapMarkers, redrawDrivers, selectOrder (parte mapa) | ~150 líneas |
| **`useRealtimeSync.js`** | Polling + Pusher + detección de cambios de estado + toasts | ~80 líneas |
| **`useToast.js`** | Sistema de notificaciones toast (showToast, toasts array) | ~20 líneas |
| **`useStats.js`** | Cálculo de estadísticas (activeOrders, balance, fleetBalance) | ~40 líneas |

---

## 4. QUÉ PARTES DEBEN CONVERTIRSE EN SERVICES

| Service | Estado actual | Acción |
|---|---|---|
| **`services/realtime.js`** | ✅ **YA EXISTE** con Pusher | **No se usa en Dashboard** — hay que migrar el polling a Pusher |
| **`services/maps/MapService.js`** | ✅ **YA EXISTE** como Singleton/Facade | Bien diseñado, no tocar |
| **`services/orders.js`** | ❌ No existe | Extraer lógica de llamadas API de órdenes |
| **`services/drivers.js`** | ❌ No existe | Extraer lógica de llamadas API de conductores |

---

## 5. QUÉ ESTADO DEBERÍA MOVERSE A PINIA

Actualmente solo existe `stores/auth.js`. Se podrían crear:

| Store | Estado | Justificación |
|---|---|---|
| **`stores/orders.js`** | orders[], selectedOrder, routeInfo | Múltiples componentes necesitan acceso a pedidos (Dashboard, OrdersView) |
| **`stores/drivers.js`** | drivers[] | Múltiples vistas necesitan conductores |
| **`stores/realtime.js`** | Conexión Pusher, canales activos | Centralizar suscripciones/desuscripciones |

**⚠️ Pero esto es POSTERIOR a la refactorización a composables.** Primero composables, luego stores si se necesita estado compartido entre rutas.

---

## 6. DEPENDENCIAS PELIGROSAS / ACOPLAMIENTOS FUERTES

### 🔴 Críticos (rompen si se tocan mal)

1. **`silentUpdate()` ↔ `updateMapMarkers()`** (líneas 75-126 ↔ 128-189)
   - `silentUpdate` llama a `updateMapMarkers` al final
   - Si separas en composables distintos, necesitas un mecanismo de reactividad entre ellos

2. **`selectOrder()` ↔ `redrawDrivers()`** (líneas 306-355 ↔ 253-265)
   - `selectOrder` llama a `redrawDrivers` para resetear iconos
   - Luego agrega marcador highlight del driver asignado
   - El orden de ejecución importa

3. **`clearSelection()` ↔ `redrawDrivers()`** (líneas 357-363)
   - Dependencia directa: clearSelection → redrawDrivers → MapService

4. **`onOrderCreated()` ↔ `updateMapMarkers()`** (líneas 405-411)
   - El modal emite `@created` y el dashboard actualiza el mapa

5. **`fetchDashboardData()` ↔ `initDashboardMap()`** (líneas 205-251)
   - initDashboardMap se llama DENTRO de fetchDashboardData con setTimeout de 800ms
   - Acoplamiento temporal peligroso

### 🟡 Medios

6. **`isDriverEnRoute(driver)`** (línea 191-203) depende de `orders.value`
   - Si separas drivers de orders, este computed necesita acceso a ambos

7. **Las 3 listas computadas** (pendingOrders, scheduledOrders, activeOrdersList) dependen de `orders.value`
   - Son pura derivación, fáciles de mover

8. **`hasZones`** depende de `clientZones.value` que se carga en fetchDashboardData

---

## 7. RIESGOS DE ROMPER FUNCIONALIDAD

| Riesgo | Probabilidad | Impacto | Mitigación |
|---|---|---|---|
| Romper el flujo `silentUpdate → updateMapMarkers` | Alta | 🔴 El mapa deja de actualizarse | Probar con datos reales después de cada extracción |
| Perder la detección de cambios de estado (toasts) | Media | 🟡 Notificaciones silenciosas | Mantener la lógica de diff en el mismo composable |
| Desincronizar selectedOrder con el mapa | Alta | 🔴 Panel de detalle sin ruta | Extraer selectOrder COMPLETO en un solo paso |
| setTimeout 800ms en initDashboardMap | Media | 🟡 Mapa no se inicializa | Mover a nextTick + watch del DOM |
| Perder reactividad al separar computed properties | Baja | 🟡 Listas no se actualizan | Usar computed dentro del composable y retornarlos |
| Que el modal `@created` no actualice el mapa | Media | 🟡 Nuevo pedido no aparece | Pasar evento a través del composable de mapa |

---

## 8. ORDEN IDEAL DE EXTRACCIÓN (FASES)

### 🟢 FASE 1 — Bajo riesgo, alto impacto (1-2 hrs)
*No toca el mapa ni el polling*

1. **Extraer `useToast.js`** — El sistema de toasts es completamente independiente
2. **Extraer `DriverCard.vue`** — Template puro, sin lógica de negocio
3. **Extraer `OrderCard.vue`** — Template puro, unifica los 3 tipos de tarjeta
4. **Extraer `ToastContainer.vue`** — Template puro

### 🟡 FASE 2 — Riesgo medio (2-3 hrs)
*Extraer lógica de datos, mantener compatibilidad*

5. **Extraer `useOrders.js`** — fetchOrders, computed lists, selectOrder, cancelOrder
6. **Extraer `useDrivers.js`** — fetchDrivers, activeDrivers, isDriverEnRoute, focusDriver
7. **Extraer `useStats.js`** — Cálculo de stats desde orders y drivers
8. **Extraer `OrderDetailPanel.vue`** — Template del panel lateral

### 🔴 FASE 3 — Alto riesgo (3-4 hrs)
*El mapa y el polling son los más delicados*

9. **Extraer `useDashboardMap.js`** — initDashboardMap, updateMapMarkers, redrawDrivers
10. **Extraer `useRealtimeSync.js`** — silentUpdate + detección de cambios + **migrar a Pusher**
11. **Extraer `MapControls.vue`** y `MapCommandBar.vue`

### 🟣 FASE 4 — Pulido (1-2 hrs)
*Componentes contenedores*

12. Extraer `OrdersSidebar.vue` (usa OrderCard)
13. Extraer `FleetSidebar.vue` (usa DriverCard)
14. Extraer `StatsGrid.vue`
15. DashboardView.vue queda como orquestador ~150-200 líneas

---

## 🔍 HALLAZGO CRÍTICO: Pusher ya existe pero no se usa

**`frontend/src/services/realtime.js`** ya tiene implementado **Pusher** (WebSocket) con:
- `subscribe(channelName)` 
- `unsubscribe(channelName)`
- `disconnectRealtime()`

Pero **el Dashboard actual usa polling HTTP cada 3 segundos** en lugar de Pusher.

**Canales definidos en realtime.js:**
- `trips.{client_id}` → cola de viajes
- `orders.{client_id}` → cambios de estado
- `driver.{driver_id}` → eventos individuales
- `admin.{client_id}` → notificaciones admin

**Recomendación:** En la FASE 3, reemplazar el polling por suscripción a Pusher. Esto:
- Elimina ~50 líneas de polling
- Reduce peticiones HTTP de 2 cada 3s a 0
- Las notificaciones toast serían inmediatas vs cada 3s
- El mapa se actualizaría en tiempo real

---

## 📊 RESUMEN DEL PLAN

```
DashboardView.vue (1358 líneas)
│
├── 🟢 FASE 1 → Componentes pequeños + useToast
│   ├── components/ToastContainer.vue
│   ├── components/OrderCard.vue
│   ├── components/DriverCard.vue
│   └── composables/useToast.js
│
├── 🟡 FASE 2 → Composición de datos
│   ├── composables/useOrders.js
│   ├── composables/useDrivers.js
│   ├── composables/useStats.js
│   └── components/OrderDetailPanel.vue
│
├── 🔴 FASE 3 → Mapa + Tiempo real
│   ├── composables/useDashboardMap.js
│   ├── composables/useRealtimeSync.js (Pusher en lugar de polling)
│   ├── components/MapControls.vue
│   └── components/MapCommandBar.vue
│
├── 🟣 FASE 4 → Componentes contenedores
│   ├── components/OrdersSidebar.vue
│   ├── components/FleetSidebar.vue
│   └── components/StatsGrid.vue
│
└── 🎯 RESULTADO: DashboardView.vue ~180 líneas (orquestador)
```

**Total estimado: 7-11 horas de trabajo progresivo, sin romper producción.**
