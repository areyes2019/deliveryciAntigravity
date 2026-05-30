<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../api'
import { useOrders } from '../composables/useOrders'
import { useDrivers } from '../composables/useDrivers'
import { useRealtimeSync } from '../composables/useRealtimeSync'
import { useDashboardMap } from '../composables/useDashboardMap'
import { useToast } from '../composables/useToast'
import StatsGrid from '../components/dashboard/StatsGrid.vue'
import OrdersSidebar from '../components/dashboard/OrdersSidebar.vue'
import DashboardMap from '../components/dashboard/DashboardMap.vue'
import OrderDetailPanel from '../components/dashboard/OrderDetailPanel.vue'
import FleetSidebar from '../components/dashboard/FleetSidebar.vue'
import ToastContainer from '../components/dashboard/ToastContainer.vue'
import CreateOrderModal from '../components/CreateOrderModal.vue'
import CreateOrderManualModal from '../components/CreateOrderManualModal.vue'

const authStore = useAuthStore()
const role = computed(() => authStore.userRole)
const userName = computed(() => authStore.user?.name || 'User')

const { orders, selectedOrder, routeInfo, selectOrder, clearSelection, cancelOrder } = useOrders()
const { drivers, activeDrivers } = useDrivers()
const { toasts, showToast } = useToast()
const { isDriverEnRoute, initDashboardMap, updateMapMarkers, focusDriver, destroyMap } = useDashboardMap()
const { startPolling, stopPolling } = useRealtimeSync()

const stats = ref({ totalClients: 0, totalDrivers: 0, activeOrders: 0, balance: 0, fleetBalance: 0 })
const loading = ref(true)
const viewMode = ref('map')
const showCreateOrder = ref(false)
const showCreateOrderManual = ref(false)
const clientZones = ref([])
const hasZones = computed(() => clientZones.value.length > 0)

const pendingOrders = computed(() => {
  const now = new Date()
  return orders.value.filter(o => o.status === 'pendiente' && o.scheduled_at && (() => {
    const p = o.scheduled_at.split(/[- :]/)
    return new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]), parseInt(p[3]), parseInt(p[4]), parseInt(p[5] || 0)) > now
  })())
})
const scheduledOrders = computed(() => orders.value.filter(o => o.status === 'publicado'))
const activeOrdersList = computed(() => orders.value.filter(o => ['tomado', 'arribado', 'en_camino'].includes(o.status)))

const activeStatuses = ['publicado', 'tomado', 'arribado', 'en_camino']
const countActive = () => orders.value.filter(o => activeStatuses.includes(o.status)).length

const fetchDashboardData = async () => {
  loading.value = true
  try {
    const ordersRes = await api.get('/orders')
    if (ordersRes.data.status) { orders.value = ordersRes.data.data; stats.value.activeOrders = countActive() }
    if (role.value === 'superadmin') {
      const clientsRes = await api.get('/clients')
      stats.value.totalClients = clientsRes.data.data.length
      stats.value.balance = clientsRes.data.data.reduce((a, c) => a + (parseFloat(c.credits_balance) || 0), 0)
    } else if (role.value === 'client_admin') {
      const [driversRes, geofencesRes] = await Promise.all([api.get('/drivers'), api.get('/geofences')])
      drivers.value = driversRes.data.data
      stats.value.totalDrivers = drivers.value.length
      stats.value.fleetBalance = drivers.value.reduce((a, d) => a + (parseFloat(d.balance) || 0), 0)
      clientZones.value = geofencesRes.data?.data ?? []
      const meRes = await api.get('/auth/me')
      stats.value.balance = parseFloat(meRes.data.data.client_balance) || 0
      if (viewMode.value === 'map') {
        await nextTick()
        setTimeout(() => initDashboardMap({ orders: orders.value, drivers: drivers.value, isDriverEnRoute: d => isDriverEnRoute(d, orders.value) }), 800)
      }
    }
  } catch (e) { console.error('Error fetching dashboard data:', e) }
  finally { loading.value = false }
}

const mapCtx = () => ({
  drivers: drivers.value, orders: orders.value,
  isDriverEnRoute: d => isDriverEnRoute(d, orders.value),
  selectedOrder: selectedOrder.value, clearSelection, showToast
})

const onOrderCreated = (newOrder) => {
  orders.value = [newOrder, ...orders.value]
  stats.value.activeOrders = countActive()
  updateMapMarkers(mapCtx())
}

const handleSelectOrder = async (order) => {
  await selectOrder(order, { drivers: drivers.value, createDriverMapIcon: undefined, redrawDrivers: () => updateMapMarkers(mapCtx()) })
}

const handleCancelOrder = async () => { await cancelOrder({ showToast, clearSelection, fetchDashboardData }) }

