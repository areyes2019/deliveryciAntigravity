import { ref, onUnmounted } from 'vue'
import api from '../api'
import { subscribe, unsubscribe, isRealtimeConnected, onConnectionStateChange } from '../services/realtime'

const POLLING_INTERVAL_MS = 30_000

export function useRealtimeSync() {
  const refreshInterval = ref(null)

  const startPolling = ({ role, orders, drivers, stats, showToast, updateMapMarkers }) => {

    // Sincronización silenciosa: reemplaza el estado local con datos frescos del servidor.
    // Solo corre cuando Pusher está desconectado — actúa como red de seguridad, no como
    // mecanismo principal. Preserva coordenadas GPS recientes de Pusher para evitar
    // que el polling sobreescriba una posición más nueva ya recibida por WebSocket.
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
              // Conservar coordenadas GPS si Pusher las actualizó en los últimos 10s
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
      if (refreshInterval.value) return
      refreshInterval.value = setInterval(() => {
        if (role.value === 'client_admin') silentUpdate()
      }, POLLING_INTERVAL_MS)
    }

    const stopInterval = () => {
      if (refreshInterval.value) {
        clearInterval(refreshInterval.value)
        refreshInterval.value = null
      }
    }

    // Al montar: si Pusher no está conectado todavía, arrancar polling de inmediato
    if (!isRealtimeConnected()) {
      startInterval()
    }

    // Reaccionar a cambios de estado de la conexión Pusher
    const unbindState = onConnectionStateChange((state) => {
      if (state === 'connected') {
        // Pusher recuperó conexión: sincronizar una vez y detener el polling
        if (role.value === 'client_admin') silentUpdate()
        stopInterval()
      } else if (state === 'disconnected' || state === 'unavailable') {
        // Pusher perdió conexión: activar polling como fallback
        startInterval()
      }
    })

    onUnmounted(() => {
      stopInterval()
      unbindState()
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
