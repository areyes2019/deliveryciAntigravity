# AUDITORÍA PUSHER & REALTIME
**Fecha:** 2026-06-03 | **Proyecto:** Sello Pronto Delivery

---

## METODOLOGÍA

Todas las conclusiones están basadas en código encontrado.
Cuando no existe evidencia suficiente, se indica explícitamente.
No se asume que Pusher funciona — se demuestra o se contradice.

---

## 1. ¿EXISTE INTEGRACIÓN DE PUSHER?

### Backend

| Elemento | Estado | Evidencia |
|---|---|---|
| PHP SDK instalado | ✅ Instalado | `vendor/pusher/pusher-php-server/` existe en el proyecto |
| Credenciales configuradas | ✅ Completas | `.env:84-87` — APP_ID, APP_KEY, APP_SECRET, CLUSTER |
| Servicio PHP | ✅ Implementado | `app/Services/PusherService.php` — Singleton con manejo de errores |

**PusherService.php — características clave:**

```php
// PusherService.php:46-53
public static function trigger(string $channel, string $event, array $data): void
{
    try {
        self::client()->trigger($channel, $event, $data);
    } catch (\Throwable $e) {
        // NO FATAL — solo log, nunca interrumpe el flujo de negocio
        log_message('error', '[PusherService] trigger failed ...');
    }
}
```

Los errores de Pusher son **no-fatales por diseño**. Si Pusher falla, el sistema continúa. El polling es la red de seguridad.

### Frontend

| Elemento | Estado | Evidencia |
|---|---|---|
| `pusher-js` instalado | ✅ Instalado | `frontend/package.json:17` — `"pusher-js": "^8.5.0"` |
| Credenciales configuradas | ✅ Completas | `frontend/.env:3-4` — APP_KEY y CLUSTER |
| Servicio JS | ✅ Implementado | `frontend/src/services/realtime.js` — Singleton Pusher |

**Conclusión:** La infraestructura Pusher existe y está configurada en ambos lados. APP_KEY `92c7d5b9e647f1b1af63` y CLUSTER `us2` coinciden entre backend `.env` y `frontend/.env`.

---

## 2. ¿EL FRONTEND REALMENTE LO UTILIZA?

### DriverAppView.vue — PLENAMENTE INTEGRADO

```js
// DriverAppView.vue:476-496
const fleetChannel = subscribe(`trips.${driverClientId.value}`)
fleetChannel.bind('trip-taken', ({ trip_id }) => {
    availableOrders.value = availableOrders.value.filter(o => o.id !== trip_id)
})
fleetChannel.bind('new-trip', () => {
    if (isDriverOnline.value && !activeOrder.value) loadAvailableOrders()
})

const driverChannel = subscribe(`driver.${driverId.value}`)
driverChannel.bind('order-cancelled', handleOrderCancelled)
driverChannel.bind('wallet-updated', (data) => {
    todayEarnings.value     = parseFloat(data.earnings) || 0
    todayTrips.value        = parseInt(data.trips) || 0
    guaranteeBalance.value  = parseFloat(data.guarantee_balance) || 0
    viajesDisponibles.value = data.viajes_disponibles ?? null
})
```

El propio código del driver documenta el modelo de diseño:

```js
// DriverAppView.vue:510 — COMENTARIO EN CÓDIGO
// Pusher maneja los cambios instantáneos; el polling es solo red de seguridad (fallback).
pollInterval = setInterval(() => {
    if (!activeOrder.value && !isAccepting.value) loadAvailableOrders()
}, 8000)  // ← 8 segundos, NO 3
```

### DashboardView.vue — CERO INTEGRACIÓN PUSHER

```
Resultado de búsqueda: "subscribe|channel|Pusher" en DashboardView.vue
→ NO MATCHES FOUND
```

El Dashboard usa **únicamente polling** a través de `useRealtimeSync.startPolling()`:

```js
// useRealtimeSync.js:44-48
refreshInterval.value = setInterval(() => {
    if (role.value === 'client_admin') {
        silentUpdate()   // ← GET /orders + GET /drivers cada 3 segundos
    }
}, 3000)
```

### useRealtimeSync.js — FUNCIÓN LISTA, NUNCA LLAMADA

El composable define `setupRealtimeListeners()` correctamente:

```js
// useRealtimeSync.js:68-87
const setupRealtimeListeners = ({ clientId, onOrderUpdated, onDriverMoved }) => {
    const channel = subscribe(`orders.${clientId}`)
    channel.bind('order-updated', onOrderUpdated)     // ← espera evento 'order-updated'

    const driverChannel = subscribe(`trips.${clientId}`)
    driverChannel.bind('driver-moved', onDriverMoved)  // ← espera evento 'driver-moved'
    ...
}
```

