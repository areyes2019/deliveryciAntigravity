import { ref, onMounted, onUnmounted } from 'vue'
import api from '../api'
import { subscribe, unsubscribe } from '../services/realtime'

const refreshInterval = ref(null)

export function useRealtimeSync() {
  const startPolling = ({ role, orders, drivers, stats, showToast, updateMapMarkers }) => {
    const silentUpdate = async () => {
      // Orders y drivers se actualizan de forma independiente para que un fallo
      // en uno no bloquee la actualización del otro.
      try {
        const ordersRes = await api.get('/orders')
        if (ordersRes.data.status) {
          const newOrders = ordersRes.data.data

          // Detectar cambios en pedidos
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
          drivers.value = driversRes.data.data
        }
      } catch (error) {
        console.error('Error en silent update (drivers):', error)
      }

      if (updateMapMarkers) updateMapMarkers()
    }

    // Polling cada 5 segundos como fallback cuando Pusher no está disponible
    refreshInterval.value = setInterval(() => {
      if (role.value === 'client_admin') {
        silentUpdate()
      }
    }, 5000)

    // Limpiar al desmontar
    onUnmounted(() => {
      if (refreshInterval.value) {
        clearInterval(refreshInterval.value)
        refreshInterval.value = null
      }
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

    // Limpiar listeners al desmontar
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
