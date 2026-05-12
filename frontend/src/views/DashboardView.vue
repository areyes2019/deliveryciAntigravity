<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../api'
import MapService from '../services/maps/MapService'
import CreateOrderModal from '../components/CreateOrderModal.vue'
import CreateOrderManualModal from '../components/CreateOrderManualModal.vue'

const buildDriverMapIcon = (highlight = false) => {
  const size = highlight ? { width: 70, height: 47 } : { width: 55, height: 37 }
  return {
    url: '/public/35859-removebg-preview.png',
    scaledSize: size,
    anchor: { x: size.width / 2, y: size.height / 2 }
  }
}

const DRIVER_MAP_ICON = buildDriverMapIcon(false)
const DRIVER_MAP_ICON_HIGHLIGHT = buildDriverMapIcon(true)

const createDriverMapIcon = (highlight = false) => {
  return highlight ? DRIVER_MAP_ICON_HIGHLIGHT : DRIVER_MAP_ICON
}

// --- Notification Toasts ---
const toasts = ref([])
const showToast = (message, type = 'success') => {
  const id = Date.now()
  toasts.value.push({ id, message, type })
  setTimeout(() => {
    toasts.value = toasts.value.filter(t => t.id !== id)
  }, 5000)
}

const focusDriver = (driver) => {
    const lat = parseFloat(driver.current_lat);
    const lng = parseFloat(driver.current_lng);
    if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
        MapService.centerOn([lat, lng], 15);
    } else {
        alert('Este conductor no ha reportado su ubicación aún.');
    }
}

const authStore = useAuthStore()
const role = computed(() => authStore.userRole)
const userName = computed(() => authStore.user?.name || 'User')

const stats = ref({
  totalClients: 0,
  totalDrivers: 0,
  activeOrders: 0,
  balance: 0,
  fleetBalance: 0
})

const recentActivity = ref([])
const loading = ref(true)
const orders = ref([])
const drivers = ref([])

// Map State
const selectedOrder = ref(null)
const routeInfo = ref(null)
const viewMode = ref('map') // 'map' or 'stats' for client_admin
const showCreateOrder = ref(false)
const showCreateOrderManual = ref(false)
const clientZones = ref([])

const hasZones = computed(() => clientZones.value.length > 0)

let refreshInterval = null

// --- Real-time Logic ---
const silentUpdate = async () => {
    try {
        const oldOrders = [...orders.value]

        // Fetch orders and drivers independently so a driver failure doesn't block order updates
        const [ordersResult, driversResult] = await Promise.allSettled([
            api.get('/orders'),
            api.get('/drivers')
        ])
        const ordersRes = ordersResult.status === 'fulfilled' ? ordersResult.value : null
        const driversRes = driversResult.status === 'fulfilled' ? driversResult.value : null

        if (ordersRes?.data?.status) {
            const newOrders = ordersRes.data.data

            // Check for key status transitions in the active delivery flow.
            newOrders.forEach(order => {
                const old = oldOrders.find(o => o.id === order.id)
                if (old && old.status !== order.status) {
                    const driver = driversRes?.data?.data?.find(d => String(d.id) === String(order.driver_id))

                    if (old.status === 'publicado' && (order.status === 'tomado' || order.status === 'arribado' || order.status === 'en_camino')) {
                        showToast(`✅ Viaje #${order.id} aceptado por ${driver?.name || 'un conductor'}`, 'success')
                    } else if (old.status === 'tomado' && order.status === 'arribado') {
                        showToast(`📍 Viaje #${order.id} llegó al punto de recogida`, 'info')
                    } else if (old.status === 'en_camino' && order.status === 'entregado') {
                        showToast(`🏁 Viaje #${order.id} completado con éxito por ${driver?.name || 'el conductor'}`, 'success')
                    }
                }
            })

            orders.value = newOrders
            stats.value.activeOrders = orders.value.filter(o => ['publicado', 'tomado', 'arribado', 'en_camino'].includes(o.status)).length
        }

        if (driversRes?.data?.status) {
            drivers.value = driversRes.data.data
            stats.value.totalDrivers = drivers.value.length
            stats.value.fleetBalance = drivers.value.reduce((acc, d) => acc + (parseFloat(d.balance) || 0), 0)

            // Update Markers on map silently
            if (viewMode.value === 'map') {
                updateMapMarkers()
            }
        }
    } catch (e) {
        console.warn('Silent update failed:', e)
    }
}

