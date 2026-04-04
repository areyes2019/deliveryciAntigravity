<script setup>
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()

const menuItems = computed(() => {
  const role = authStore.userRole
  const items = [
    { name: 'Dashboard', path: '/', icon: '📊', roles: ['superadmin', 'client_admin'] },
    { name: 'Clients', path: '/clients', icon: '🏢', roles: ['superadmin'] },
    { name: 'Drivers', path: '/drivers', icon: '🏎️', roles: ['client_admin'] },
    { name: 'Orders', path: '/orders', icon: '📦', roles: ['client_admin'] },
    { name: 'Reports', path: '/reports', icon: '📈', roles: ['superadmin', 'client_admin'] },
  ]
  return items.filter(item => item.roles.includes(role))
})
</script>

<template>
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="logo">
        <span class="logo-icon">🚀</span>
        <h2>DeliveryCloud</h2>
      </div>
    </div>
    <div class="sidebar-menu-wrapper">
      <p class="menu-label">Main Menu</p>
      <ul class="nav-menu">
        <li v-for="item in menuItems" :key="item.path">
          <router-link :to="item.path" class="nav-link" active-class="active" exact>
            <span class="icon">{{ item.icon }}</span> {{ item.name }}
          </router-link>
        </li>
      </ul>
    </div>
  </aside>
</template>

<style scoped>
.sidebar {
  width: var(--sidebar-width);
  height: 100vh;
  background-color: var(--bg-panel);
  border-right: 1px solid var(--border-light);
  display: flex;
  flex-direction: column;
  position: fixed;
  left: 0;
  top: 0;
  z-index: 100;
}

.sidebar-header {
  height: var(--topbar-height);
  display: flex;
  align-items: center;
  padding: 0 1.5rem;
  border-bottom: 1px solid var(--border-light);
}

.logo {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.logo-icon {
  font-size: 1.5rem;
}

.logo h2 {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--primary);
  letter-spacing: -0.02em;
}

.sidebar-menu-wrapper {
  padding: 1.5rem 1rem;
}

.menu-label {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--text-light);
  font-weight: 600;
  margin-bottom: 1rem;
  padding-left: 0.5rem;
}

.nav-menu {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.nav-link {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  border-radius: var(--radius-sm);
  color: var(--text-muted);
  font-weight: 500;
  font-size: 0.95rem;
  transition: all 0.2s;
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
</style>