Pero `DashboardView.vue` **NUNCA llama `setupRealtimeListeners`**. Solo importa y usa `startPolling` y `stopPolling`.

**Resumen:**

| Componente | Usa Pusher | Usa Polling |
|---|---|---|
| DriverAppView.vue | ✅ Sí (4 eventos, 2 canales) | ✅ Sí (fallback 8s) |
| DashboardView.vue | ❌ No | ✅ Sí (principal 3s) |

---

## 3. ¿EL BACKEND REALMENTE EMITE EVENTOS?

Sí. Se encontraron **7 llamadas a `PusherService::trigger()`** en 3 archivos distintos.

### Mapa completo de triggers backend

| Archivo | Línea | Canal | Evento | Cuándo |
|---|---|---|---|---|
| `OrderService.php` | 213 | `trips.{client_id}` | `new-trip` | Orden creada con estado `publicado` |
| `OrderService.php` | 361 | `trips.{client_id}` | `new-trip` | Orden programada publicada automáticamente |
| `OrderController.php` | 142 | `driver.{driver_id}` | `order-cancelled` | Admin cancela orden con driver asignado |
| `OrderController.php` | 150 | `orders.{client_id}` | `order-cancelled` | Admin cancela cualquier orden |
| `OrderController.php` | 210 | `trips.{client_id}` | `new-trip` | Driver cancela → orden vuelve a `publicado` |
| `DriverApiController.php` | 140 | `trips.{client_id}` | `trip-taken` | Driver acepta un viaje |
| `DriverApiController.php` | 305 | `driver.{driver_id}` | `wallet-updated` | Driver completa entrega |

---

## 4. CANALES EXISTENTES

| Canal | Convención | Propósito |
|---|---|---|
| `trips.{client_id}` | Compartido por toda la flota de un cliente | Cola de viajes disponibles, cambios de asignación |
| `orders.{client_id}` | Panel administrativo del cliente | Cancelaciones desde el panel |
| `driver.{driver_id}` | Canal personal por conductor | Cancelaciones individuales, wallet updates |
| `admin.{client_id}` | Documentado en PusherService.php | **NO SE ENCONTRÓ trigger activo a este canal** |

**Nota sobre `admin.{client_id}`:** El canal está documentado en el comentario de `PusherService.php:16` pero ningún código emite eventos hacia él. Es una convención reservada sin implementación.

---

## 5. EVENTOS EXISTENTES

### Eventos con trigger en backend Y listener en frontend

| Evento | Canal | Backend emite | Frontend escucha | Quién |
|---|---|---|---|---|
| `new-trip` | `trips.{client_id}` | ✅ 3 lugares | ✅ Implementado | DriverAppView |
| `trip-taken` | `trips.{client_id}` | ✅ 1 lugar | ✅ Implementado | DriverAppView |
| `order-cancelled` | `driver.{driver_id}` | ✅ 1 lugar | ✅ Implementado | DriverAppView |
| `wallet-updated` | `driver.{driver_id}` | ✅ 1 lugar | ✅ Implementado | DriverAppView |

### Eventos con trigger en backend SIN listener en frontend

| Evento | Canal | Backend emite | Frontend escucha | Impacto |
|---|---|---|---|---|
| `order-cancelled` | `orders.{client_id}` | ✅ OrderController:150 | ❌ Nadie | El panel no reacciona en tiempo real a cancelaciones |

### Eventos que useRealtimeSync espera pero el backend NUNCA emite

| Evento esperado | Canal | Código que lo espera | ¿Backend lo emite? |
|---|---|---|---|
| `order-updated` | `orders.{client_id}` | `useRealtimeSync.js:73` | ❌ NO |
| `driver-moved` | `trips.{client_id}` | `useRealtimeSync.js:79` | ❌ NO |

**Este es el desacuerdo de contrato más importante del sistema:**

```
useRealtimeSync espera:          Backend emite en ese canal:
orders.{clientId} → order-updated    orders.{clientId} → order-cancelled  ← nombres distintos
trips.{clientId}  → driver-moved     trips.{clientId}  → new-trip, trip-taken ← eventos distintos
```

Incluso si DashboardView llamara a `setupRealtimeListeners()`, los listeners no recibirían nada porque los nombres de eventos no coinciden.

### Eventos que el Dashboard necesitaría pero NO existen en el backend

