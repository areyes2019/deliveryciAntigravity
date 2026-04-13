<script setup>
import { useAuthStore } from '../stores/auth'
import { useRouter } from 'vue-router'

const authStore = useAuthStore()
const router = useRouter()

const handleLogout = () => {
  authStore.logout()
}
</script>

<template>
  <div class="px-4 pt-8 pb-4 h-screen flex flex-col space-y-6">
    <!-- Header -->
    <div class="animate-fade-in">
      <h2 class="text-3xl font-bold text-gray-900">Mi Perfil</h2>
      <p class="text-sm text-gray-500 mt-1">Información personal</p>
    </div>

    <!-- Profile Card -->
    <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-3xl p-6 border border-blue-100 shadow-sm animate-scale-in">
      <!-- Avatar -->
      <div class="flex justify-center mb-6">
        <div class="w-24 h-24 bg-gradient-to-br from-blue-500 via-blue-600 to-indigo-600 rounded-full flex items-center justify-center text-white shadow-lg relative">
          <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" />
          </svg>
          <!-- Status indicator -->
          <div class="absolute bottom-0 right-0 w-6 h-6 bg-green-500 rounded-full border-3 border-white"></div>
        </div>
      </div>

      <!-- User Info -->
      <div class="text-center">
        <h3 class="text-2xl font-bold text-gray-900 mb-1">{{ authStore.userName }}</h3>
        <p class="text-sm text-gray-600 mb-1 capitalize font-medium">{{ authStore.userRole }}</p>
        <p class="text-xs text-green-600 font-semibold flex items-center justify-center gap-1">
          <span class="w-2 h-2 bg-green-500 rounded-full inline-block"></span>
          Conectado
        </p>
      </div>
    </div>

    <!-- Details Grid -->
    <div class="space-y-3">
      <!-- Email -->
      <div class="bg-white rounded-2xl p-4 border border-gray-100 hover:border-blue-200 hover:shadow-md transition-all duration-300 group">
        <div class="flex items-start gap-3.5">
          <div class="w-12 h-12 bg-blue-50 group-hover:bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors">
            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
          </div>
          <div class="min-w-0">
            <p class="text-xs text-gray-500 font-semibold uppercase tracking-wide">Email</p>
            <p class="text-sm text-gray-900 font-medium break-words mt-1">{{ authStore.user?.email || 'N/A' }}</p>
          </div>
        </div>
      </div>

      <!-- Phone -->
      <div class="bg-white rounded-2xl p-4 border border-gray-100 hover:border-green-200 hover:shadow-md transition-all duration-300 group">
        <div class="flex items-start gap-3.5">
          <div class="w-12 h-12 bg-green-50 group-hover:bg-green-100 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors">
            <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
          </div>
          <div class="min-w-0">
            <p class="text-xs text-gray-500 font-semibold uppercase tracking-wide">Teléfono</p>
            <p class="text-sm text-gray-900 font-medium mt-1">{{ authStore.user?.phone || 'N/A' }}</p>
          </div>
        </div>
      </div>

      <!-- Status -->
      <div class="bg-white rounded-2xl p-4 border border-gray-100 hover:border-purple-200 hover:shadow-md transition-all duration-300 group">
        <div class="flex items-start gap-3.5">
          <div class="w-12 h-12 bg-purple-50 group-hover:bg-purple-100 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors">
            <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div>
            <p class="text-xs text-gray-500 font-semibold uppercase tracking-wide">Estado</p>
            <p class="text-sm text-gray-900 font-medium mt-1">
              <span class="inline-flex items-center gap-2">
                <span class="w-2.5 h-2.5 bg-green-500 rounded-full animate-pulse"></span>
                <span>Activo</span>
              </span>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Stats Section -->
    <div class="grid grid-cols-2 gap-3 pt-2">
      <div class="bg-blue-50 rounded-2xl p-4 border border-blue-100">
        <p class="text-xs text-blue-600 font-semibold mb-2">Viajes completados</p>
        <p class="text-2xl font-bold text-blue-700">342</p>
      </div>
      <div class="bg-amber-50 rounded-2xl p-4 border border-amber-100">
        <p class="text-xs text-amber-600 font-semibold mb-2">Calificación</p>
        <p class="text-2xl font-bold text-amber-700">4.9 ⭐</p>
      </div>
    </div>

    <!-- Spacer -->
    <div class="flex-1"></div>

    <!-- Logout Button -->
    <button
      @click="handleLogout"
      class="w-full bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white font-bold py-4 rounded-2xl transition-all duration-300 active:scale-95 flex items-center justify-center gap-3 shadow-lg hover:shadow-xl group"
    >
      <svg class="w-5 h-5 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
      </svg>
      Cerrar sesión
    </button>
  </div>
</template>

<style scoped>
/* Animations */
@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@keyframes scaleIn {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.animate-fade-in {
  animation: fadeIn 0.4s ease-out;
}

.animate-scale-in {
  animation: scaleIn 0.5s ease-out;
}

button {
  touch-action: manipulation;
  -webkit-user-select: none;
  user-select: none;
}
</style>
