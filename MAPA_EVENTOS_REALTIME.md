# MAPA DE EVENTOS REALTIME
**Fecha:** 2026-06-03 | **Proyecto:** Sello Pronto Delivery
**Alcance:** Solo auditoría. Cero modificaciones de código.

---

## INVENTARIO BACKEND — Todos los `PusherService::trigger()`

### EMISOR 1
- **Archivo:** `app/Services/OrderService.php:213`
- **Método:** `createOrder()`
- **Condición:** Solo si `$initialStatus === 'publicado'` (órdenes inmediatas, no programadas)
- **Canal:** `trips.{client_id}`
- **Evento:** `new-trip`
- **Payload:** `['trip_id' => (int) $orderId]`

### EMISOR 2
- **Archivo:** `app/Services/OrderService.php:361`
- **Método:** `publishDueOrders()`
- **Condición:** Cada vez que una orden programada (`pendiente`) alcanza su `scheduled_at`
- **Canal:** `trips.{client_id}`
- **Evento:** `new-trip`
- **Payload:** `['trip_id' => (int) $order['id']]`

### EMISOR 3
- **Archivo:** `app/Controllers/Api/V1/OrderController.php:142`
- **Método:** `cancel()`
- **Condición:** Solo si la orden tiene `driver_id` asignado
- **Canal:** `driver.{assignedDriverId}`
- **Evento:** `order-cancelled`
- **Payload:** `['order_id' => (int) $id]`

### EMISOR 4
- **Archivo:** `app/Controllers/Api/V1/OrderController.php:150`
- **Método:** `cancel()`
- **Condición:** Siempre que la cancelación sea exitosa (independiente de si hay driver)
- **Canal:** `orders.{clientId}`
- **Evento:** `order-cancelled`
- **Payload:** `['order_id' => (int) $id]`

### EMISOR 5
- **Archivo:** `app/Controllers/Api/V1/OrderController.php:210`
- **Método:** `cancelByDriver()`
- **Condición:** Siempre que el driver cancele exitosamente su viaje
- **Canal:** `trips.{client_id}`
- **Evento:** `new-trip`
- **Payload:** `['trip_id' => (int) $id]`

### EMISOR 6
- **Archivo:** `app/Controllers/Api/V1/Driver/DriverApiController.php:140`
- **Método:** `acceptTrip()`
- **Condición:** Después del commit exitoso de asignación
- **Canal:** `trips.{client_id}`
- **Evento:** `trip-taken`
- **Payload:** `['trip_id' => (int) $id, 'driver_id' => $driver['id'], 'status' => 'tomado']`

### EMISOR 7
- **Archivo:** `app/Controllers/Api/V1/Driver/DriverApiController.php:305`
- **Método:** `updateStatus()`
- **Condición:** Solo cuando `$newStatus === 'entregado'`
- **Canal:** `driver.{driver['id']}`
- **Evento:** `wallet-updated`
- **Payload:** `['earnings', 'trips', 'guarantee_balance', 'viajes_disponibles']`

---

## INVENTARIO FRONTEND — Todos los `subscribe()` y `.bind()`

### COMPONENTE: DriverAppView.vue

**Bloque de suscripción:** `onMounted` — líneas 464-534

**Canal 1:** `trips.{driverClientId}` — suscrito en línea 476

| Línea | Evento escuchado | Acción |
|---|---|---|
| 478 | `trip-taken` | Filtra `availableOrders` eliminando el `trip_id` recibido |
| 482 | `new-trip` | Llama `loadAvailableOrders()` si driver está online y sin viaje activo |

**Canal 2:** `driver.{driverId}` — suscrito en línea 489

| Línea | Evento escuchado | Acción |
|---|---|---|
| 490 | `order-cancelled` | Llama `handleOrderCancelled({ order_id })` — limpia viaje activo, muestra toast, vibración |
| 491 | `wallet-updated` | Actualiza `todayEarnings`, `todayTrips`, `guaranteeBalance`, `viajesDisponibles` |

**Cleanup en `onUnmounted` (líneas 531-532):**
```js
unsubscribe(`trips.${driverClientId.value}`)
unsubscribe(`driver.${driverId.value}`)
```