| Funcionalidad | Estado actual | Evento faltante |
|---|---|---|
| Actualización de posición de conductores | Solo escribe en DB | **NO HAY TRIGGER** — `updateLocation()` no emite Pusher |
| Cambio de estado de orden (tomado, arribado, en_camino) | Solo polling lo detecta | **NO HAY TRIGGER para el dashboard** |
| Nuevo balance/saldo del cliente | Solo polling | **NO HAY TRIGGER** |

---

## 6. QUÉ POLLING PODRÍA REEMPLAZARSE POR EVENTOS

### 6.1 — Nueva orden disponible (publicado)

**Situación actual:** El polling de 3s detecta que una orden nueva apareció.

**Evidencia de que el evento ya existe:**
```php
// OrderService.php:213-217
PusherService::trigger('trips.' . $clientId, 'new-trip', ['trip_id' => (int) $orderId]);
```

**Lo que falta:** DashboardView suscribirse al canal `trips.{client_id}` y reaccionar al evento `new-trip` actualizando la lista de órdenes.

**Beneficio:** Latencia 0ms vs 0-3000ms. El operador ve el pedido nuevo instantáneamente.

---

### 6.2 — Driver tomó un viaje

**Situación actual:** El polling de 3s detecta que `status` cambió de `publicado` a `tomado`.

**Evidencia de que el evento ya existe:**
```php
// DriverApiController.php:140-148
PusherService::trigger('trips.' . $order['client_id'], 'trip-taken', [
    'trip_id'   => (int) $id,
    'driver_id' => $driver['id'],
    'status'    => 'tomado',
]);
```

**Lo que falta:** DashboardView suscribirse y reaccionar con un refresh selectivo de esa orden.

**Beneficio:** El operador ve la asignación del conductor en tiempo real.

---

### 6.3 — Orden cancelada (desde el panel del cliente)

**Situación actual:** El polling de 3s detecta que `status` cambió a `cancelado`. El evento ya se emite pero nadie lo escucha.

**Evidencia:**
```php
// OrderController.php:149-153
PusherService::trigger('orders.' . $clientId, 'order-cancelled', ['order_id' => (int) $id]);
```

**Lo que falta:** DashboardView suscribirse al canal `orders.{clientId}` y manejar `order-cancelled` limpiando el marcador del mapa y actualizando la lista.

---

## 7. QUÉ POLLING NO DEBERÍA REEMPLAZARSE

### 7.1 — Posición de conductores en el mapa

**Razón:** El endpoint `POST /driver/location` guarda en base de datos y **NO emite ningún evento Pusher**:

```php
// DriverApiController.php:322-343
public function updateLocation()
{
    $this->driverModel->update($driver['id'], [
        'current_lat' => $input['lat'],
        'current_lng' => $input['lng']
    ]);
    return $this->respondSuccess('Location updated successfully.');
    // ← SIN PusherService::trigger()
}
```

Para reemplazar el polling de posición por Pusher se necesitaría agregar un trigger en `updateLocation()`. Eso requiere diseño cuidadoso (rate limiting — el driver puede enviar ubicación cada 2 segundos, lo que generaría 30+ eventos Pusher por minuto por conductor).

**Recomendación:** Mantener polling para posición. Es el caso correcto para polling dado el volumen de actualizaciones.

---

### 7.2 — Balance del cliente y stats del superadmin

**Razón:** No existen eventos para cambios de balance de cliente ni de estadísticas globales. Implementar Pusher para estos requiere nuevo trabajo en backend.

**Recomendación:** Mantener polling. Son datos de baja criticidad.

---

### 7.3 — Polling de 3s como fallback general

**Razón:** El propio comentario en DriverAppView confirma el modelo de diseño correcto:

```js
// DriverAppView.vue:510
// Pusher maneja los cambios instantáneos; el polling es solo red de seguridad (fallback).
```

Incluso con Pusher activo, el polling debe existir como fallback ante desconexiones de red, expiración de suscripción, o fallas del servicio Pusher. La frecuencia puede reducirse de 3s a 15-30s si Pusher está activo.

---

## 8. BENEFICIOS REALES DE MIGRAR EL DASHBOARD A PUSHER

### Beneficios cuantificables

| Métrica | Con polling 3s | Con Pusher + polling 30s fallback |
|---|---|---|
| Requests HTTP/min por sesión | ~40 (20 /orders + 20 /drivers) | ~2-4 (solo fallback) |
| Latencia de notificación de nueva orden | 0-3000ms | ~100-500ms (WebSocket) |
| Latencia de asignación de driver | 0-3000ms | ~100-500ms |
| Carga en servidor PHP/MySQL en hora pico | Alta (consultas constantes) | Significativamente menor |

### Beneficios cualitativos

