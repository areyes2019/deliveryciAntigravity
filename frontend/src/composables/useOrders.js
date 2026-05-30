import { ref } from 'vue'
import api from '../api'
import MapService from '../services/maps/MapService'

const orders = ref([])
const selectedOrder = ref(null)
const routeInfo = ref(null)

export function useOrders() {
  const selectOrder = async (order, { drivers, createDriverMapIcon, redrawDrivers } = {}) => {
    console.log('👁 Pedido seleccionado:', order.id, order);
    selectedOrder.value = order
    routeInfo.value = null

    MapService.clearRoutes()
    MapService.removeMarker('temp-drop')

    if (redrawDrivers) redrawDrivers()

    const pickupLat = parseFloat(order.pickup_lat)
    const pickupLng = parseFloat(order.pickup_lng)
    const dropLat   = parseFloat(order.drop_lat)
    const dropLng   = parseFloat(order.drop_lng)

    console.log('📍 Coordenadas:', { pickupLat, pickupLng, dropLat, dropLng });

    const validPickup = !isNaN(pickupLat) && !isNaN(pickupLng) && pickupLat !== 0
    const validDrop   = !isNaN(dropLat)   && !isNaN(dropLng)   && dropLat   !== 0

    if (!validPickup || !validDrop) {
      console.warn('⚠️ Coordenadas inválidas en el pedido', order.id);
      return;
    }

    if (order.driver_id && drivers) {
      const assignedDriver = drivers.find(d => String(d.id) === String(order.driver_id));
      if (assignedDriver) {
        const dLat = parseFloat(assignedDriver.current_lat)
        const dLng = parseFloat(assignedDriver.current_lng)
        if (!isNaN(dLat) && dLat !== 0) {
          MapService.addMarker(`driver-${assignedDriver.id}`, [dLat, dLng], {
            icon: createDriverMapIcon ? createDriverMapIcon(true) : undefined,
            popup: `<b>Asignado:</b> ${assignedDriver.name}`
          });
        }
      }
    }

    console.log('🛣️ Solicitando ruta vía Directions API...');
    const result = await MapService.drawRoute(`route-${order.id}`, [
      [pickupLat, pickupLng],
      [dropLat, dropLng]
    ], { color: '#6366F1', weight: 5 })

    console.log('📡 Resultado ruta:', result);
    if (result && result.distance) {
      routeInfo.value = result;
    }
  }

  const clearSelection = () => {
    selectedOrder.value = null
    routeInfo.value = null
    MapService.clearRoutes()
    MapService.centerOn([20.5222, -100.8122], 13)
  }

  const cancelOrder = async ({ showToast, clearSelection, fetchDashboardData } = {}) => {
    if (!selectedOrder.value) return

    const orderId = selectedOrder.value.id
    const confirmCancel = confirm(`¿Estás seguro de que deseas cancelar el viaje #${orderId}?`)

    if (!confirmCancel) return

    try {
      const response = await api.put(`/orders/${orderId}/cancel`)
      if (response.data.status) {
        if (showToast) showToast(`Viaje #${orderId} cancelado exitosamente`, 'success')
        if (clearSelection) clearSelection()
        if (fetchDashboardData) await fetchDashboardData()
      }
    } catch (error) {
      const message = error.response?.data?.message || 'Error al cancelar el viaje'
      if (showToast) showToast(message, 'error')
      console.error('Error canceling order:', error)
    }
  }

  return {
    orders,
    selectedOrder,
    routeInfo,
    selectOrder,
    clearSelection,
    cancelOrder
  }
}
