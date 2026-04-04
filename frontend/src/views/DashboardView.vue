<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../api'

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

const fetchDashboardData = async () => {
  loading.value = true
  try {
    if (role.value === 'superadmin') {
      const clientsRes = await api.get('/api/v1/clients')
      stats.value.totalClients = clientsRes.data.data.length
      // Calculate total credits in system
      stats.value.balance = clientsRes.data.data.reduce((acc, c) => acc + parseFloat(c.credits_balance), 0)
      recentActivity.value = clientsRes.data.data.slice(0, 5).map(c => ({
        id: c.id,
        title: `New Client: ${c.business_name}`,
        subtitle: `Admin: ${c.admin_name}`,
        time: 'Recently',
        icon: '🏢'
      }))
    } else if (role.value === 'client_admin') {
      const driversRes = await api.get('/api/v1/drivers')
      stats.value.totalDrivers = driversRes.data.data.length
      // In a real app, we'd get the current client's balance from a specific profile endpoint
      // For now, we use a placeholder or the first client record if we could find it
      stats.value.balance = authStore.user?.client_balance || 0
      recentActivity.value = driversRes.data.data.slice(0, 5).map(d => ({
        id: d.id,
        title: `Driver Update: ${d.name}`,
        subtitle: d.vehicle_details,
        time: 'Just now',
        icon: '🏎️'
      }))
    }
  } catch (error) {
    console.error('Error fetching dashboard data:', error)
  } finally {
    loading.value = false
  }
}

onMounted(fetchDashboardData)
</script>

<template>
  <div class="dashboard">
    <header class="dashboard-header">
      <div class="welcome">
        <h1>Welcome back, {{ userName }}! 👋</h1>
        <p>Here's what's happening with your delivery platform today.</p>
      </div>
    </header>

    <!-- Stats Grid -->
    <div class="stats-grid">
      <div class="stat-card" v-if="role === 'superadmin'">
        <div class="stat-icon clients">🏢</div>
        <div class="stat-info">
          <p class="stat-label">Total Clients</p>
          <h3 class="stat-value">{{ stats.totalClients }}</h3>
        </div>
      </div>

      <div class="stat-card" v-if="role === 'client_admin'">
        <div class="stat-icon drivers">🏎️</div>
        <div class="stat-info">
          <p class="stat-label">My Drivers</p>
          <h3 class="stat-value">{{ stats.totalDrivers }}</h3>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon orders">📦</div>
        <div class="stat-info">
          <p class="stat-label">Active Orders</p>
          <h3 class="stat-value">{{ stats.activeOrders }}</h3>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-icon balance">💰</div>
        <div class="stat-info">
          <p class="stat-label">{{ role === 'superadmin' ? 'Total System Credits' : 'Wallet Balance' }}</p>
          <h3 class="stat-value">${{ stats.balance }}</h3>
        </div>
      </div>
    </div>

    <!-- Main Content Area -->
    <div class="dashboard-content">
      <!-- Recent Activity -->
      <section class="content-card">
        <div class="card-header">
          <h2>Recent Activity</h2>
          <button class="btn-text">View All</button>
        </div>
        <div class="activity-list" v-if="recentActivity.length > 0">
          <div v-for="item in recentActivity" :key="item.id" class="activity-item">
            <div class="activity-icon">{{ item.icon }}</div>
            <div class="activity-details">
              <h4>{{ item.title }}</h4>
              <p>{{ item.subtitle }}</p>
            </div>
            <span class="activity-time">{{ item.time }}</span>
          </div>
        </div>
        <div v-else class="empty-state">
           <p>No recent activity found.</p>
        </div>
      </section>

      <!-- Quick Actions -->
      <aside class="quick-actions">
        <h3>Quick Actions</h3>
        <div class="actions-grid">
          <router-link v-if="role === 'superadmin'" to="/clients" class="action-btn">
            <span>➕</span> Create Client
          </router-link>
          <router-link v-if="role === 'client_admin'" to="/drivers" class="action-btn">
            <span>➕</span> Add Driver
          </router-link>
          <router-link to="/reports" class="action-btn">
            <span>📈</span> View Reports
          </router-link>
        </div>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.dashboard {
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

.dashboard-header h1 {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--text-main);
  margin-bottom: 0.25rem;
}

.dashboard-header p {
  color: var(--text-muted);
  font-size: 0.95rem;
}

/* Stats Grid */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1.5rem;
}

.stat-card {
  background: white;
  padding: 1.5rem;
  border-radius: 12px;
  border: 1px solid var(--border-light);
  display: flex;
  align-items: center;
  gap: 1.25rem;
  transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
}

.stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
}

.stat-icon.clients { background: #E0F2FE; }
.stat-icon.drivers { background: #F0F9FF; }
.stat-icon.orders { background: #FEF3C7; }
.stat-icon.balance { background: #DCFCE7; }

.stat-label {
  font-size: 0.875rem;
  color: var(--text-muted);
  margin-bottom: 0.25rem;
}

.stat-value {
  font-size: 1.5rem;
  font-weight: 700;
  color: var(--text-main);
}

/* Content Area */
.dashboard-content {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 1.5rem;
}

.content-card {
  background: white;
  border-radius: 12px;
  border: 1px solid var(--border-light);
  padding: 1.5rem;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.card-header h2 {
  font-size: 1.1rem;
  font-weight: 600;
}

.activity-list {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.activity-item {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.75rem;
  border-radius: 8px;
  transition: background 0.2s;
}

.activity-item:hover {
  background-color: var(--bg-app);
}

.activity-icon {
  font-size: 1.25rem;
}

.activity-details h4 {
  font-size: 0.95rem;
  font-weight: 500;
  margin-bottom: 0.1rem;
}

.activity-details p {
  font-size: 0.8rem;
  color: var(--text-muted);
}

.activity-time {
  margin-left: auto;
  font-size: 0.75rem;
  color: var(--text-light);
}

.quick-actions h3 {
  font-size: 1rem;
  font-weight: 600;
  margin-bottom: 1rem;
}

.actions-grid {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.action-btn {
  background: white;
  border: 1px solid var(--border-light);
  padding: 1rem;
  border-radius: 10px;
  text-decoration: none;
  color: var(--text-main);
  font-weight: 500;
  font-size: 0.9rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  transition: all 0.2s;
}

.action-btn:hover {
  background-color: var(--primary);
  color: white;
  border-color: var(--primary);
}

.empty-state {
  text-align: center;
  padding: 3rem;
  color: var(--text-muted);
}

@media (max-width: 768px) {
  .dashboard-content {
    grid-template-columns: 1fr;
  }
}
</style>
