<template>
  <header class="topbar">
    <div class="topbar-left">
      <h2 class="page-title">{{ title }}</h2>
    </div>
    
    <div class="topbar-right">
      <div class="search-box">
        <span class="search-icon">🔍</span>
        <input type="text" placeholder="Search..." class="search-input">
      </div>
      
      <div class="actions">
        <button class="icon-btn">⚙️</button>
        <button class="icon-btn">🔔</button>
      </div>

      <div class="user-profile" @click="handleLogout" title="Click to logout">
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
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const authStore = useAuthStore()

const title = computed(() => {
  if (route.name === 'dashboard') return 'Dashboard'
  return 'Delivery Cloud'
})

const avatarUrl = computed(() => {
  const name = authStore.userName.replace(' ', '+')
  return `https://ui-avatars.com/api/?name=${name}&background=6366f1&color=fff&bold=true`
})

const handleLogout = () => {
    if(confirm('Are you sure you want to log out?')){
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

.page-title {
  font-size: 1.25rem;
  font-weight: 600;
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