- El operador ve el mapa de cambios de estado sin delay visible
- El toast "Pedido #X cambió a tomado" aparece en tiempo real, no hasta 3 segundos después
- Menos requests mejora el rendimiento general del servidor con múltiples sesiones activas

---

## 9. RIESGOS

### 🔴 ALTO

**R1 — Desacuerdo de nombres de eventos entre `setupRealtimeListeners` y backend**

El código listo del dashboard espera eventos con nombres incorrectos:

```
useRealtimeSync espera:     Backend emite:
'order-updated'           → 'order-cancelled'  (en orders.{client_id})
'driver-moved'            → 'new-trip'/'trip-taken'  (en trips.{client_id})
```

Si se activa `setupRealtimeListeners` tal como está, los listeners nunca recibirán datos.
**Requiere corrección de nombres antes de activar.**

---

**R2 — `order-cancelled` en `orders.{client_id}` tiene payload mínimo**

```php
// OrderController.php:150-153
PusherService::trigger('orders.' . $clientId, 'order-cancelled', ['order_id' => (int) $id]);
```

El payload solo incluye `order_id`. El dashboard necesita el objeto orden completo para actualizar el estado del mapa. Hay dos opciones:
- a) El frontend hace un fetch individual al recibir el evento
- b) El backend enriquece el payload

La opción (a) es más segura y ya es el patrón en DriverAppView (`loadAvailableOrders()` al recibir `new-trip`).

---

### 🟡 MEDIO

**R3 — Pusher tiene límite de conexiones concurrentes según plan**

Con un plan gratuito de Pusher el límite es 100 conexiones concurrentes. Con múltiples drivers + operadores, puede alcanzarse. **NO SE ENCONTRÓ EVIDENCIA** de qué plan está contratado.

**R4 — Sin Pusher funcional, el dashboard sigue operando** (por diseño), pero si se migra y Pusher falla, el intervalo de fallback de 3s debe mantenerse. Si el desarrollador reduce el polling asumiendo que Pusher siempre funciona, hay riesgo.

---

### 🟢 BAJO

**R5 — El canal `trips.{client_id}` es compartido por drivers y el panel**

Múltiples tipos de consumidores escuchan el mismo canal. Un cambio en el formato del evento afectaría a todos.

**Mitigación:** Los eventos son ligeros (solo IDs), así que los consumidores hacen fetch de los datos completos. El contrato es mínimo y estable.

---

## 10. PLAN DE MIGRACIÓN GRADUAL

> **Principio:** El polling actual es el fallback. Pusher es la mejora. Nada se rompe si Pusher falla.

---

### PASO 1 — Corregir los nombres de eventos en useRealtimeSync (30 minutos)

**Problema:** `setupRealtimeListeners` espera eventos que el backend no emite.

**Fix:**
```js
// useRealtimeSync.js — ANTES
channel.bind('order-updated', onOrderUpdated)       // ← backend emite 'order-cancelled'
driverChannel.bind('driver-moved', onDriverMoved)   // ← backend emite 'new-trip', 'trip-taken'

// useRealtimeSync.js — DESPUÉS
channel.bind('order-cancelled', ({ order_id }) => {
    if (onOrderUpdated) onOrderUpdated(order_id)
})
driverChannel.bind('new-trip', ({ trip_id }) => {
    if (onNewTrip) onNewTrip(trip_id)
})
driverChannel.bind('trip-taken', ({ trip_id, driver_id }) => {
    if (onTripTaken) onTripTaken(trip_id, driver_id)
})
```

**Validación:** Los handlers son callbacks, no ejecutan nada hasta que DashboardView los conecte.

---

### PASO 2 — Activar `setupRealtimeListeners` en DashboardView (1-2 horas)

**Añadir en DashboardView.vue:**

```js
// En onMounted, después de fetchDashboardData()
const clientId = authStore.user?.client_id
if (clientId && role.value === 'client_admin') {
    setupRealtimeListeners({
        clientId,
        onNewTrip: async () => {
            await fetchDashboardData()
            showToast('Nuevo pedido disponible', 'info')
        },
        onTripTaken: async (tripId) => {
            await fetchDashboardData()
            updateMapMarkers(mapCtx())
        },
        onOrderCancelled: async (orderId) => {
            if (selectedOrder.value?.id === orderId) clearSelection()
            await fetchDashboardData()
            updateMapMarkers(mapCtx())
        }
    })
}
```

**El polling de 3s continúa activo** como fallback. Si Pusher trae el dato primero, el polling siguiente simplemente no detectará diferencias y no hará nada.

**Validación:**
- Crear un pedido desde otra sesión → DashboardView actualiza sin esperar 3s
- Driver toma un viaje → DashboardView muestra el cambio inmediatamente
- Cancelar pedido → marcador del mapa desaparece inmediatamente

