<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../api'
import MapService from '../services/maps/MapService'
import CreateOrderModal from '../components/CreateOrderModal.vue'

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
const viewMode = ref('map') // 'map' or 'stats' for client_admin
const showCreateOrder = ref(false)

const fetchDashboardData = async () => {
  loading.value = true
  console.log('🔄 Iniciando carga de datos para:', authStore.user?.email);
  
  try {
    const ordersRes = await api.get('/orders')
    if (ordersRes.data.status) {
        orders.value = ordersRes.data.data
        stats.value.activeOrders = orders.value.filter(o => o.status === 'publicado' || o.status === 'en_curso').length
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

const initDashboardMap = async () => {
    console.log('📍 Dibujando Mapa en #map-root...');
    await MapService.initialize('map-root', {
        zoom: 14,
        center: [20.5222, -100.8122] // Celaya
    })

    // Limpiar marcadores previos por seguridad
    MapService.clearMarkers()

    // Add Drivers to map
    drivers.value.forEach(driver => {
        const lat = parseFloat(driver.current_lat);
        const lng = parseFloat(driver.current_lng);
        console.log(`🔎 Marcador Conductor:${driver.name} -> [${lat}, ${lng}]`);
        
        if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
            MapService.addMarker(`driver-${driver.id}`, [lat, lng], {
                icon: '🏎️',
                className: 'driver',
                popup: `<b>Conductor:</b> ${driver.name}<br>${driver.vehicle_details}`
            })
            console.log(`✅ Conductor ID:${driver.id} mostrado.`);
        }
    })

    // Add active orders to map
    const activeOrders = orders.value.filter(o => o.status === 'publicado' || o.status === 'en_curso');
    activeOrders.forEach(order => {
        const lat = parseFloat(order.pickup_lat);
        const lng = parseFloat(order.pickup_lng);
        console.log(`🔎 Marcador Pedido ID:${order.id} -> [${lat}, ${lng}]`);

        if (!isNaN(lat) && !isNaN(lng) && lat !== 0) {
            MapService.addMarker(`order-${order.id}`, [lat, lng], {
                icon: '📦',
                className: 'order',
                popup: `<b>Pedido #${order.id}</b><br>${order.pickup_address}`
            })
            console.log(`✅ Pedido ID:${order.id} mostrado.`);
        }
    })
}

const selectOrder = (order) => {
    selectedOrder.value = order
    MapService.centerOn([order.pickup_lat, order.pickup_lng], 16)
    
    // Draw route if it's active
    MapService.clearRoutes()
    MapService.drawRoute(`route-${order.id}`, [
        [order.pickup_lat, order.pickup_lng],
        [order.drop_lat, order.drop_lng]
    ], { color: '#6366F1', weight: 5 })
}

onMounted(fetchDashboardData)
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
        <div class="stat-icon drivers">🏎️</div>
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
            <div class="map-area" style="flex: 1; position: relative; height: 100%;">
                <div id="map-root"></div>
                
                <!-- Floating Navigation Overlays -->
                <div class="map-controls-top">
                    <button class="map-pill active">
                        <span class="dot pulse green"></span> {{ stats.activeOrders }} Viajes Activos
                    </button>
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
                        <button class="close-panel" @click="selectedOrder = null">&times;</button>
                        <h3>Detalles de Ruta</h3>
                        
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
                                <span class="label">ID Pedido</span>
                                <span class="value">#{{ selectedOrder.id }}</span>
                            </div>
                            <div class="meta-item">
                                <span class="label">Estado</span>
                                <span class="value badge">{{ selectedOrder.status.toUpperCase() }}</span>
                            </div>
                        </div>

                        <button class="btn-primary full-width" @click="$router.push('/orders')">
                            Ver Seguimiento Completo
                        </button>
                    </div>
                </transition>
            </div>
            
            <!-- Drivers Right Sidebar -->
            <div class="drivers-sidebar">
                <div class="drivers-header">
                    <h3>Flotilla</h3>
                    <span class="badge" v-if="drivers.length > 0">{{ drivers.length }} Activos</span>
                </div>
                <div class="drivers-list" v-if="drivers.length > 0">
                    <div class="driver-card" v-for="driver in drivers" :key="driver.id" @click="focusDriver(driver)">
                        <div class="driver-avatar">{{ driver.name.charAt(0).toUpperCase() }}</div>
                        <div class="driver-info">
                            <h4>{{ driver.name }}</h4>
                            <p>{{ driver.vehicle_details || 'Vehículo estándar' }}</p>
                        </div>
                        <div class="driver-status">
                            <span class="dot green"></span>
                        </div>
                    </div>
                </div>
                <div class="drivers-empty" v-else>
                    No hay conductores registrados.
                </div>
            </div>
        </div>
        
        <!-- Bottom Recent Orders (Mini) -->
        <div class="mini-orders-grid">
            <div v-for="order in orders.filter(o => o.status === 'publicado').slice(0, 3)" 
                 :key="order.id" 
                 class="mini-order-card"
                 @click="selectOrder(order)"
                 :class="{ active: selectedOrder?.id === order.id }">
                <div class="order-header">
                    <span class="id">#{{ order.id }}</span>
                    <span class="time">{{ new Date(order.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}</span>
                </div>
                <p class="addr">{{ order.drop_address }}</p>
            </div>
        </div>
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

/* Drivers Sidebar inside Map Container */
.drivers-sidebar {
    width: 280px; background: white; border-left: 1px solid var(--border-light);
    display: flex; flex-direction: column; height: 100%;
}

.drivers-header {
    padding: 1rem 1.2rem; border-bottom: 1px solid var(--border-light);
    display: flex; justify-content: space-between; align-items: center;
}

.drivers-header h3 { font-size: 1rem; font-weight: 700; margin: 0; color: #1F2937; }
.drivers-header .badge { background: #DCFCE7; color: #166534; padding: 0.2rem 0.5rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; }

.drivers-list {
    flex: 1; overflow-y: auto; display: flex; flex-direction: column;
}

.driver-card {
    display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.2rem;
    border-bottom: 1px solid #F3F4F6; cursor: pointer; transition: background 0.2s;
}

.driver-card:hover { background: #F9FAFB; }

.driver-avatar {
    width: 36px; height: 36px; border-radius: 50%; background: #E0E7FF; color: #4338CA;
    display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;
}

.driver-info { flex: 1; overflow: hidden; }
.driver-info h4 { margin: 0; font-size: 0.9rem; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.driver-info p { margin: 0; font-size: 0.75rem; color: #6B7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.drivers-empty { padding: 2rem 1rem; text-align: center; color: var(--text-muted); font-size: 0.9rem; }

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

.mini-orders-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; }
.mini-order-card {
    background: white; padding: 1rem; border-radius: 10px; border: 1px solid var(--border-light);
    cursor: pointer; transition: all 0.2s;
}
.mini-order-card:hover, .mini-order-card.active { border-color: #6366F1; box-shadow: 0 4px 6px rgba(0,0,0,0.05); background: #F5F7FF; }
.mini-order-card .order-header { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
.mini-order-card .id { font-weight: 700; font-size: 0.85rem; color: #6366F1; }
.mini-order-card .time { font-size: 0.75rem; color: var(--text-light); }
.mini-order-card .addr { font-size: 0.8rem; color: var(--text-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.close-panel { position: absolute; top: 1rem; right: 1rem; border: none; background: transparent; font-size: 1.5rem; cursor: pointer; color: var(--text-light); }

/* Transitions */
.slide-right-enter-active, .slide-right-leave-active { transition: all 0.3s ease; }
.slide-right-enter-from, .slide-right-leave-to { transform: translateX(50px); opacity: 0; }

.full-width { width: 100%; justify-content: center; }

@media (max-width: 768px) {
    .mini-orders-grid { grid-template-columns: 1fr; }
    .map-detail-panel { width: calc(100% - 3rem); left: 1.5rem; right: 1.5rem; }
}
</style>