---

### COMPOSABLE: useRealtimeSync.js — `setupRealtimeListeners()`

**Estado:** DEFINIDO Y EXPORTADO — NUNCA LLAMADO

Evidencia de que existe:
```js
// useRealtimeSync.js:68
const setupRealtimeListeners = ({ clientId, onOrderUpdated, onDriverMoved }) => {
```

Evidencia de que nunca se llama — búsqueda en todo el frontend:
```
Resultado: "setupRealtimeListeners" aparece en 2 lugares:
  → useRealtimeSync.js:68  (definición)
  → useRealtimeSync.js:94  (export)
  → Ningún componente lo importa ni lo invoca
```

**Canal 1:** `orders.{clientId}` — línea 71

| Línea | Evento escuchado | Acción |
|---|---|---|
| 72 | `order-updated` | Llama `onOrderUpdated(data)` si el callback existe |

**Canal 2:** `trips.{clientId}` — línea 76

| Línea | Evento escuchado | Acción |
|---|---|---|
| 77 | `driver-moved` | Llama `onDriverMoved(data)` si el callback existe |

**Cleanup registrado en `onUnmounted` interno (líneas 82-85):**
```js
unsubscribe(`orders.${clientId}`)
unsubscribe(`trips.${clientId}`)
```

---

### VISTA: DashboardView.vue

**Resultado de búsqueda exhaustiva:** `subscribe|channel|realtime|\.bind\(|setupRealtimeListeners`

```
→ NO MATCHES FOUND
```

El Dashboard no tiene ninguna integración con Pusher. Cero suscripciones. Cero listeners.

---

### TODAS LAS DEMÁS VISTAS Y COMPONENTES

Búsqueda en `frontend/src/views/*.vue` y `frontend/src/components/**/*.vue`:

```
→ subscribe: solo en DriverAppView.vue
→ .bind(: solo en DriverAppView.vue
→ realtime: solo en DriverAppView.vue (import) y useRealtimeSync.js
```

Ninguna otra vista ni componente usa Pusher.

---

## TABLA MAESTRA DE EVENTOS

| # | Evento Backend | Canal Backend | Cuándo se emite | Listener Frontend | Componente | Coincide |
|---|---|---|---|---|---|---|
| 1 | `new-trip` | `trips.{client_id}` | Orden creada (publicado) | `new-trip` → `loadAvailableOrders()` | DriverAppView:482 | ✅ |
| 2 | `new-trip` | `trips.{client_id}` | Orden programada auto-publicada | `new-trip` → `loadAvailableOrders()` | DriverAppView:482 | ✅ |
| 3 | `new-trip` | `trips.{client_id}` | Driver cancela su viaje | `new-trip` → `loadAvailableOrders()` | DriverAppView:482 | ✅ |
| 4 | `trip-taken` | `trips.{client_id}` | Driver acepta un viaje | `trip-taken` → filtra lista | DriverAppView:478 | ✅ |
| 5 | `order-cancelled` | `driver.{driver_id}` | Admin cancela con driver asignado | `order-cancelled` → limpia viaje activo | DriverAppView:490 | ✅ |
| 6 | `wallet-updated` | `driver.{driver_id}` | Driver completa entrega | `wallet-updated` → actualiza wallet UI | DriverAppView:491 | ✅ |
| 7 | `order-cancelled` | `orders.{client_id}` | Admin cancela cualquier orden | — | **NADIE** | ❌ HUÉRFANO |
| 8 | — | `orders.{client_id}` | **NUNCA** | `order-updated` → callback | useRealtimeSync:72 | ❌ FANTASMA |
| 9 | — | `trips.{client_id}` | **NUNCA** | `driver-moved` → callback | useRealtimeSync:77 | ❌ FANTASMA |

---

## ANÁLISIS DETALLADO POR CATEGORÍA

### ✅ Eventos que coinciden completamente (6)

Todos en DriverAppView. Contrato backend↔frontend verificado por nombre de evento y canal.

