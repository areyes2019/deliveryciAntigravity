<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'
import TopBar from './components/TopBar.vue'

const authStore = useAuthStore()
const route = useRoute()

const isMobileView = computed(() => {
  return route.name === 'driver-app' || route.name === 'driver-simulator'
})
</script>

<template>
  <div id="app" :class="{ 'admin-layout': authStore.isAuthenticated && !isMobileView }">
    <div class="main-wrapper">
      <TopBar v-if="authStore.isAuthenticated && !isMobileView" />
      
      <main class="content-area" :class="{ 'no-padding': isMobileView }">
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

.content-area.no-padding {
  padding: 0;
}

/* Auth views will not have admin-layout class, so they take full screen */
</style>
