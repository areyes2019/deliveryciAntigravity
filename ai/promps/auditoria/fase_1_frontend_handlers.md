# SPEC — Fase 1: Eliminar Anti-Patrones de Refetch en Handlers de Pusher

**Origen**: Auditoría técnica 2026-06-18  
**Alcance**: Un solo archivo frontend + un ajuste de payload en backend.  
**Riesgo**: Bajo — los handlers ya tienen actualización optimista local. Solo se elimina
el roundtrip HTTP redundante que ocurre después del evento.  
**Tiempo estimado**: 2-3 horas incluyendo pruebas manuales.

---

## Contexto

El sistema recibe eventos en tiempo real vía Pusher. El problema es que los handlers
de esos eventos, en lugar de aplicar los datos del propio evento, hacen requests HTTP
adicionales al backend para "confirmar" el cambio. Esto convierte un evento instantáneo
en una secuencia: evento recibido → esperar ~200-400ms → actualizar UI.

Los cuatro handlers afectados están todos en:
**`frontend/src/views/DashboardView.vue`**

---

## Tarea 1.1 — onTripTaken: eliminar refreshTripsAndDrivers

### Problema

**Líneas 398–408** de `DashboardView.vue`:

```javascript
onTripTaken: async (data) => {
  if (data?.trip_id) {
    const idx = orders.value.findIndex(o => String(o.id) === String(data.trip_id))
    if (idx !== -1) {
      orders.value[idx] = { ...orders.value[idx], status: 'tomado' }
      stats.value.activeOrders = countActive()
    }
  }
  await refreshTripsAndDrivers()   // ← dispara GET /orders + GET /drivers
  updateMapMarkers(mapCtx())
},
```

El evento `trip-taken` llega con payload `{ trip_id, driver_id, status }` — confirmado en
`DriverApiController.php` líneas 143–147. El campo `driver_id` ya viene en el evento
pero el handler lo ignora y lo busca via HTTP.

### Corrección

Aplicar `driver_id` del payload directamente. Eliminar `refreshTripsAndDrivers()`.
La función deja de ser `async`.

```javascript
onTripTaken: (data) => {
  if (data?.trip_id) {
    const idx = orders.value.findIndex(o => String(o.id) === String(data.trip_id))
    if (idx !== -1) {
      orders.value[idx] = {
        ...orders.value[idx],
        status:    'tomado',
        driver_id: data.driver_id   // ya viene en el payload de Pusher
      }
      stats.value.activeOrders = countActive()
    }
  }
  updateMapMarkers(mapCtx())
},
```

### Sin cambios en backend

El payload de `trip-taken` ya incluye `driver_id` (DriverApiController.php:145).
No se toca el backend en esta tarea.

---

## Tarea 1.2 — onTripUpdated: eliminar refreshTripsAndDrivers

### Problema

**Líneas 414–424** de `DashboardView.vue`:

```javascript
onTripUpdated: async (data) => {
  if (data?.trip_id && data?.status) {
    const idx = orders.value.findIndex(o => String(o.id) === String(data.trip_id))
    if (idx !== -1) {
      orders.value[idx] = { ...orders.value[idx], status: data.status }
      stats.value.activeOrders = countActive()
    }
  }
  await refreshTripsAndDrivers()   // ← dispara GET /orders + GET /drivers
  updateMapMarkers(mapCtx())
},
```

El evento `trip-updated` llega con `{ trip_id, driver_id, status }` — confirmado en
`DriverApiController.php` líneas 296–300. La actualización optimista de `status` en
la línea 418 ya es correcta. El `refreshTripsAndDrivers()` solo sirve para traer
`updated_at` y otros campos secundarios que el panel de dashboard no utiliza.

### Corrección

Eliminar `refreshTripsAndDrivers()`. Añadir toast opcional para visibilidad.
La función deja de ser `async`.

