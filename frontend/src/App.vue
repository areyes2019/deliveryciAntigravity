<script setup>
import { useAuthStore } from './stores/auth'
import TopBar from './components/TopBar.vue'

const authStore = useAuthStore()
</script>

<template>
  <div id="app" :class="{ 'admin-layout': authStore.isAuthenticated }">
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

.content-area {
  flex: 1;
  padding: 1.5rem;
  background-color: var(--bg-app);
}

/* Auth views will not have admin-layout class, so they take full screen */
</style>
