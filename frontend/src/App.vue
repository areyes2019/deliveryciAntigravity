<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'
import TopBar from './components/TopBar.vue'
import BottomNav from './components/BottomNav.vue'

const authStore = useAuthStore()
const route = useRoute()

const isAppView = computed(() => {
  return ['driver-app', 'driver-simulator', 'wallet', 'profile'].includes(route.name)
})

const isAdminView = computed(() => {
  return authStore.isAuthenticated && !isAppView.value && route.name !== 'login'
})
</script>

<template>
  <div class="h-screen w-screen bg-gray-100 lg:bg-white" :class="{ 'flex items-center justify-center': !isAdminView }">
    <!-- Mobile Container (driver PWA) -->
    <div
      v-if="isAppView"
      class="w-full h-screen max-w-md rounded-none lg:rounded-3xl shadow-2xl relative overflow-hidden"
    >
      <Transition name="fade-slide" mode="out-in">
        <router-view :key="route.fullPath" />
      </Transition>
      <BottomNav />
    </div>

    <!-- Admin Layout (TopBar + content) -->
    <div v-else-if="isAdminView" class="w-full h-full flex flex-col">
      <TopBar />
      <router-view />
    </div>

    <!-- Guest Views (login) -->
    <div v-else class="w-full h-full flex items-center justify-center">
      <router-view />
    </div>
  </div>
</template>

<style scoped>
/* Smooth scrolling */
main {
  scroll-behavior: smooth;
  -webkit-overflow-scrolling: touch;
}

/* Hide scrollbar but keep functionality */
main::-webkit-scrollbar {
  width: 0;
  background: transparent;
}

/* Page transitions */
.fade-slide-enter-active,
.fade-slide-leave-active {
  transition: all 0.3s ease;
}

.fade-slide-enter-from {
  opacity: 0;
  transform: translateY(10px);
}

.fade-slide-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.fade-slide-enter-to,
.fade-slide-leave-from {
  opacity: 1;
  transform: translateY(0);
}
</style>