```javascript
onTripUpdated: (data) => {
  if (data?.trip_id && data?.status) {
    const idx = orders.value.findIndex(o => String(o.id) === String(data.trip_id))
    if (idx !== -1) {
      orders.value[idx] = { ...orders.value[idx], status: data.status }
      stats.value.activeOrders = countActive()
      showToast(`Pedido #${data.trip_id} → ${data.status}`, 'info')
    }
  }
  updateMapMarkers(mapCtx())
},
```

### Sin cambios en backend

El payload ya es suficiente. No se toca el backend.

---

## Tarea 1.3 — onOrderCancelled: reemplazar fetchDashboardData con mutación local

### Problema

**Líneas 429–433** de `DashboardView.vue`:

```javascript
onOrderCancelled: async ({ order_id }) => {
  if (selectedOrder.value?.id === order_id) clearSelection()
  await fetchDashboardData()   // ← recarga COMPLETA: orders + drivers + geofences + auth/me
  updateMapMarkers(mapCtx())
},
```

Una cancelación de pedido provoca la recarga total del dashboard. Las geofences y los
conductores no cambian cuando se cancela un pedido. Solo cambian: la lista de órdenes
(se elimina una) y el saldo del cliente (se reintegra el crédito). El backend puede
incluir el nuevo saldo directamente en el payload del evento, eliminando la necesidad
de cualquier request.

### Cambio en backend — OrderController.php

**Archivo**: `app/Controllers/Api/V1/OrderController.php`  
**Líneas 159–164** (el trigger de Pusher para `orders.{clientId}`):

```php
// ANTES
PusherService::trigger(
    'orders.' . $clientId,
    'order-cancelled',
    ['order_id' => (int) $id]
);
```

```php
// DESPUÉS
$freshClient = $clientModel->find($clientId);
PusherService::trigger(
    'orders.' . $clientId,
    'order-cancelled',
    [
        'order_id'    => (int) $id,
        'new_balance' => (float) ($freshClient['credits_balance'] ?? 0),
    ]
);
```

> `$clientModel` ya está instanciado en el método `cancel()` (línea 133). El
> `find($clientId)` es una query por PK — costo mínimo.

### Cambio en frontend — DashboardView.vue

```javascript
onOrderCancelled: ({ order_id, new_balance }) => {
  if (selectedOrder.value?.id === order_id) clearSelection()

  // Eliminar la orden cancelada del array local
  orders.value = orders.value.filter(o => String(o.id) !== String(order_id))
  stats.value.activeOrders = countActive()

  // Actualizar el saldo directamente desde el payload (sin /auth/me)
  if (new_balance !== undefined) {
    stats.value.balance = new_balance
  }

  updateMapMarkers(mapCtx())
},
```

La función deja de ser `async`. Cero requests HTTP en este handler.

---

## Tarea 1.4 — fetchDashboardData: paralelizar las 4 llamadas de client_admin

### Problema

**Líneas 246–285** de `DashboardView.vue`. Flujo actual para `client_admin`:

```
await GET /orders          ← esperar respuesta
  await GET /drivers  ┐
  await GET /geofences┘  ← paralelo entre sí, pero bloqueado por /orders
    await GET /auth/me    ← esperar los dos anteriores
