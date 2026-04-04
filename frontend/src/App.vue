<script setup>
import { useAuthStore } from './stores/auth'
import Sidebar from './components/Sidebar.vue'
import TopBar from './components/TopBar.vue'

const authStore = useAuthStore()
</script>

<template>
  <div id="app" :class="{ 'admin-layout': authStore.isAuthenticated }">
    <Sidebar v-if="authStore.isAuthenticated" />
    
    <div class="main-wrapper">
      <TopBar v-if="authStore.isAuthenticated" />
      
      <main class="content-area">
        <router-view />
      </main>
    </div>
  </div>
</template>

<style>
/* Base app structure */
.admin-layout {
  display: flex;
  min-height: 100vh;
}

.main-wrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.admin-layout .main-wrapper {
  margin-left: var(--sidebar-width);
}

.content-area {
  flex: 1;
  padding: 2rem;
  background-color: var(--bg-app);
}

/* Auth views will not have admin-layout class, so they take full screen */
</style>