---

### PASO 3 — Reducir frecuencia del polling fallback (15 minutos)

Una vez validado que Pusher entrega eventos correctamente:

```js
// useRealtimeSync.js:44 — cambiar de 3000 a 15000 (15 segundos)
refreshInterval.value = setInterval(() => {
    if (role.value === 'client_admin') silentUpdate()
}, 15000)  // 3s → 15s
```

**Validación:** En una sesión con Pusher funcionando, verificar que los datos siguen sincronizados. Simular desconexión de Pusher y confirmar que el polling de 15s resincroniza.

---

### PASO 4 — (Opcional, futuro) Agregar evento de ubicación de conductores

**Condición:** Solo si el volumen de conductores activos simultáneos no saturará el plan de Pusher.

**Backend — agregar trigger en updateLocation():**
```php
// DriverApiController.php:updateLocation()
PusherService::trigger(
    'trips.' . $driver['client_id'],
    'driver-location',
    ['driver_id' => $driver['id'], 'lat' => $input['lat'], 'lng' => $input['lng']]
);
```

**Frontend — agregar handler:**
```js
driverChannel.bind('driver-location', ({ driver_id, lat, lng }) => {
    MapService.updateMarker(`driver-${driver_id}`, [lat, lng], { ... })
})
```

**Riesgo:** Si un conductor envía ubicación cada 2s y hay 10 conductores activos → 300 eventos Pusher/minuto solo de ubicaciones. Evaluar costo/beneficio según plan contratado.

---

## TABLA RESUMEN

| Funcionalidad | Polling Actual | Puede usar Pusher | Prioridad |
|---|---|---|---|
| Nueva orden creada (publicado) | 3s | ✅ Sí — evento `new-trip` ya existe en backend | **Alta** |
| Driver toma un viaje (tomado) | 3s | ✅ Sí — evento `trip-taken` ya existe en backend | **Alta** |
| Orden cancelada desde panel | 3s | ✅ Sí — evento `order-cancelled` ya existe, nadie lo escucha | **Alta** |
| Orden cancelada por driver | 3s | ✅ Sí — emite `new-trip` de vuelta | Media |
| Actualización de posición de conductores | 3s | ⚠️ Parcial — falta trigger en `updateLocation()` | Baja (riesgo de volumen) |
| Cambios de estado intermedios (arribado, en_camino) | 3s | ❌ No — no existe trigger en backend para el panel | Media (requiere backend work) |
| Balance del cliente | 3s | ❌ No — no existe trigger | Baja |
| Stats del superadmin | 3s | ❌ No — no existe trigger | Muy baja |
| Driver — nueva orden disponible | 8s fallback | ✅ Ya migrado completamente | — (ya hecho) |
| Driver — viaje tomado por otro | 8s fallback | ✅ Ya migrado completamente | — (ya hecho) |
| Driver — orden cancelada | 8s fallback | ✅ Ya migrado completamente | — (ya hecho) |
| Driver — wallet actualizada | 30s polling de earnings | ✅ Ya migrado completamente | — (ya hecho) |

---

## CONCLUSIÓN FINAL

### ¿Vale la pena migrar DashboardView.vue a Pusher en este momento?

**Sí, y la mayor parte del trabajo ya está hecha.**

La situación real del sistema es:

1. **Backend:** Emite 7 eventos Pusher correctamente. La infraestructura es sólida.
2. **DriverAppView:** Migración completamente exitosa — 4 eventos, polling reducido a fallback de 8s.
3. **DashboardView:** Es el único componente que no usa Pusher, y es el que más se beneficiaría.

Los 3 eventos de mayor impacto para el Dashboard (`new-trip`, `trip-taken`, `order-cancelled`) **ya existen en el backend y no requieren ningún trabajo adicional en PHP**. Solo requieren:

- **30 min:** Corregir los nombres de evento en `useRealtimeSync.setupRealtimeListeners()`
- **1-2 horas:** Llamar `setupRealtimeListeners` desde `DashboardView.vue` con los handlers correctos
- **15 min:** Reducir el polling de 3s a 15s como fallback

**Total estimado: medio día de trabajo para migración segura y verificable.**

El riesgo es bajo porque el polling sigue activo como fallback durante toda la migración. Si Pusher falla, el sistema continúa funcionando exactamente igual que hoy, solo con 15 segundos de latencia en lugar de 3.

Lo que NO debe hacerse: eliminar el polling. El modelo de DriverAppView (Pusher primario + polling fallback) es el correcto y debe replicarse en DashboardView.
