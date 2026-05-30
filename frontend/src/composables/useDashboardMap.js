import { nextTick } from 'vue'
import MapService from '../services/maps/MapService'

// --- Iconos de conductores ---
const DRIVER_MAP_ICON = {
  path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z',
  fillColor: '#6366F1',
  fillOpacity: 1,
  strokeWeight: 2,
  strokeColor: '#FFFFFF',
  scale: 0.9,
  anchor: [12, 24],
  labelOrigin: [12, 10]
}

const DRIVER_MAP_ICON_HIGHLIGHT = {
  ...DRIVER_MAP_ICON,
  scale: 1.3,
  fillColor: '#F59E0B',
  strokeColor: '#FFFFFF',
  strokeWeight: 3,
  labelOrigin: [12, 10]
}

export function useDashboardMap() {
  // --- Iconos ---
  const buildDriverMapIcon = (isHighlighted = false) => {
    return isHighlighted ? DRIVER_MAP_ICON_HIGHLIGHT : DRIVER_MAP_ICON
  }

  const createDriverMapIcon = (isEnRoute = false) => {
    return buildDriverMapIcon(isEnRoute)
  }

  // --- Estado de ruta del conductor ---
  const isDriverEnRoute = (driver, orders) => {
    if (!orders || orders.length === 0) return false

    const twelveHoursAgo = new Date(Date.now() - 12 * 60 * 60 * 1000)

    return orders.some(o => {
      const orderDate = new Date(o.created_at)
      return String(o.driver_id) === String(driver.id) &&
        (o.status === 'tomado' || o.status === 'arribado' || o.status === 'en_camino') &&
        orderDate > twelveHoursAgo
    })
  }

  // --- Inicializar mapa ---
  const initDashboardMap = async ({ orders, drivers, isDriverEnRoute }) => {
    console.log('📍 Dibujando Mapa en #map-root...')

    await MapService.initialize('map-root', {
      zoom: 14,
      center: [20.5222, -100.8122] // Celaya
    })

    // Limpiar marcadores previos por seguridad
    MapService.clearMarkers()

    // Add Drivers to map
    redrawDrivers({ drivers, isDriverEnRoute })

    // Add active orders to map
    const activeOrders = orders.filter(o =>
      ['publicado', 'tomado', 'arribado', 'en_camino'].includes(o.status)
    )

    activeOrders.forEach(order => {
      const pickupLat = parseFloat(order.pickup_lat)
      const pickupLng = parseFloat(order.pickup_lng)
      const dropLat = parseFloat(order.drop_lat)
      const dropLng = parseFloat(order.drop_lng)

      if (!isNaN(pickupLat) && !isNaN(pickupLng) && pickupLat !== 0) {
        MapService.addMarker(`order-${order.id}`, [pickupLat, pickupLng], {
          icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png',
          className: 'order',
          popup: `<b>Pedido #${order.id}</b><br>${order.pickup_address}`
        })
      }

      if (!isNaN(dropLat) && !isNaN(dropLng) && dropLat !== 0) {
        MapService.addMarker(`order-drop-${order.id}`, [dropLat, dropLng], {
          icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',
          className: 'order-drop',
          popup: `<b>Entrega Pedido #${order.id}</b><br>${order.drop_address}`
        })
      }
    })
  }

  // --- Redibujar conductores ---
  const redrawDrivers = ({ drivers, isDriverEnRoute }) => {
    drivers.forEach(driver => {
      const lat = parseFloat(driver.current_lat)
      const lng = parseFloat(driver.current_lng)
      if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
        MapService.addMarker(`driver-${driver.id}`, [lat, lng], {
          icon: createDriverMapIcon(isDriverEnRoute(driver)),
          className: 'driver',
          popup: `<b>Conductor:</b> ${driver.name}<br>${driver.vehicle_details}`
        })
      }
    })
  }

  // --- Actualizar marcadores del mapa ---
  const updateMapMarkers = ({ drivers, orders, isDriverEnRoute, selectedOrder, clearSelection, showToast }) => {
    if (!MapService.getNativeMap()) return

    // 1. Update Driver Markers
    drivers.forEach(driver => {
      const lat = parseFloat(driver.current_lat)
      const lng = parseFloat(driver.current_lng)
      if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
        MapService.updateMarker(`driver-${driver.id}`, [lat, lng], {
          icon: createDriverMapIcon(isDriverEnRoute(driver)),
          className: 'driver',
          popup: `<b>Conductor:</b> ${driver.name}<br>${driver.vehicle_details}`
        })
      }
    })

    // 2. Manage Order Markers
    const activeOrderStatuses = ['publicado', 'tomado', 'arribado', 'en_camino']

    orders.forEach(order => {
      const markerId = `order-${order.id}`
      const dropMarkerId = `order-drop-${order.id}`
      const isActive = activeOrderStatuses.includes(order.status)

      if (isActive) {
        const lat = parseFloat(order.pickup_lat)
        const lng = parseFloat(order.pickup_lng)
        const dropLat = parseFloat(order.drop_lat)
        const dropLng = parseFloat(order.drop_lng)

        if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
          MapService.updateMarker(markerId, [lat, lng], {
            icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png',
            className: 'order',
            popup: `<b>Pedido #${order.id}</b><br>${order.status.toUpperCase()}`
          })
        }

        if (!isNaN(dropLat) && !isNaN(dropLng) && dropLat !== 0) {
          MapService.updateMarker(dropMarkerId, [dropLat, dropLng], {
            icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',
            className: 'order-drop',
            popup: `<b>Entrega Pedido #${order.id}</b><br>${order.drop_address}`
          })
        }
      } else {
        MapService.removeMarker(markerId)
        MapService.removeMarker(dropMarkerId)

        if (selectedOrder && selectedOrder.id === order.id) {
          if (order.status === 'entregado' || order.status === 'cancelado') {
            if (clearSelection) clearSelection()
            if (showToast) showToast(`🏁 Viaje #${order.id} finalizado y retirado del mapa`, 'info')
          }
        }
      }
    })
  }

  // --- Enfocar conductor ---
  const focusDriver = (driver, { orders, drivers, isDriverEnRoute }) => {
    const lat = parseFloat(driver.current_lat)
    const lng = parseFloat(driver.current_lng)

    if (isNaN(lat) || isNaN(lng) || lat === 0) {
      console.warn('Conductor sin ubicación:', driver.name)
      return
    }

    MapService.flyTo([lat, lng], 16)

    // Buscar pedido activo de este conductor
    const activeOrder = orders.find(o =>
      String(o.driver_id) === String(driver.id) &&
      ['tomado', 'arribado', 'en_camino'].includes(o.status)
    )

    if (activeOrder) {
      const pickupLat = parseFloat(activeOrder.pickup_lat)
      const pickupLng = parseFloat(activeOrder.pickup_lng)
      const dropLat = parseFloat(activeOrder.drop_lat)
      const dropLng = parseFloat(activeOrder.drop_lng)

      if (!isNaN(pickupLat) && pickupLat !== 0 && !isNaN(dropLat) && dropLat !== 0) {
        MapService.drawRoute(`focus-route-${driver.id}`, [
          [lat, lng],
          [pickupLat, pickupLng],
          [dropLat, dropLng]
        ], { color: '#10B981', weight: 4 })
      }
    }

    // Resaltar conductor
    MapService.addMarker(`driver-${driver.id}`, [lat, lng], {
      icon: createDriverMapIcon(true),
      className: 'driver',
      popup: `<b>Conductor:</b> ${driver.name}<br>${driver.vehicle_details}`
    })
  }

  // --- Refrescar dimensiones del mapa (útil al alternar vistas) ---
  const resizeMap = () => {
    const nativeMap = MapService.getNativeMap()
    if (!nativeMap) {
      console.warn('⚠️ resizeMap: No hay instancia de mapa para redimensionar.')
      return
    }
    // Google Maps: dispara el evento resize para que el mapa recalcule sus dimensiones
    if (typeof google !== 'undefined' && google.maps) {
      google.maps.event.trigger(nativeMap, 'resize')
      // Re-centrar en Celaya para evitar que quede en coordenadas (0,0) o desubicado
      nativeMap.setCenter({ lat: 20.5222, lng: -100.8122 })
      console.log('📍 resizeMap: Mapa redimensionado y re-centrado.')
    }
  }

  // --- Limpiar y destruir mapa ---
  const destroyMap = () => {
    MapService.destroy()
  }

  return {
    buildDriverMapIcon,
    createDriverMapIcon,
    isDriverEnRoute,
    initDashboardMap,
    redrawDrivers,
    updateMapMarkers,
    focusDriver,
    resizeMap,
    destroyMap
  }
}
