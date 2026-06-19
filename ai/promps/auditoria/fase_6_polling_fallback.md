# SPEC — Fase 6: Convertir Polling en Fallback Real Basado en Estado de Conexión Pusher

**Origen**: Auditoría técnica 2026-06-18  
**Alcance**: `useRealtimeSync.js` + `realtime.js` (exponer estado de conexión).  
**Riesgo**: Bajo. El polling existente sigue siendo el mecanismo de recuperación;
solo se añade inteligencia para que no corra cuando Pusher está conectado.  
**Tiempo estimado**: 2-3 horas incluyendo prueba de desconexión manual.

---

## Contexto

### Estado actual

**`frontend/src/composables/useRealtimeSync.js` líneas 5 y 67–72:**

```javascript
const POLLING_INTERVAL_MS = 30_000

// Polling como fallback de recuperación — Pusher es la fuente primaria
refreshInterval.value = setInterval(() => {
  if (role.value === 'client_admin') {
    silentUpdate()
  }
}, POLLING_INTERVAL_MS)
```

El `setInterval` de 30 segundos corre **siempre**, independientemente de si Pusher
está conectado o no. Con Pusher activo y funcionando correctamente, el polling genera:

- 2 requests HTTP cada 30 segundos: `GET /orders` + `GET /drivers`
- 120 requests/hora por sesión de panel abierta
- Carga innecesaria en el servidor durante horas de baja actividad

El comentario en el código dice "Pusher es la fuente primaria" — la intención es
correcta. La implementación no lo es, porque el polling no comprueba si Pusher está
funcionando.

### Lo que Pusher JS expone

El SDK de Pusher para el navegador publica eventos de estado de conexión en tiempo real:

```javascript
pusher.connection.bind('connected',     () => { /* Pusher OK */ })
pusher.connection.bind('disconnected',  () => { /* sin conexión */ })
pusher.connection.bind('unavailable',   () => { /* error de red o auth */ })
pusher.connection.bind('failed',        () => { /* protocolo no soportado */ })
```

El estado actual se puede leer en cualquier momento con:

```javascript
pusher.connection.state  // 'connected' | 'disconnected' | 'unavailable' | ...
```

---

## Tarea 6.1 — Exponer estado de conexión desde realtime.js

**Archivo**: `frontend/src/services/realtime.js`

Añadir dos funciones exportadas: una para consultar el estado actual y otra para
suscribirse a cambios de estado:

```javascript
import Pusher from 'pusher-js'

let instance = null

function getInstance() {
    if (!instance) {
        instance = new Pusher(import.meta.env.VITE_PUSHER_APP_KEY, {
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
        })
    }
    return instance
}

export function subscribe(channelName) {
    return getInstance().subscribe(channelName)
}

export function unsubscribe(channelName) {
    if (instance) {
        instance.unsubscribe(channelName)
    }
}

export function disconnectRealtime() {
    if (instance) {
        instance.disconnect()
        instance = null
    }
}

/**
 * Devuelve true si la conexión WebSocket con Pusher está activa.
 * Usar para decidir si el polling de fallback debe correr.
 */
export function isRealtimeConnected() {
    if (!instance) return false
    return instance.connection.state === 'connected'
}

/**
 * Registra un callback que se llama cada vez que cambia el estado de conexión.
 * Retorna una función para cancelar la suscripción.
 *
 * @param {(state: string) => void} callback
 * @returns {() => void} unsubscribe function
 */
export function onConnectionStateChange(callback) {
    const pusher = getInstance()
    const handler = (states) => callback(states.current)
    pusher.connection.bind('state_change', handler)
    return () => pusher.connection.unbind('state_change', handler)
}
```

---

## Tarea 6.2 — Activar y desactivar polling según estado Pusher

**Archivo**: `frontend/src/composables/useRealtimeSync.js`

Reemplazar el `setInterval` fijo con lógica que arranca el polling solo cuando Pusher
no está conectado y lo detiene cuando Pusher reconecta:

