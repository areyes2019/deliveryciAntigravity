<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../api'
import MapService from '../services/maps/MapService'
import CreateOrderModal from '../components/CreateOrderModal.vue'

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
  balance: 0
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

let refreshInterval = null

// --- Real-time Logic ---
const silentUpdate = async () => {
    try {
        const oldOrders = [...orders.value]
        
        // Fetch new data
        const [ordersRes, driversRes] = await Promise.all([
            api.get('/orders'),
            api.get('/drivers')
        ])

        if (ordersRes.data.status) {
            const newOrders = ordersRes.data.data
            
            // Check for status transitions (Transitions: publicado -> tomado/en_camino, or en_camino -> entregado)
            newOrders.forEach(order => {
                const old = oldOrders.find(o => o.id === order.id)
                if (old && old.status !== order.status) {
                    const driver = driversRes.data.data.find(d => String(d.id) === String(order.driver_id))
                    
                    if (old.status === 'publicado' && (order.status === 'tomado' || order.status === 'en_camino')) {
                        showToast(`✅ Viaje #${order.id} aceptado por ${driver?.name || 'un conductor'}`, 'success')
                    } else if (old.status === 'en_camino' && order.status === 'entregado') {
                        showToast(`🏁 Viaje #${order.id} completado con éxito por ${driver?.name || 'el conductor'}`, 'success')
                    }
                }
            })

            orders.value = newOrders
            stats.value.activeOrders = orders.value.filter(o => ['publicado', 'tomado', 'en_camino'].includes(o.status)).length
        }

        if (driversRes.data.status) {
            drivers.value = driversRes.data.data
            stats.value.totalDrivers = drivers.value.length
            
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
                icon: '🏍️',
                className: 'driver',
                popup: `<b>Conductor:</b> ${driver.name}<br>${driver.vehicle_details}`
            });
        }
    });

    // 2. Manage Order Markers (📦)
    const activeOrderStatuses = ['publicado', 'tomado', 'en_camino'];
    
    // We'll iterate through all known orders to sync the map
    orders.value.forEach(order => {
        const markerId = `order-${order.id}`;
        const isActive = activeOrderStatuses.includes(order.status);
        
        if (isActive) {
            const lat = parseFloat(order.pickup_lat);
            const lng = parseFloat(order.pickup_lng);
            if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
                // updateMarker is idempotent: adds if new, updates if exists
                MapService.updateMarker(markerId, [lat, lng], {
                    icon: '📦',
                    className: 'order',
                    popup: `<b>Pedido #${order.id}</b><br>${order.status.toUpperCase()}`
                });
            }
        } else {
            // Remove markers for finished/cancelled orders
            MapService.removeMarker(markerId);
            
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
               (o.status === 'tomado' || o.status === 'en_camino') &&
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
        stats.value.activeOrders = orders.value.filter(o => ['publicado', 'tomado', 'en_camino'].includes(o.status)).length
        console.log('📦 Pedidos recibidos:', orders.value.length);
    }

    if (role.value === 'superadmin') {
      const clientsRes = await api.get('/clients')
      stats.value.totalClients = clientsRes.data.data.length
      stats.value.balance = clientsRes.data.data.reduce((acc, c) => acc + parseFloat(c.credits_balance), 0)
    } else if (role.value === 'client_admin') {
      const driversRes = await api.get('/drivers')
      drivers.value = driversRes.data.data
      stats.value.totalDrivers = drivers.value.length
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
                icon: '🏍️',
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
    const activeOrders = orders.value.filter(o => ['publicado', 'tomado', 'en_camino'].includes(o.status));
    activeOrders.forEach(order => {
        const lat = parseFloat(order.pickup_lat);
        const lng = parseFloat(order.pickup_lng);
        if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
            MapService.addMarker(`order-${order.id}`, [lat, lng], {
                icon: '📦',
                className: 'order',
                popup: `<b>Pedido #${order.id}</b><br>${order.pickup_address}`
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
    
    // Destination marker
    MapService.addMarker('temp-drop', [dropLat, dropLng], {
        icon: '🏁',
        popup: `<b>Entrega Pedido #${order.id}</b><br>${order.drop_address}`
    })

    // Highlight assigned driver
    if (order.driver_id) {
        const assignedDriver = drivers.value.find(d => String(d.id) === String(order.driver_id));
        if (assignedDriver) {
            const dLat = parseFloat(assignedDriver.current_lat)
            const dLng = parseFloat(assignedDriver.current_lng)
            if (!isNaN(dLat) && dLat !== 0) {
                MapService.addMarker(`driver-${assignedDriver.id}`, [dLat, dLng], {
                    icon: '🟢🏍️',
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
    MapService.removeMarker('temp-drop')
    redrawDrivers()
    MapService.centerOn([20.5222, -100.8122], 13)
}

onMounted(() => {
    fetchDashboardData()
    
    // Start Polling (Faster: 2s)
    refreshInterval = setInterval(() => {
        if (role.value === 'client_admin') {
            silentUpdate()
        }
    }, 2000)
})

onUnmounted(() => {
    if (refreshInterval) clearInterval(refreshInterval)
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
          <p class="stat-label">Pedidos Activos</p>
          <h3 class="stat-value">{{ stats.activeOrders }}</h3>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon balance">💰</div>
        <div class="stat-info">
          <p class="stat-label">{{ role === 'superadmin' ? 'Saldo Total Sistema' : 'Saldo en Cartera' }}</p>
          <h3 class="stat-value">${{ stats.balance }}</h3>
        </div>
      </div>
    </div>

    <!-- MAP INTERFACE FOR CLIENT_ADMIN -->
    <div v-if="role === 'client_admin' && viewMode === 'map'" class="dashboard-map-view">
        <div class="dashboard-map-container" style="display: flex;">
            
            <!-- Orders Left Sidebar -->
            <div class="data-sidebar right-border">
                <div class="sidebar-header">
                    <h3>Viajes en Cola</h3>
                    <span class="badge" v-if="stats.activeOrders > 0">{{ stats.activeOrders }} En espera</span>
                </div>
                <div class="sidebar-list" v-if="stats.activeOrders > 0">
                    <div v-for="order in orders.filter(o => ['publicado', 'tomado', 'en_camino'].includes(o.status))" 
                         :key="order.id" 
                         class="order-card"
                         @click="selectOrder(order)"
                         :class="{ active: selectedOrder?.id === order.id }">
                        <div class="order-header">
                            <span class="id">🚗 Viaje #{{ order.id }}</span>
                            <span class="time">{{ new Date(order.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}</span>
                        </div>
                        <p class="addr"><span class="icon">🔵</span> {{ order.pickup_address }}</p>
                        <p class="addr"><span class="icon">🔴</span> {{ order.drop_address }}</p>
                    </div>
                </div>
                <div class="sidebar-empty" v-else>
                    No hay viajes en cola por el momento.
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
                    <button class="map-pill generate" @click="showCreateOrder = true">
                        <span class="icon">🚀</span> Generar Viaje
                    </button>
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

                        <button class="btn-primary full-width" @click="$router.push('/orders')">
                            Ver Seguimiento Completo
                        </button>
                    </div>
                </transition>
            </div>
            
            <!-- Drivers Right Sidebar -->
            <div class="data-sidebar left-border">
                <div class="sidebar-header">
                    <h3>Flotilla</h3>
                    <span class="badge" v-if="drivers.length > 0">{{ drivers.length }} Activos</span>
                </div>
                <div class="sidebar-list" v-if="drivers.length > 0">
                    <div class="driver-card" v-for="driver in drivers" :key="driver.id" @click="focusDriver(driver)">
                        <div class="driver-avatar">{{ driver.name.charAt(0).toUpperCase() }}</div>
                        <div class="driver-info">
                            <div class="name-row">
                                <h4>{{ driver.name }}</h4>
                                <span class="status-pill" :class="isDriverEnRoute(driver) ? 'en-route' : 'available'">
                                    {{ isDriverEnRoute(driver) ? 'En Ruta' : 'Libre' }}
                                </span>
                            </div>
                            <p>{{ driver.vehicle_details || 'Vehículo estándar' }}</p>
                        </div>
                    </div>
                </div>
                <div class="sidebar-empty" v-else>
                    No hay conductores registrados.
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

    <!-- Modal Generar Viaje -->
    <CreateOrderModal
        v-if="showCreateOrder"
        @close="showCreateOrder = false"
        @created="fetchDashboardData"
    />
  </div>
</template>

<style scoped>
.dashboard { display: flex; flex-direction: column; gap: 2rem; height: 100%; }

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
.stat-label { font-size: 0.875rem; color: var(--text-muted); margin-bottom: 0.25rem; }
.stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text-main); }

/* Map Specific Dashboard Styles */
.dashboard-map-view { display: flex; flex-direction: column; gap: 1rem; position: relative; height: 100%; min-height: 500px; }

.dashboard-map-container {
    flex: 1; border-radius: 16px; overflow: hidden; position: relative;
    border: 1px solid var(--border-light); box-shadow: 0 10px 30px rgba(0,0,0,0.08);
}

#map-root { width: 100%; height: 100%; min-height: 500px; background: #f0f0f0; }

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
.map-pill.generate:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99, 102, 241, 0.45); }

/* Shared Sidebars inside Map Container */
.data-sidebar {
    width: 280px; background: white; 
    display: flex; flex-direction: column; height: 100%;
}
.data-sidebar.left-border { border-left: 1px solid var(--border-light); }
.data-sidebar.right-border { border-right: 1px solid var(--border-light); }

.sidebar-header {
    padding: 1rem 1.2rem; border-bottom: 1px solid var(--border-light);
    display: flex; justify-content: space-between; align-items: center;
}

.sidebar-header h3 { font-size: 1rem; font-weight: 700; margin: 0; color: #1F2937; }
.sidebar-header .badge { background: #DCFCE7; color: #166534; padding: 0.2rem 0.5rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }

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

@media (max-width: 900px) {
    .dashboard-map-container { flex-direction: column; }
    .data-sidebar { width: 100%; height: 300px; }
    .data-sidebar.left-border, .data-sidebar.right-border { border: none; border-bottom: 1px solid var(--border-light); }
    .map-area { min-height: 400px; }
    .map-detail-panel { width: calc(100% - 3rem); left: 1.5rem; right: 1.5rem; }
}
</style>