| Evento | Canal | Backend → Frontend | Verificado |
|---|---|---|---|
| `new-trip` | `trips.{client_id}` | OrderService:213 → DriverAppView:482 | ✅ |
| `new-trip` | `trips.{client_id}` | OrderService:361 → DriverAppView:482 | ✅ |
| `new-trip` | `trips.{client_id}` | OrderController:210 → DriverAppView:482 | ✅ |
| `trip-taken` | `trips.{client_id}` | DriverApiController:140 → DriverAppView:478 | ✅ |
| `order-cancelled` | `driver.{driver_id}` | OrderController:142 → DriverAppView:490 | ✅ |
| `wallet-updated` | `driver.{driver_id}` | DriverApiController:305 → DriverAppView:491 | ✅ |

---

### ❌ Evento huérfano — Backend emite, nadie escucha (1)

**Evento:** `order-cancelled`
**Canal:** `orders.{client_id}`
**Backend:** `OrderController.php:150` — emitido siempre que un admin cancela una orden

```php
// OrderController.php:149-154
// Notificar al panel de órdenes del cliente
PusherService::trigger(
    'orders.' . $clientId,
    'order-cancelled',
    ['order_id' => (int) $id]
);
```

**Frontend:** Ningún componente suscribe el canal `orders.{client_id}` ni escucha `order-cancelled` en ese canal.

El evento viaja de backend a Pusher y se pierde. Nadie lo recibe.

---

### 👻 Eventos fantasma — Frontend escucha, backend nunca emite (2)

Ambos están en `useRealtimeSync.setupRealtimeListeners()` — que además nunca se llama.

**Fantasma 1:**
- **Listener:** `orders.{clientId}` → `order-updated` (`useRealtimeSync.js:72`)
- **Nombre de evento en backend que se emite en ese canal:** `order-cancelled` (distinto)
- El backend **nunca** emite `order-updated` en ningún canal.

**Fantasma 2:**
- **Listener:** `trips.{clientId}` → `driver-moved` (`useRealtimeSync.js:77`)
- **Nombres de eventos que el backend emite en ese canal:** `new-trip`, `trip-taken` (distintos)
- El backend **nunca** emite `driver-moved` en ningún canal.

---

### 🔇 Listeners nunca utilizados (función completa)

`setupRealtimeListeners` en `useRealtimeSync.js`:

- Definida en línea 68
- Exportada en línea 94
- **Importada por:** ningún archivo
- **Llamada por:** ningún archivo

Los dos listeners internos (`order-updated` y `driver-moved`) nunca se registran en ninguna sesión de usuario.

---

## MAPA DE CANALES

| Canal | Backend emite | Frontend suscribe | Estado |
|---|---|---|---|
| `trips.{client_id}` | `new-trip` (×3), `trip-taken` (×1) | DriverAppView ✅, useRealtimeSync ❌ (nunca llamado) | Activo — parcialmente escuchado |
| `driver.{driver_id}` | `order-cancelled` (×1), `wallet-updated` (×1) | DriverAppView ✅ | Activo — completamente escuchado |
| `orders.{client_id}` | `order-cancelled` (×1) | useRealtimeSync ❌ (nunca llamado) | Canal huérfano — nadie escucha |
| `admin.{client_id}` | **Nada** | Nadie | Canal documentado en comentario, nunca usado |

---

## RESUMEN EJECUTIVO

```
Backend emite 7 triggers → 4 tipos de eventos → 3 canales activos

De esos 7:
  ✅ 6 son recibidos y procesados (todos por DriverAppView)
  ❌ 1 es emitido y nadie lo recibe (orders.{client_id} → order-cancelled)

Frontend define 4 listeners:
  ✅ 2 en DriverAppView conectados y funcionando (canal trips)
  ✅ 2 en DriverAppView conectados y funcionando (canal driver)
  ❌ 2 en useRealtimeSync nunca registrados (setupRealtimeListeners jamás se llama)
  ❌ Los 2 de useRealtimeSync escuchan eventos que el backend nunca emite

DashboardView.vue: cero integración con Pusher.

Canal admin.{client_id}: documentado en PusherService.php, sin un solo trigger.
```