```javascript
import { ref, onUnmounted } from 'vue'
import api from '../api'
import { subscribe, unsubscribe, isRealtimeConnected, onConnectionStateChange } from '../services/realtime'

const POLLING_INTERVAL_MS = 30_000

export function useRealtimeSync() {
  const refreshInterval = ref(null)

  const startPolling = ({ role, orders, drivers, stats, showToast, updateMapMarkers }) => {
    const silentUpdate = async () => {
      try {
        const ordersRes = await api.get('/orders')
        if (ordersRes.data.status) {
          const newOrders = ordersRes.data.data
          newOrders.forEach(newOrder => {
            const old = orders.value.find(o => o.id === newOrder.id)
            if (old && old.status !== newOrder.status) {
              showToast(`📦 Pedido #${newOrder.id} cambió a: ${newOrder.status}`, 'info')
            }
          })
          orders.value = newOrders
          stats.value.activeOrders = orders.value.filter(o =>
            ['publicado', 'tomado', 'arribado', 'en_camino'].includes(o.status)
          ).length
        }
      } catch (error) {
        console.error('Error en silent update (orders):', error)
      }

      try {
        const driversRes = await api.get('/drivers')
        if (driversRes.data.status) {
          const freshDrivers = driversRes.data.data
          freshDrivers.forEach(fresh => {
            const idx = drivers.value.findIndex(d => d.id === fresh.id)
            if (idx !== -1) {
              const existing = drivers.value[idx]
              const pusherIsRecent = existing._pusherTs &&
                (Date.now() - existing._pusherTs) < 10_000
              drivers.value[idx] = {
                ...fresh,
                current_lat: pusherIsRecent ? existing.current_lat : fresh.current_lat,
                current_lng: pusherIsRecent ? existing.current_lng : fresh.current_lng,
                _pusherTs: existing._pusherTs ?? null,
              }
            } else {
              drivers.value.push(fresh)
            }
          })
          drivers.value = drivers.value.filter(d => freshDrivers.some(f => f.id === d.id))
        }
      } catch (error) {
        console.error('Error en silent update (drivers):', error)
      }

      if (updateMapMarkers) updateMapMarkers()
    }

    const startInterval = () => {
      if (refreshInterval.value) return  // ya está corriendo
      refreshInterval.value = setInterval(() => {
        if (role.value === 'client_admin') {
          silentUpdate()
        }
      }, POLLING_INTERVAL_MS)
    }

    const stopInterval = () => {
      if (refreshInterval.value) {
        clearInterval(refreshInterval.value)
        refreshInterval.value = null
      }
    }

    // Si Pusher no está conectado al montar, arrancar polling inmediatamente
    if (!isRealtimeConnected()) {
      startInterval()
    }

    // Reaccionar a cambios de estado de conexión de Pusher
    const unbindConnectionState = onConnectionStateChange((state) => {
      if (state === 'connected') {
        // Pusher conectó (o reconectó) — el polling ya no es necesario
        stopInterval()
      } else if (state === 'disconnected' || state === 'unavailable') {
        // Pusher perdió conexión — activar polling como fallback
        startInterval()
      }
    })

    onUnmounted(() => {
      stopInterval()
      unbindConnectionState()
    })

    return { silentUpdate }
  }

  const stopPolling = () => {
    if (refreshInterval.value) {
      clearInterval(refreshInterval.value)
      refreshInterval.value = null
    }
  }

  const setupRealtimeListeners = ({ clientId, onOrderCancelled, onNewTrip, onTripTaken, onTripUpdated, onDriverLocation }) => {
    if (!clientId) return

    const channel = subscribe(`orders.${clientId}`)
    channel.bind('order-cancelled', (data) => {
      if (onOrderCancelled) onOrderCancelled(data)
    })

    const tripsChannel = subscribe(`trips.${clientId}`)
    tripsChannel.bind('new-trip', (data) => {
      if (onNewTrip) onNewTrip(data)
    })
    tripsChannel.bind('trip-taken', (data) => {
      if (onTripTaken) onTripTaken(data)
    })
    tripsChannel.bind('trip-updated', (data) => {
      if (onTripUpdated) onTripUpdated(data)
    })
    if (onDriverLocation) {
      tripsChannel.bind('driver-location', (data) => {
        onDriverLocation(data)
      })
    }

    onUnmounted(() => {
      unsubscribe(`orders.${clientId}`)
      unsubscribe(`trips.${clientId}`)
    })

    return { channel, tripsChannel }
  }

  return {
    refreshInterval,
    startPolling,
    stopPolling,
    setupRealtimeListeners
  }
}
```

---

## Comportamiento resultante

| Estado de Pusher | Polling activo | Frecuencia de updates |
|---|---|---|
| `connected` | No | Instantáneo (vía Pusher) |
| `disconnected` / `unavailable` | Sí, cada 30s | Cada 30 segundos |
| Reconexión de `unavailable` → `connected` | Se detiene automáticamente | Instantáneo nuevamente |

### Diagrama de transiciones

```
[montar componente]
       │
       ├── Pusher conectado → NO polling → eventos en tiempo real
       │
       └── Pusher no conectado → polling cada 30s ──→ Pusher reconecta → detener polling
```

---

## Archivos modificados

| Archivo | Tarea | Tipo de cambio |
|---|---|---|
| `frontend/src/services/realtime.js` | 6.1 | Añadir `isRealtimeConnected()` y `onConnectionStateChange()` |
| `frontend/src/composables/useRealtimeSync.js` | 6.2 | Polling condicional basado en estado Pusher |

---

## Criterio de éxito

### Prueba — Pusher conectado (flujo normal)

1. Abrir el panel web. Abrir DevTools → Network → filtrar XHR.
2. Esperar 2 minutos.
3. Verificar que **no** aparecen llamadas a `GET /orders` ni `GET /drivers` durante
   ese período (el polling está inactivo porque Pusher está conectado).
4. En la consola del navegador ejecutar:
   ```javascript
   // Verificar que la instancia de Pusher reporta connected
   import('/src/services/realtime.js').then(m => console.log(m.isRealtimeConnected()))
   ```
   Debe imprimir `true`.

### Prueba — simular desconexión de Pusher

1. En DevTools → Network → activar "Offline" (o bloquear `ws.pusherapp.com` en el
   panel de Network).
2. Esperar unos segundos hasta que Pusher detecte la desconexión (generalmente 5-10s).
3. Verificar en Network que aparecen llamadas periódicas a `GET /orders` y `GET /drivers`
   cada 30 segundos.
4. Desactivar "Offline".
5. Verificar que Pusher reconecta y que las llamadas HTTP de polling cesan.

### Prueba de regresión

- Todos los eventos Pusher (`trip-taken`, `trip-updated`, `order-cancelled`,
  `new-trip`, `driver-location`) siguen llegando y actualizando el UI cuando Pusher
  está conectado.
- El comportamiento del mapa y los marcadores no cambia.
- El toast de detección de cambios sigue apareciendo durante el polling de fallback.