const updateMapMarkers = () => {
    if (viewMode.value !== 'map') return;

    // 1. Update/Add Driver Markers
    drivers.value.forEach(driver => {
        const lat = parseFloat(driver.current_lat);
        const lng = parseFloat(driver.current_lng);
        if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
            MapService.updateMarker(`driver-${driver.id}`, [lat, lng], {
                icon: createDriverMapIcon(isDriverEnRoute(driver)),
                className: 'driver',
                popup: `<b>Conductor:</b> ${driver.name}<br>${driver.vehicle_details}`
            });
        }
    });

    // 2. Manage Order Markers
    const activeOrderStatuses = ['publicado', 'tomado', 'arribado', 'en_camino'];
    
    // We'll iterate through all known orders to sync the map
    orders.value.forEach(order => {
        const markerId = `order-${order.id}`;
        const dropMarkerId = `order-drop-${order.id}`;
        const isActive = activeOrderStatuses.includes(order.status);
        
        if (isActive) {
            const lat = parseFloat(order.pickup_lat);
            const lng = parseFloat(order.pickup_lng);
            const dropLat = parseFloat(order.drop_lat);
            const dropLng = parseFloat(order.drop_lng);
            
            if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
                // updateMarker is idempotent: adds if new, updates if exists
                MapService.updateMarker(markerId, [lat, lng], {
                    icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png',
                    className: 'order',
                    popup: `<b>Pedido #${order.id}</b><br>${order.status.toUpperCase()}`
                });
            }
            
            if (!isNaN(dropLat) && !isNaN(dropLng) && dropLat !== 0) {
                MapService.updateMarker(dropMarkerId, [dropLat, dropLng], {
                    icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',
                    className: 'order-drop',
                    popup: `<b>Entrega Pedido #${order.id}</b><br>${order.drop_address}`
                });
            }
        } else {
            // Remove markers for finished/cancelled orders
            MapService.removeMarker(markerId);
            MapService.removeMarker(dropMarkerId);
            
            // Auto-clear selection if the selected order just got finished
            if (selectedOrder.value && selectedOrder.value.id === order.id) {
                if (order.status === 'entregado' || order.status === 'cancelado') {
                    clearSelection();
                    showToast(`🏁 Viaje #${order.id} finalizado y retirado del mapa`, 'info');
                }
            }
        }
    });
}

const isDriverEnRoute = (driver) => {
    if (!orders.value || orders.value.length === 0) return false;
    
    // Only consider orders from the last 12 hours
    const twelveHoursAgo = new Date(Date.now() - 12 * 60 * 60 * 1000);
    
    return orders.value.some(o => {
        const orderDate = new Date(o.created_at);
        return String(o.driver_id) === String(driver.id) && 
               (o.status === 'tomado' || o.status === 'arribado' || o.status === 'en_camino') &&
               orderDate > twelveHoursAgo;
    })
}

const fetchDashboardData = async () => {
  loading.value = true
  console.log('🔄 Iniciando carga de datos para:', authStore.user?.email);
  
  try {
    const ordersRes = await api.get('/orders')
    if (ordersRes.data.status) {
        orders.value = ordersRes.data.data
        stats.value.activeOrders = orders.value.filter(o => ['publicado', 'tomado', 'arribado', 'en_camino'].includes(o.status)).length
        console.log('📦 Pedidos recibidos:', orders.value.length);
    }

    if (role.value === 'superadmin') {
      const clientsRes = await api.get('/clients')
      stats.value.totalClients = clientsRes.data.data.length
      stats.value.balance = clientsRes.data.data.reduce((acc, c) => acc + parseFloat(c.credits_balance), 0)
    } else if (role.value === 'client_admin') {
      const [driversRes, geofencesRes] = await Promise.all([
          api.get('/drivers'),
          api.get('/geofences')
      ])
      drivers.value = driversRes.data.data
      stats.value.totalDrivers = drivers.value.length
      stats.value.fleetBalance = drivers.value.reduce((acc, d) => acc + (parseFloat(d.balance) || 0), 0)
      clientZones.value = geofencesRes.data?.data ?? []
      console.log('🏎️ Conductores recibidos:', drivers.value.length);
      
      const meRes = await api.get('/auth/me')
      stats.value.balance = meRes.data.data.client_balance || 0
      
      // Initialize Map if in map mode
      if (viewMode.value === 'map') {
          console.log('🗺️ Sincronizando Mapa...');
          await nextTick()
          // Delay de seguridad de 800ms para asegurar renderizado del DOM
          setTimeout(async () => {
              await initDashboardMap()
          }, 800)
      }
    }
  } catch (error) {
    console.error('❌ Error fetching dashboard data:', error)
  } finally {
    loading.value = false
  }
}

const redrawDrivers = () => {
    drivers.value.forEach(driver => {
        const lat = parseFloat(driver.current_lat);
        const lng = parseFloat(driver.current_lng);
        if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
            MapService.addMarker(`driver-${driver.id}`, [lat, lng], {
                icon: createDriverMapIcon(isDriverEnRoute(driver)),
                className: 'driver',
                popup: `<b>Conductor:</b> ${driver.name}<br>${driver.vehicle_details}`
            })
        }
    })
}