```

Las cuatro llamadas son completamente independientes entre sí. El resultado de
`/orders` no se necesita para hacer `/drivers` ni `/auth/me`. El bloqueo secuencial
es innecesario y suma ~2 RTTs al tiempo de carga.

### Corrección — bloque client_admin

Reemplazar las líneas 259–281 con un `Promise.all` de las cuatro llamadas:

```javascript
} else if (role.value === 'client_admin') {
  const [ordersRes, driversRes, geofencesRes, meRes] = await Promise.all([
    api.get('/orders'),
    api.get('/drivers'),
    api.get('/geofences'),
    api.get('/auth/me'),
  ])

  if (ordersRes.data.status) {
    orders.value = ordersRes.data.data
    stats.value.activeOrders = countActive()
  }

  if (driversRes.data.status) {
    drivers.value = driversRes.data.data
    stats.value.totalDrivers = drivers.value.length
    stats.value.fleetBalance = drivers.value.reduce((a, d) => a + (parseFloat(d.balance) || 0), 0)
  }

  clientZones.value = geofencesRes.data?.data ?? []
  stats.value.balance = parseFloat(meRes.data.data.client_balance) || 0

  if (viewMode.value === 'map' && !showFeed.value) {
    await nextTick()
    setTimeout(() => initDashboardMap({
      orders:          orders.value,
      drivers:         drivers.value,
      isDriverEnRoute: d => isDriverEnRoute(d, orders.value)
    }), 800)
  }
}
```

### Corrección — bloque superadmin

Las líneas 253–257 también son secuenciales sin necesidad:

```javascript
if (role.value === 'superadmin') {
  const [ordersRes, clientsRes] = await Promise.all([
    api.get('/orders'),
    api.get('/clients'),
  ])

  if (ordersRes.data.status) {
    orders.value = ordersRes.data.data
    stats.value.activeOrders = countActive()
  }

  if (clientsRes.data.status) {
    stats.value.totalClients = clientsRes.data.data.length
    stats.value.balance = clientsRes.data.data.reduce(
      (a, c) => a + (parseFloat(c.credits_balance) || 0), 0
    )
  }
}
```

> Con este cambio, `fetchDashboardData` ya no necesita hacer `await api.get('/orders')`
> en la línea 250 antes de bifurcarse por rol — la llamada a `/orders` pasa a estar
> dentro de cada rama. Eliminar las líneas 250–251 del bloque actual.

---

## refreshTripsAndDrivers — estado después de la Fase 1

Después de completar las tareas 1.1 y 1.2, la función `refreshTripsAndDrivers`
(líneas 212–244) ya no es llamada desde ningún handler de Pusher.

Solo se llama desde `onTripTaken` y `onTripUpdated`, que se corrigen en esta fase.

**Verificar** que no hay otras referencias antes de eliminarla:

```bash
grep -n "refreshTripsAndDrivers" frontend/src/views/DashboardView.vue
```

Si el único resultado es la definición de la función (línea 212), eliminar la función
completa (líneas 212–244). Si aparece en otro punto, dejar la función y solo eliminar
las llamadas de los handlers.

---

## Archivos modificados en esta fase

| Archivo | Tareas | Tipo de cambio |
|---|---|---|
| `frontend/src/views/DashboardView.vue` | 1.1, 1.2, 1.3, 1.4 | Editar handlers y fetchDashboardData |
| `app/Controllers/Api/V1/OrderController.php` | 1.3 | Añadir `new_balance` al payload de `order-cancelled` |

---

## Criterio de éxito

### Prueba manual — onTripTaken (1.1)

1. Abrir el panel web con un pedido en estado `publicado`.
2. Desde la app de conductor, aceptar el pedido.
3. En las DevTools del panel → pestaña Network: verificar que **no** aparece
   una llamada a `GET /orders` ni `GET /drivers` inmediatamente después del evento.
4. El pedido en el sidebar debe cambiar a `tomado` con el conductor asignado
   visible en menos de 200ms desde que el conductor presionó aceptar.

### Prueba manual — onTripUpdated (1.2)

1. Con un pedido en estado `tomado`, el conductor actualiza el estado a `en_camino`.
2. En Network: verificar que **no** aparece `GET /orders` ni `GET /drivers`.
3. La tarjeta del pedido en el sidebar cambia de estado instantáneamente.
4. El toast de estado aparece.

### Prueba manual — onOrderCancelled (1.3)

1. Con un pedido activo, cancelarlo desde el panel.
2. En Network: verificar que la cancelación genera una sola llamada `PUT /orders/:id/cancel`
   y ningún `GET` posterior.
3. El saldo en el chip `Saldo $X` se actualiza inmediatamente con el nuevo valor.
4. La orden desaparece del sidebar.
5. Si el pedido estaba seleccionado en el panel de detalle, el panel se cierra.

### Prueba manual — fetchDashboardData (1.4)

1. Abrir DevTools → Network antes de cargar el dashboard.
2. Navegar a la vista del dashboard (o hacer F5).
3. Verificar en la cascada de requests que las 4 llamadas
   (`/orders`, `/drivers`, `/geofences`, `/auth/me`) aparecen con timestamps
   de inicio simultáneos (misma columna de inicio en la cascada).
4. El tiempo total de carga debe ser aproximadamente el del request más lento
   de los cuatro, no la suma de todos.

### Prueba de regresión

- El polling de 30 segundos de `useRealtimeSync.js` sigue funcionando como fallback.
- `onDriverLocation` (líneas 440–449) no fue tocado y sigue funcionando.
- `onNewTrip` (líneas 380–392) no fue tocado — su lógica de fallback (refetch si la
  orden no existe localmente) es correcta y debe conservarse.
- El mapa sigue actualizando marcadores correctamente después de cada evento.
