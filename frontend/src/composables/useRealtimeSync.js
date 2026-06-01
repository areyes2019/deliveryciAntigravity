import { ref, onMounted, onUnmounted } from 'vue'
import api from '../api'
import { subscribe, unsubscribe } from '../services/realtime'

const refreshInterval = ref(null)

export function useRealtimeSync() {
  const startPolling = ({ role, orders, drivers, stats, showToast, updateMapMarkers }) => {
    const silentUpdate = async () => {
      try {
        const [ordersRes, driversRes] = await Promise.all([
          api.get('/orders'),
          api.get('/drivers')
        ])

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

        if (driversRes.data.status) {
          drivers.value = driversRes.data.data
        }

        if (updateMapMarkers) updateMapMarkers()
      } catch (error) {
        console.error('Error en silent update:', error)
      }
    }

    // Iniciar polling cada 3 segundos solo para client_admin
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

    return { silentUpdate }
  }

  const stopPolling = () => {
    if (refreshInterval.value) {
      clearInterval(refreshInterval.value)
      refreshInterval.value = null
    }
  }

  const setupRealtimeListeners = ({ clientId, onOrderUpdated, onDriverMoved }) => {
    if (!clientId) return

    const channel = subscribe(`orders.${clientId}`)
    channel.bind('order-updated', (data) => {
      if (onOrderUpdated) onOrderUpdated(data)
    })

    const driverChannel = subscribe(`trips.${clientId}`)
    driverChannel.bind('driver-moved', (data) => {
      if (onDriverMoved) onDriverMoved(data)
    })

    // Limpiar listeners al desmontar
    onUnmounted(() => {
      unsubscribe(`orders.${clientId}`)
      unsubscribe(`trips.${clientId}`)
    })

    return { channel, driverChannel }
  }

  return {
    refreshInterval,
    startPolling,
    stopPolling,
    setupRealtimeListeners
  }
}