const handleFocusDriver = (driver) => { focusDriver(driver, { orders: orders.value, drivers: drivers.value, isDriverEnRoute: d => isDriverEnRoute(d, orders.value) }) }

onMounted(() => {
  fetchDashboardData()
  startPolling({ role, orders, drivers, stats, showToast, updateMapMarkers: () => updateMapMarkers(mapCtx()) })
})

onUnmounted(() => { stopPolling(); destroyMap() })
</script>


<template>
  <div class="dashboard">
    <header class="dashboard-header" v-if="role === 'superadmin' || viewMode === 'stats'">
      <div class="welcome">
        <h1>¡Bienvenido de nuevo, {{ userName }}! 👋</h1>
        <p>Esto es lo que está pasando en tu plataforma hoy.</p>
      </div>
    </header>

    <StatsGrid
      v-if="role === 'superadmin' || viewMode === 'stats'"
      :stats="stats"
      :role="role"
    />

    <div v-if="role === 'client_admin' && viewMode === 'map'" class="dashboard-map-view">
      <div class="map-ambient-strip" aria-hidden="true"></div>
      <div class="map-command-bar">
        <div class="map-command-bar__user">
          <span class="map-command-bar__greeting">Panel operativo</span>
          <span class="map-command-bar__name">{{ userName }}</span>
        </div>
        <div class="map-command-bar__chips">
          <span class="stat-chip stat-chip--queue"><span class="stat-chip__dot"></span>{{ stats.activeOrders }} en cola</span>
          <span class="stat-chip stat-chip--fleet">{{ stats.totalDrivers }} conductores</span>
          <span class="stat-chip stat-chip--balance">Saldo ${{ stats.balance.toFixed(2) }}</span>
        </div>
      </div>
      <div class="dashboard-map-container dashboard-map-container--row">
        <OrdersSidebar
          :pending-orders="pendingOrders"
          :scheduled-orders="scheduledOrders"
          :active-orders-list="activeOrdersList"
          :selected-order="selectedOrder"
          :drivers="drivers"
          @select-order="handleSelectOrder"
        />

        <DashboardMap
          :stats="stats"
          :has-zones="hasZones"
          @create-order="showCreateOrder = true"
          @create-order-manual="showCreateOrderManual = true"
        />

        <OrderDetailPanel
          :selected-order="selectedOrder"
          :route-info="routeInfo"
          @close="clearSelection"
          @cancel="handleCancelOrder"
        />



        <FleetSidebar
          :active-drivers="activeDrivers"
          :is-driver-en-route="(driver) => isDriverEnRoute(driver, orders)"
          @focus-driver="handleFocusDriver"
        />
      </div>
    </div>

    <ToastContainer :toasts="toasts" />

    <CreateOrderModal
      v-if="showCreateOrder"
      @close="showCreateOrder = false"
      @created="onOrderCreated"
    />

    <CreateOrderManualModal
      v-if="showCreateOrderManual"
      @close="showCreateOrderManual = false"
      @created="onOrderCreated"
    />
  </div>
</template>

<style scoped>
.dashboard { display: flex; flex-direction: column; height: calc(100vh - var(--topbar-height)); }
.dashboard-header, .stats-grid { flex-shrink: 0; }
.dashboard-header { padding: 1.5rem 1.5rem 0; }
.dashboard-header .welcome h1 { font-size: 1.35rem; font-weight: 800; margin: 0 0 0.25rem; color: #0f172a; }
.dashboard-header .welcome p { margin: 0; color: #64748b; font-size: 0.9rem; }
.dashboard-map-view { flex: 1; display: flex; flex-direction: column; min-height: 0; position: relative; }
.map-ambient-strip { position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg,#6366F1,#8B5CF6,#EC4899); z-index: 10; }
.map-command-bar { display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 1.25rem; background: #fff; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
.map-command-bar__user { display: flex; align-items: center; gap: 0.5rem; }
.map-command-bar__greeting { font-size: 0.8rem; color: #64748b; font-weight: 500; }
.map-command-bar__name { font-size: 0.9rem; font-weight: 700; color: #0f172a; }
.map-command-bar__chips { display: flex; gap: 0.5rem; }
.stat-chip { padding: 0.3rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 0.35rem; }
.stat-chip--queue { background: #FEF3C7; color: #92400E; }
.stat-chip--fleet { background: #DBEAFE; color: #1E40AF; }
.stat-chip--balance { background: #D1FAE5; color: #065F46; }
.stat-chip__dot { width: 6px; height: 6px; border-radius: 50%; background: #F59E0B; animation: pulse-dot 2s infinite; }
@keyframes pulse-dot { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }
.dashboard-map-container--row { flex: 1; display: flex; min-height: 0; position: relative; }
</style>
