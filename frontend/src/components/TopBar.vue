<template>
  <header class="topbar">
    <div class="topbar-left">
      <div class="brand">
        <span class="logo-icon">🚀</span>
        <h2 class="page-title">DeliveryCloud</h2>
      </div>

      <nav class="top-nav">
        <router-link 
          v-for="item in menuItems" 
          :key="item.path" 
          :to="item.path" 
          class="nav-link" 
          active-class="active" 
          exact>
          <span class="icon">{{ item.icon }}</span> <span class="nav-text">{{ item.name }}</span>
        </router-link>
      </nav>
    </div>
    
    <div class="topbar-right">
      <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="Buscar..." class="search-input">
      </div>
      
      <div v-if="clientBalance !== null" class="credits-badge" title="Viajes Disponibles">
         <span>📦 {{ clientBalance }} Viajes</span>
      </div>
      
      <div class="actions">
        <button class="icon-btn">⚙️</button>
        <button class="icon-btn">🔔</button>
      </div>

      <div class="user-profile" @click="handleLogout" title="Haz clic para cerrar sesión">
        <div class="avatar">
          <img :src="avatarUrl" alt="Avatar">
        </div>
        <div class="user-info">
          <span class="user-name">{{ authStore.userName }}</span>
          <span class="dropdown-icon">▼</span>
        </div>
      </div>
    </div>
  </header>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import api from '../api'

const route = useRoute()
const authStore = useAuthStore()

const clientBalance = ref(null)

const fetchBalance = async () => {
    if (authStore.userRole === 'client_admin') {
        try {
            const res = await api.get('/auth/me')
            if (res.data.status) {
                const totalMoney = parseFloat(res.data.data.client_balance) || 0;
                const costPerTrip = parseFloat(res.data.data.cost_per_trip) || 1;
                
                if (costPerTrip > 0) {
                    clientBalance.value = Math.floor(totalMoney / costPerTrip);
                } else {
                    clientBalance.value = totalMoney;
                }
            }
        } catch(e) {
            console.error(e)
        }
    }
}

onMounted(() => {
    fetchBalance()
})

const menuItems = computed(() => {
  const role = authStore.userRole
  const items = [
    { name: 'Panel', path: '/', icon: '📊', roles: ['superadmin', 'client_admin'] },
    { name: 'Clientes', path: '/clients', icon: '🏢', roles: ['superadmin'] },
    { name: 'Conductores', path: '/drivers', icon: '🏎️', roles: ['client_admin'] },
    { name: 'Envíos', path: '/orders', icon: '📦', roles: ['client_admin'] },
    { name: 'Precios', path: '/pricing', icon: '💲', roles: ['client_admin'] },
    { name: 'Reportes', path: '/reports', icon: '📈', roles: ['superadmin', 'client_admin'] },
  ]
  return items.filter(item => item.roles.includes(role))
})

const avatarUrl = computed(() => {
  const name = authStore.userName.replace(' ', '+')
  return `https://ui-avatars.com/api/?name=${name}&background=6366f1&color=fff&bold=true`
})

const handleLogout = () => {
    if(confirm('¿Estás seguro de que quieres cerrar sesión?')){
        authStore.logout()
    }
}
</script>

<style scoped>
.topbar {
  height: var(--topbar-height);
  background-color: var(--bg-panel);
  border-bottom: 1px solid var(--border-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 2rem;
  position: sticky;
  top: 0;
  z-index: 90;
}

.topbar-left {
  display: flex;
  align-items: center;
  gap: 2rem;
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.logo-icon {
  font-size: 1.5rem;
}

.page-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--primary);
  letter-spacing: -0.02em;
  margin: 0;
}

.top-nav {
  display: flex;
  gap: 0.25rem;
  align-items: center;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.8rem;
  border-radius: var(--radius-sm);
  color: var(--text-muted);
  font-weight: 500;
  font-size: 0.9rem;
  transition: all 0.2s;
  text-decoration: none;
}

.nav-link:hover {
  background-color: var(--bg-app);
  color: var(--text-main);
}

.nav-link.active {
  background-color: var(--primary);
  color: #fff;
}

.nav-link.active .icon {
  opacity: 1;
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 1.5rem;
}

.search-box {
  display: flex;
  align-items: center;
  background: var(--bg-app);
  border-radius: 999px;
  padding: 0.5rem 1rem;
  border: 1px solid var(--border-light);
  width: 250px;
}

.credits-badge {
  background: #F0FDF4;
  color: #166534;
  border: 1px solid #86EFAC;
  padding: 0.4rem 0.8rem;
  border-radius: 999px;
  font-weight: 700;
  font-size: 0.85rem;
  display: flex;
  align-items: center;
}

.search-input {
  border: none;
  background: transparent;
  outline: none;
  margin-left: 0.5rem;
  font-size: 0.9rem;
  width: 100%;
}

.actions {
  display: flex;
  gap: 0.5rem;
}

.icon-btn {
  background: none;
  border: none;
  font-size: 1.2rem;
  cursor: pointer;
  padding: 0.25rem;
  border-radius: 50%;
  transition: background 0.2s;
}

.icon-btn:hover {
  background: var(--bg-app);
}

.user-profile {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding-left: 1.5rem;
  border-left: 1px solid var(--border-light);
  cursor: pointer;
  transition: opacity 0.2s;
}

.user-profile:hover {
    opacity: 0.8;
}

.avatar img {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  object-fit: cover;
}

.user-info {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.user-name {
  font-weight: 500;
  font-size: 0.95rem;
  color: var(--text-main);
}

.dropdown-icon {
  font-size: 0.65rem;
  color: var(--text-muted);
}
</style>