const initDashboardMap = async () => {
    console.log('📍 Dibujando Mapa en #map-root...');
    await MapService.initialize('map-root', {
        zoom: 14,
        center: [20.5222, -100.8122] // Celaya
    })

    // Limpiar marcadores previos por seguridad
    MapService.clearMarkers()

    // Add Drivers to map
    redrawDrivers()

    // Add active orders to map
    const activeOrders = orders.value.filter(o => ['publicado', 'tomado', 'arribado', 'en_camino'].includes(o.status));
    activeOrders.forEach(order => {
        const pickupLat = parseFloat(order.pickup_lat);
        const pickupLng = parseFloat(order.pickup_lng);
        const dropLat = parseFloat(order.drop_lat);
        const dropLng = parseFloat(order.drop_lng);
        
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

const selectOrder = async (order) => {
    console.log('👁 Pedido seleccionado:', order.id, order);
    selectedOrder.value = order
    routeInfo.value = null
    
    MapService.clearRoutes()
    MapService.removeMarker('temp-drop')
    redrawDrivers()
    
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
    
    // Highlight assigned driver
    if (order.driver_id) {
        const assignedDriver = drivers.value.find(d => String(d.id) === String(order.driver_id));
        if (assignedDriver) {
            const dLat = parseFloat(assignedDriver.current_lat)
            const dLng = parseFloat(assignedDriver.current_lng)
            if (!isNaN(dLat) && dLat !== 0) {
                MapService.addMarker(`driver-${assignedDriver.id}`, [dLat, dLng], {
                    icon: createDriverMapIcon(true),
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
    redrawDrivers()
    MapService.centerOn([20.5222, -100.8122], 13)
}

const cancelOrder = async () => {
    if (!selectedOrder.value) return
    
    const orderId = selectedOrder.value.id
    const confirmCancel = confirm(`¿Estás seguro de que deseas cancelar el viaje #${orderId}?`)
    
    if (!confirmCancel) return
    
    try {
        const response = await api.put(`/orders/${orderId}/cancel`)
        if (response.data.status) {
            showToast(`Viaje #${orderId} cancelado exitosamente`, 'success')
            clearSelection()
            // Refresh the orders list
            await fetchDashboardData()
        }
    } catch (error) {
        const message = error.response?.data?.message || 'Error al cancelar el viaje'
        showToast(message, 'error')
        console.error('Error canceling order:', error)
    }
}

onMounted(() => {
    fetchDashboardData()
    
    // Start Polling (Requested: 3s)
    refreshInterval = setInterval(() => {
        if (role.value === 'client_admin') {
            silentUpdate()
        }
    }, 3000)
})

onUnmounted(() => {
    if (refreshInterval) clearInterval(refreshInterval)
    MapService.destroy()
})

// --- Handler directo al crear orden: usa el objeto ya devuelto por el backend ---
const onOrderCreated = (newOrder) => {
    orders.value = [newOrder, ...orders.value]
    stats.value.activeOrders = orders.value.filter(o =>
        ['publicado', 'tomado', 'arribado', 'en_camino'].includes(o.status)
    ).length
    updateMapMarkers()
}

// --- Categorized Trip Lists ---
const pendingOrders = computed(() => {
    return orders.value.filter(o => o.status === 'publicado')
})

const scheduledOrders = computed(() => {
    const now = new Date()
    return orders.value.filter(o =>
        o.status === 'pendiente' &&
        o.scheduled_at &&
        new Date(o.scheduled_at) > now
    )
})

const activeOrdersList = computed(() => {
    return orders.value.filter(o => ['tomado', 'arribado', 'en_camino'].includes(o.status))
})

const activeDrivers = computed(() => {
    return drivers.value.filter(d => d.is_active != 0)
})
</script>

<template>
  <div class="dashboard">
    <header class="dashboard-header" v-if="role === 'superadmin' || viewMode === 'stats'">
      <div class="welcome">
        <h1>¡Bienvenido de nuevo, {{ userName }}! 👋</h1>
        <p>Esto es lo que está pasando en tu plataforma hoy.</p>
      </div>
    </header>

    <!-- Stats Grid (Hidden for client_admin in MAP mode) -->
    <div class="stats-grid" v-if="role === 'superadmin' || viewMode === 'stats'">
      <div class="stat-card" v-if="role === 'superadmin'">
        <div class="stat-icon clients">🏢</div>
        <div class="stat-info">
          <p class="stat-label">Clientes Totales</p>
          <h3 class="stat-value">{{ stats.totalClients }}</h3>
        </div>
      </div>

      <div class="stat-card" v-if="role === 'client_admin'">
        <div class="stat-icon drivers">🏍️</div>
        <div class="stat-info">
          <p class="stat-label">Mis Conductores</p>
          <h3 class="stat-value">{{ stats.totalDrivers }}</h3>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon orders">📦</div>
        <div class="stat-info">
          <p class="stat-label">Entregas Activas</p>
          <h3 class="stat-value">{{ stats.activeOrders }}</h3>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon balance">💰</div>
        <div class="stat-info">
          <p class="stat-label">{{ role === 'superadmin' ? 'Saldo Total Sistema' : 'Mi Saldo (Créditos)' }}</p>
          <h3 class="stat-value">${{ stats.balance.toFixed(2) }}</h3>
        </div>
      </div>

      <div class="stat-card" v-if="role === 'client_admin'">
        <div class="stat-icon fleet">🏦</div>
        <div class="stat-info">
          <p class="stat-label">Efectivo en Flota</p>
          <h3 class="stat-value">${{ stats.fleetBalance.toFixed(2) }}</h3>
        </div>
      </div>
    </div>

    <!-- MAP INTERFACE FOR CLIENT_ADMIN -->
    <div v-if="role === 'client_admin' && viewMode === 'map'" class="dashboard-map-view">
        <div class="dashboard-map-container" style="display: flex;">
            
            <!-- Orders Left Sidebar -->
            <div class="data-sidebar right-border">
                <!-- Pending Trips Section -->
                <div class="sidebar-section">
                    <div class="sidebar-header">
                        <h3>Viajes Pendientes</h3>
                        <span class="badge pending" v-if="pendingOrders.length > 0">{{ pendingOrders.length }}</span>
                    </div>
                    <div class="sidebar-list scrollable">
                        <div v-if="pendingOrders.length > 0">
                            <div v-for="order in pendingOrders" 
                                 :key="order.id" 
                                 class="order-card pending"
                                 @click="selectOrder(order)"
                                 :class="{ active: selectedOrder?.id === order.id }">
                                <div class="order-header">
                                    <span class="id">⏳ #{{ order.id }}</span>
                                    <span class="time">{{ new Date(order.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}</span>
                                </div>
                                <p class="addr"><span class="icon">🔵</span> {{ order.pickup_address }}</p>
                                <p class="addr"><span class="icon">🔴</span> {{ order.drop_address }}</p>
                            </div>
                        </div>
                        <div class="sidebar-empty mini" v-else>
                            No hay viajes pendientes.
                        </div>
                    </div>
                </div>

                <!-- Scheduled Trips Section -->
                <div class="sidebar-section" style="border-top: 2px solid #f3f4f6;" v-if="scheduledOrders.length > 0">
                    <div class="sidebar-header">
                        <h3>Programados</h3>
                        <span class="badge scheduled">{{ scheduledOrders.length }}</span>
                    </div>
                    <div class="sidebar-list scrollable">
                        <div v-for="order in scheduledOrders" :key="order.id" class="order-card scheduled-card">
                            <div class="order-header">
                                <span class="id">📅 #{{ order.id }}</span>
                                <span class="scheduled-time">
                                    {{ new Date(order.scheduled_at).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' }) }}
                                    {{ new Date(order.scheduled_at).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true }) }}
                                </span>
                            </div>
                            <p class="addr"><span class="icon">🔵</span> {{ order.pickup_address }}</p>
                            <p class="addr"><span class="icon">🔴</span> {{ order.drop_address }}</p>
                        </div>
                    </div>
                </div>

                <!-- Active Trips Section -->
                <div class="sidebar-section" style="border-top: 2px solid #f3f4f6; flex: 1; display: flex; flex-direction: column;">
                    <div class="sidebar-header">
                        <h3>Viajes Activos</h3>
                        <span class="badge active-now" v-if="activeOrdersList.length > 0">{{ activeOrdersList.length }}</span>
                    </div>
                    <div class="sidebar-list scrollable" style="flex: 1;">
                        <div v-if="activeOrdersList.length > 0">
                            <div v-for="order in activeOrdersList" 
                                 :key="order.id" 
                                 class="order-card active-trip"
                                 @click="selectOrder(order)"
                                 :class="{ active: selectedOrder?.id === order.id }">
                                <div class="order-header">
                                    <span class="id">🏍️ #{{ order.id }}</span>
                                    <span class="status-tag" :class="order.status">
                                      {{ order.status === 'tomado' ? 'Aceptado' : order.status === 'arribado' ? 'En Recogida' : 'En Camino' }}
                                    </span>
                                </div>
                                <div class="driver-assigned" v-if="order.driver_id">
                                    <small>Conductor: {{ drivers.find(d => String(d.id) === String(order.driver_id))?.name || 'Asignado' }}</small>
                                </div>
                                <p class="addr"><span class="icon">🔵</span> {{ order.pickup_address }}</p>
                                <p class="addr"><span class="icon">🔴</span> {{ order.drop_address }}</p>
                            </div>
                        </div>
                        <div class="sidebar-empty mini" v-else>
                            No hay viajes activos.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Map Area -->
            <div class="map-area" style="flex: 1; position: relative; height: 100%;">
                <div id="map-root"></div>
                
                <!-- Floating Navigation Overlays -->
                <div class="map-controls-top">
                    <div class="map-pill active">
                        <span class="dot pulse green"></span> {{ stats.activeOrders }} Viajes en Cola
                    </div>
                    <button class="map-pill secondary">
                        <span class="icon">🏎️</span> {{ stats.totalDrivers }} Conductores
                    </button>
                    <button
                        class="map-pill generate"
                        :class="{ disabled: !hasZones }"
                        :disabled="!hasZones"
                        :title="!hasZones ? 'Debes configurar al menos una zona de operación antes de generar viajes' : ''"
                        @click="hasZones && (showCreateOrder = true)">
                        <span class="icon">🤖</span> IA
                    </button>
                    <button
                        class="map-pill generate-manual"
                        :class="{ disabled: !hasZones }"
                        :disabled="!hasZones"
                        :title="!hasZones ? 'Debes configurar al menos una zona de operación antes de generar viajes' : ''"
                        @click="hasZones && (showCreateOrderManual = true)">
                        <span class="icon">📝</span> Manual
                    </button>
                    <div v-if="!hasZones" class="map-pill warning-pill">
                        ⚠️ Sin zonas configuradas
                    </div>
                </div>

                <!-- Side Route Detail Panel -->
                <transition name="slide-right">
                    <div v-if="selectedOrder" class="map-detail-panel">
                        <button class="close-panel" @click="clearSelection">&times;</button>
                        <h3>Detalles del Viaje</h3>
                        
                        <div class="route-visual">
                            <div class="route-stop">
                                <span class="dot green"></span>
                                <div class="stop-info">
                                    <p class="label">Recogida</p>
                                    <p class="address">{{ selectedOrder.pickup_address }}</p>
                                </div>
                            </div>
                            <div class="route-line"></div>
                            <div class="route-stop">
                                <span class="dot red"></span>
                                <div class="stop-info">
                                    <p class="label">Entrega</p>
                                    <p class="address">{{ selectedOrder.drop_address }}</p>
                                </div>
                            </div>
                        </div>

                        <div class="receiver-info">
                            <div class="receiver-item" v-if="selectedOrder.receiver_name">
                                <span class="label">👤 Nombre de quien recibe</span>
                                <span class="value">{{ selectedOrder.receiver_name }}</span>
                            </div>
                            <div class="receiver-item" v-if="selectedOrder.receiver_phone">
                                <span class="label">📞 Teléfono</span>
                                <span class="value">{{ selectedOrder.receiver_phone }}</span>
                            </div>
                            <div class="receiver-item" v-if="selectedOrder.description">
                                <span class="label">📝 Descripción del Paquete</span>
                                <span class="value">{{ selectedOrder.description }}</span>
                            </div>
                        </div>

                        <div class="order-meta">
                            <div class="meta-item">
                                <span class="label">Viaje</span>
                                <span class="value">#{{ selectedOrder.id }}</span>
                            </div>
                            <div class="meta-item">
                                <span class="label">Estado</span>
                                <span class="value badge">{{ selectedOrder.status.toUpperCase() }}</span>
                            </div>
                            <div class="meta-item" v-if="routeInfo">
                                <span class="label">Distancia</span>
                                <span class="value">{{ routeInfo.distance }}</span>
                            </div>
                            <div class="meta-item" v-if="routeInfo">
                                <span class="label">Tiempo Est.</span>
                                <span class="value">{{ routeInfo.duration }}</span>
                            </div>
                            <div class="meta-item">
                                <span class="label">Envío</span>
                                <span class="value">${{ Number(selectedOrder.cost).toFixed(2) }}</span>
                            </div>
                            <div class="meta-item" v-if="selectedOrder.payment_type === 'cash_full'">
                                <span class="label">Producto</span>
                                <span class="value">${{ Number(selectedOrder.product_amount || 0).toFixed(2) }}</span>
                            </div>
                            <div class="meta-item price-row" v-if="selectedOrder.payment_type !== 'prepaid'">
                                <span class="label">💰 Total a Cobrar</span>
                                <span class="value price-highlight">${{ Number(selectedOrder.total_to_collect).toFixed(2) }} MXN</span>
                            </div>
                            <div class="meta-item price-row" v-else>
                                <span class="label">💰 Pagado (Prepaid)</span>
                                <span class="value price-highlight">${{ Number(selectedOrder.cost).toFixed(2) }} MXN</span>
                            </div>
                        </div>

                        <button class="btn-danger full-width" @click="cancelOrder">
                            Cancelar Viaje
                        </button>
                    </div>
                </transition>
            </div>
            
            <!-- Drivers Right Sidebar -->
            <div class="data-sidebar left-border">
                <div class="sidebar-header">
                    <h3>Flotilla</h3>
                    <span class="badge" v-if="activeDrivers.length > 0">{{ activeDrivers.length }} En Línea</span>
                </div>
                <div class="sidebar-list" v-if="activeDrivers.length > 0">
                    <div class="driver-card" v-for="driver in activeDrivers" :key="driver.id" @click="focusDriver(driver)">
                        <div class="driver-avatar">{{ driver.name.charAt(0).toUpperCase() }}</div>
                        <div class="driver-info">
                            <div class="name-row">
                                <h4>{{ driver.name }}</h4>
                                <span class="status-pill" :class="isDriverEnRoute(driver) ? 'en-route' : 'available'">
                                    {{ isDriverEnRoute(driver) ? 'En Ruta' : 'Libre' }}
                                </span>
                            </div>
                            <div class="balance-row">
                                <span class="driver-balance" :class="{ 'has-debt': driver.balance > 0 }">
                                    Saldo: ${{ (driver.balance || 0).toFixed(2) }}
                                </span>
                                <span class="vehicle-small">{{ driver.vehicle_details || 'Vehículo estándar' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="sidebar-empty" v-else>
                    No hay conductores en línea.
                </div>
            </div>
        </div>
    </div>
    <!-- Toast Notifications -->
    <div class="toast-container">
        <transition-group name="toast">
            <div v-for="toast in toasts" :key="toast.id" class="toast-message" :class="toast.type">
                {{ toast.message }}
            </div>
        </transition-group>
    </div>

    <!-- Modal Generar Viaje (IA) -->
    <CreateOrderModal
        v-if="showCreateOrder"
        @close="showCreateOrder = false"
        @created="onOrderCreated"
    />

    <!-- Modal Generar Viaje (Manual) -->
    <CreateOrderManualModal
        v-if="showCreateOrderManual"
        @close="showCreateOrderManual = false"
        @created="onOrderCreated"
    />
  </div>
</template>

<style scoped>
.dashboard { 
  display: flex; 
  flex-direction: column; 
  height: calc(100vh - var(--topbar-height)); 
  gap: 0; 
}

.dashboard-header, .stats-grid {
  padding: 1.5rem 2rem;
}

.dashboard-header h1 { font-size: 1.75rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem; }
.dashboard-header p { color: var(--text-muted); font-size: 0.95rem; }

/* Stats Grid */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.5rem; }
.stat-card {
  background: white; padding: 1.5rem; border-radius: 12px; border: 1px solid var(--border-light);
  display: flex; align-items: center; gap: 1.25rem; transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); }
.stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.stat-icon.clients { background: #E0F2FE; }
.stat-icon.drivers { background: #F0F9FF; }
.stat-icon.orders { background: #FEF3C7; }
.stat-icon.balance { background: #DCFCE7; }
.stat-icon.fleet { background: #FEF2F2; }
.stat-label { font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.25rem; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text-main); }

/* Map Specific Dashboard Styles */
.dashboard-map-view { 
  display: flex; 
  flex-direction: column; 
  position: relative; 
  flex: 1;
  min-height: 0;
}

.dashboard-map-container {
    flex: 1; 
    overflow: hidden; 
    position: relative;
    border: none;
    box-shadow: none;
}

#map-root { 
  width: 100%; 
  height: 100%; 
  background: #f0f0f0; 
}

.map-controls-top {
    position: absolute; top: 1.5rem; left: 1.5rem; z-index: 100;
    display: flex; gap: 1rem;
}

.map-pill {
    background: white; border: none; padding: 0.6rem 1.2rem; border-radius: 999px;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: flex; align-items: center; gap: 0.5rem;
    font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: all 0.2s;
}

.map-pill.active { background: #6366F1; color: white; }
.map-pill.secondary { background: white; color: var(--text-main); }
.map-pill.generate {
    background: linear-gradient(135deg, #6366F1, #8B5CF6);
    color: white;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
}
.map-pill.generate:hover:not(.disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99, 102, 241, 0.45); }
.map-pill.generate.disabled { background: #9CA3AF; box-shadow: none; cursor: not-allowed; opacity: 0.7; }
.map-pill.generate-manual {
    background: linear-gradient(135deg, #059669, #10B981);
    color: white;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
}
.map-pill.generate-manual:hover:not(.disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.45); }
.map-pill.generate-manual.disabled { background: #9CA3AF; box-shadow: none; cursor: not-allowed; opacity: 0.7; }
.warning-pill { background: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; font-size: 0.8rem; cursor: default; }

/* Shared Sidebars inside Map Container */
.data-sidebar {
    width: 280px; background: white; 
    display: flex; flex-direction: column; height: 100%;
}
.data-sidebar.left-border { border-left: 1px solid var(--border-light); }
.data-sidebar.right-border { border-right: 1px solid var(--border-light); }

.sidebar-section {
    display: flex;
    flex-direction: column;
    max-height: 50%;
}

.sidebar-list.scrollable {
    overflow-y: auto;
    scrollbar-width: thin;
}

.sidebar-list.scrollable::-webkit-scrollbar {
    width: 6px;
}

.sidebar-list.scrollable::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}

.sidebar-header {
    padding: 1rem 1.2rem; border-bottom: 1px solid var(--border-light);
    display: flex; justify-content: space-between; align-items: center;
}

.sidebar-header h3 { font-size: 0.9rem; font-weight: 700; margin: 0; color: #4B5563; text-transform: uppercase; letter-spacing: 0.025em; }
.sidebar-header .badge { padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 800; }
.sidebar-header .badge.pending { background: #FEF3C7; color: #92400E; }
.sidebar-header .badge.active-now { background: #DBEAFE; color: #1E40AF; }
.sidebar-header .badge.scheduled { background: #F3E8FF; color: #6D28D9; }

.sidebar-list {
    flex: 1; overflow-y: auto; display: flex; flex-direction: column;
}

/* Drivers specific */
.driver-card {
    display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.2rem;
    border-bottom: 1px solid #F3F4F6; cursor: pointer; transition: background 0.2s;
}
.driver-card:hover { background: #F9FAFB; }

.driver-avatar {
    width: 36px; height: 36px; border-radius: 50%; background: #E0E7FF; color: #4338CA;
    display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;
    flex-shrink: 0;
}

.driver-info { flex: 1; overflow: hidden; }
.driver-info h4 { margin: 0; font-size: 0.9rem; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.driver-info p { margin: 0; font-size: 0.75rem; color: #6B7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Orders specific */
.order-card {
    background: white; padding: 1rem 1.2rem; border-bottom: 1px solid var(--border-light);
    cursor: pointer; transition: all 0.2s; display: flex; flex-direction: column; gap: 0.5rem;
}
.order-card:hover, .order-card.active { border-left: 4px solid #6366F1; background: #F5F7FF; padding-left: calc(1.2rem - 4px); }
.order-card .order-header { display: flex; justify-content: space-between; margin-bottom: 0.25rem; }
.order-card .id { font-weight: 700; font-size: 0.85rem; color: #6366F1; display: flex; align-items: center; gap: 0.25rem; }
.order-card .time { font-size: 0.75rem; color: var(--text-light); }
.order-card .addr { 
    margin: 0; font-size: 0.8rem; color: var(--text-muted); 
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; 
    display: flex; align-items: center; gap: 0.35rem;
}
.order-card .icon { font-size: 0.7rem; }

.status-tag {
    font-size: 0.65rem;
    font-weight: 700;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    text-transform: uppercase;
}
.order-card.scheduled-card { border-left: 3px solid #8B5CF6; }
.order-card.scheduled-card:hover { border-left-color: #6D28D9; background: #FAF5FF; }
.scheduled-time { font-size: 0.7rem; color: #6D28D9; font-weight: 700; }
.status-tag.tomado { background: #D1FAE5; color: #065F46; }
.status-tag.arribado { background: #E0F2FE; color: #075985; }
.status-tag.en_camino { background: #DBEAFE; color: #1E40AF; }

.driver-assigned {
    margin-top: -0.25rem;
    color: #6B7280;
    font-size: 0.75rem;
}

.sidebar-empty.mini { padding: 1rem; font-size: 0.8rem; }

.sidebar-empty { padding: 2rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

.pulse { width: 8px; height: 8px; border-radius: 50%; display: inline-block; animation: pulse-animation 2s infinite; }
.pulse.green { background: #4ADE80; }

@keyframes pulse-animation { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(74, 222, 128, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); } }

/* Detail Panel */
.map-detail-panel {
    position: absolute; right: 1.5rem; top: 1.5rem; width: 320px; background: white;
    z-index: 100; border-radius: 12px; padding: 1.5rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);
}

.map-detail-panel h3 { font-size: 1rem; font-weight: 700; margin-bottom: 1.5rem; }

.route-visual { position: relative; margin-bottom: 1.5rem; padding-left: 10px; }
.route-stop { display: flex; align-items: flex-start; gap: 1rem; z-index: 2; position: relative; margin-bottom: 1rem; }
.route-line { position: absolute; left: 14px; top: 20px; bottom: 20px; width: 2px; background: #E5E7EB; border-left: 2px dashed #6366F1; z-index: 1; }

.dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; }
.dot.green { background: #10B981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2); }
.dot.red { background: #EF4444; box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.2); }

.stop-info p.label { font-size: 0.7rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700; margin-bottom: 2px; }
.stop-info p.address { font-size: 0.85rem; font-weight: 500; }

.receiver-info { display: flex; flex-direction: column; gap: 0.75rem; border-top: 1px solid #F3F4F6; padding-top: 1rem; margin-bottom: 1.5rem; }
.receiver-item { display: flex; flex-direction: column; gap: 0.25rem; padding: 0.75rem; background: #F9FAFB; border-radius: 8px; border-left: 3px solid #3B82F6; }
.receiver-item .label { color: var(--text-muted); font-size: 0.8rem; font-weight: 700; }
.receiver-item .value { font-weight: 500; font-size: 0.9rem; color: var(--text-dark); }

.order-meta { display: flex; flex-direction: column; gap: 1rem; border-top: 1px solid #F3F4F6; padding-top: 1rem; margin-bottom: 1.5rem; }
.meta-item { display: flex; justify-content: space-between; }
.meta-item .label { color: var(--text-muted); font-size: 0.85rem; }
.meta-item .value { font-weight: 600; font-size: 0.85rem; }
.meta-item .value.price-highlight { color: #059669; font-size: 1rem; font-weight: 800; }
.meta-item .value.muted { color: #9CA3AF; font-style: italic; font-weight: 400; }
.price-row { background: #F0FDF4; border-radius: 8px; padding: 0.5rem 0.75rem; margin-top: 0.25rem; }

.close-panel { position: absolute; top: 1rem; right: 1rem; border: none; background: transparent; font-size: 1.5rem; cursor: pointer; color: var(--text-light); }

/* Transitions */
.slide-right-enter-active, .slide-right-leave-active { transition: all 0.3s ease; }
.slide-right-enter-from, .slide-right-leave-to { transform: translateX(50px); opacity: 0; }

.full-width { width: 100%; justify-content: center; }

.btn-primary {
    background: #6366F1; color: white;
    padding: 0.75rem 1.5rem; border-radius: 8px; border: none;
    font-weight: 600; cursor: pointer; transition: all 0.2s ease;
}
.btn-primary:hover { background: #4F46E5; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(99, 102, 241, 0.4); }
.btn-primary:active { transform: translateY(0); }

.btn-danger {
    background: #EF4444; color: white;
    padding: 0.75rem 1.5rem; border-radius: 8px; border: none;
    font-weight: 600; cursor: pointer; transition: all 0.2s ease;
}
.btn-danger:hover { background: #DC2626; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4); }
.btn-danger:active { transform: translateY(0); }

/* Toast System */
.toast-container {
    position: fixed; top: 1.5rem; right: 1.5rem; 
    z-index: 2000; display: flex; flex-direction: column; gap: 0.75rem;
}
.toast-message {
    padding: 1rem 1.5rem; border-radius: 12px; background: #1F2937; color: white;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); 
    font-weight: 600; font-size: 0.9rem; min-width: 280px;
    border-left: 5px solid #6366F1;
    animation: slideInLeft 0.3s ease-out;
}
.toast-message.success { border-left-color: #10B981; }
.toast-message.error { border-left-color: #EF4444; }

@keyframes slideInLeft {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

/* Driver Status Pills */
.name-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 2px; }
.status-pill {
    font-size: 0.65rem; font-weight: 800; padding: 0.15rem 0.5rem; border-radius: 20px; text-transform: uppercase;
}
.status-pill.available { background: #DCFCE7; color: #166534; }
.status-pill.en-route { background: #DBEAFE; color: #1E40AF; animation: pulse-soft 2s infinite; }

@keyframes pulse-soft { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }

/* Sidebar Cards Overrides */
.driver-card { padding: 0.75rem 1rem !important; }
.driver-info h4 { margin: 0; font-size: 0.85rem; }

.balance-row {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.driver-balance {
    font-size: 0.8rem;
    font-weight: 700;
    color: #64748b;
}

.driver-balance.has-debt {
    color: #166534;
}

.vehicle-small {
    font-size: 0.7rem;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

@media (max-width: 900px) {
    .dashboard-map-container { flex-direction: column; }
    .data-sidebar { width: 100%; height: 300px; }
    .data-sidebar.left-border, .data-sidebar.right-border { border: none; border-bottom: 1px solid var(--border-light); }
    .map-area { min-height: 400px; }
    .map-detail-panel { width: calc(100% - 3rem); left: 1.5rem; right: 1.5rem; }
}
</style>
