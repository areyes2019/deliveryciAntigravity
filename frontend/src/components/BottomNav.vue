<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()

const showLogoutDialog = ref(false)
const isLoggingOut     = ref(false)

const isActive = (routeName) => route.name === routeName

const goHome   = () => router.push({ name: 'driver-app' })
const goWallet = () => router.push({ name: 'wallet' })

const handleUser = () => {
  if (auth.isAuthenticated) {
    showLogoutDialog.value = true
  } else {
    router.push({ name: 'login' })
  }
}

const cancelLogout = () => {
  showLogoutDialog.value = false
}

const confirmLogout = async () => {
  isLoggingOut.value = true
  await auth.logout()          // goes offline + clears session
  isLoggingOut.value = false
  showLogoutDialog.value = false
}
</script>

<template>

  <!-- ── Logout confirmation dialog ──────────────────────────── -->
  <Transition name="dialog-fade">
    <div
      v-if="showLogoutDialog"
      class="dialog-backdrop"
      @click.self="cancelLogout"
    >
      <Transition name="dialog-pop">
        <div class="dialog-card">

          <!-- Icon -->
          <div class="dialog-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6
                       a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
          </div>

          <!-- Text -->
          <h2 class="dialog-title">¿Cerrar sesión?</h2>
          <p class="dialog-body">
            Tu estado cambiará a&nbsp;<strong>offline</strong> y
            dejarás de recibir viajes.
          </p>

          <!-- Actions -->
          <div class="dialog-actions">
            <button class="dialog-btn dialog-btn--cancel" @click="cancelLogout">
              Cancelar
            </button>
            <button
              class="dialog-btn dialog-btn--confirm"
              :disabled="isLoggingOut"
              @click="confirmLogout"
            >
              <svg v-if="isLoggingOut" class="dialog-spinner" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"/>
                <path fill="currentColor" class="opacity-75"
                      d="M4 12a8 8 0 018-8v8H4z"/>
              </svg>
              <span>{{ isLoggingOut ? 'Saliendo…' : 'Sí, salir' }}</span>
            </button>
          </div>

        </div>
      </Transition>
    </div>
  </Transition>

  <!-- ── Bottom nav bar ───────────────────────────────────────── -->
  <nav class="bottom-nav">
    <div class="bottom-nav__bar">

      <!-- Home -->
      <button
        class="nav-btn"
        :class="{ 'nav-btn--active': isActive('driver-app') }"
        aria-label="Inicio"
        @click="goHome"
      >
        <span class="nav-btn__icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3
                     m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
        </span>
        <span class="nav-btn__label">Inicio</span>
        <span v-if="isActive('driver-app')" class="nav-btn__dot"></span>
      </button>

      <!-- Wallet — elevated center button -->
      <div class="nav-center-wrap">
        <button
          class="nav-btn nav-btn--center"
          :class="{ 'nav-btn--center-active': isActive('wallet') }"
          aria-label="Billetera"
          @click="goWallet"
        >
          <span class="nav-btn__icon nav-btn__icon--center">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2
                       m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0
                       -2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
          </span>
          <span class="nav-btn__label nav-btn__label--center">Billetera</span>
        </button>
      </div>

      <!-- User / Logout -->
      <button
        class="nav-btn"
        :class="{ 'nav-btn--active': isActive('profile') }"
        :aria-label="auth.isAuthenticated ? 'Cerrar sesión' : 'Iniciar sesión'"
        @click="handleUser"
      >
        <span class="nav-btn__icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
          </svg>
        </span>
        <span class="nav-btn__label">
          {{ auth.isAuthenticated ? 'Salir' : 'Entrar' }}
        </span>
        <span v-if="isActive('profile')" class="nav-btn__dot"></span>
      </button>

    </div>
  </nav>
</template>

<style scoped>
/* ── Dialog backdrop ────────────────────────────────────────── */
.dialog-backdrop {
  position: absolute;
  inset: 0;
  z-index: 100;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  padding-bottom: 96px;         /* clears the nav bar */
  padding-left: 20px;
  padding-right: 20px;
}

/* ── Dialog card ────────────────────────────────────────────── */
.dialog-card {
  width: 100%;
  background: #ffffff;
  border-radius: 28px;
  padding: 32px 28px 28px;
  box-shadow: 0 24px 64px rgba(0, 0, 0, 0.25);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}

/* ── Icon bubble ────────────────────────────────────────────── */
.dialog-icon {
  width: 64px;
  height: 64px;
  border-radius: 20px;
  background: #FEF2F2;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 20px;
  color: #EF4444;
}

.dialog-icon svg {
  width: 32px;
  height: 32px;
}

/* ── Text ───────────────────────────────────────────────────── */
.dialog-title {
  font-family: 'Outfit', sans-serif;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  margin-bottom: 8px;
}

.dialog-body {
  font-size: 14px;
  color: #6B7280;
  line-height: 1.6;
  margin-bottom: 28px;
}

/* ── Buttons ────────────────────────────────────────────────── */
.dialog-actions {
  display: flex;
  gap: 12px;
  width: 100%;
}

.dialog-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 16px;
  border-radius: 16px;
  border: none;
  font-size: 15px;
  font-weight: 700;
  cursor: pointer;
  transition: transform 0.12s, opacity 0.12s;
  touch-action: manipulation;
  -webkit-user-select: none;
  user-select: none;
}

.dialog-btn:active { transform: scale(0.96); }

.dialog-btn--cancel {
  background: #F3F4F6;
  color: #374151;
}

.dialog-btn--confirm {
  background: #EF4444;
  color: #ffffff;
  box-shadow: 0 6px 20px rgba(239, 68, 68, 0.4);
}

.dialog-btn--confirm:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

/* ── Spinner ────────────────────────────────────────────────── */
.dialog-spinner {
  width: 18px;
  height: 18px;
  animation: spin 0.7s linear infinite;
  flex-shrink: 0;
}

@keyframes spin { to { transform: rotate(360deg); } }

/* ── Transitions ────────────────────────────────────────────── */
.dialog-fade-enter-active, .dialog-fade-leave-active {
  transition: opacity 0.2s ease;
}
.dialog-fade-enter-from, .dialog-fade-leave-to { opacity: 0; }

.dialog-pop-enter-active {
  transition: transform 0.28s cubic-bezier(0.34, 1.15, 0.64, 1), opacity 0.2s ease;
}
.dialog-pop-leave-active {
  transition: transform 0.18s ease, opacity 0.18s ease;
}
.dialog-pop-enter-from { transform: translateY(32px) scale(0.95); opacity: 0; }
.dialog-pop-leave-to   { transform: translateY(16px) scale(0.97); opacity: 0; }

/* ── Bottom nav ─────────────────────────────────────────────── */
.bottom-nav {
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: 50;
}

.bottom-nav__bar {
  display: flex;
  align-items: center;
  justify-content: space-around;
  height: 72px;
  padding-left: 8px;
  padding-right: 8px;
  padding-bottom: env(safe-area-inset-bottom, 0px);
  background: rgba(255, 255, 255, 0.88);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-top: 1px solid rgba(0, 0, 0, 0.07);
  box-shadow: 0 -4px 24px rgba(0, 0, 0, 0.10);
}

.nav-btn {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  flex: 1;
  height: 100%;
  min-width: 44px;
  background: none;
  border: none;
  cursor: pointer;
  color: #9CA3AF;
  transition: color 0.15s, transform 0.12s;
  touch-action: manipulation;
  -webkit-user-select: none;
  user-select: none;
}

.nav-btn:active         { transform: scale(0.91); }
.nav-btn--active        { color: #2563EB; }

.nav-btn__icon svg      { width: 24px; height: 24px; display: block; }

.nav-btn__label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.02em;
  line-height: 1;
}

.nav-btn__dot {
  position: absolute;
  bottom: 6px;
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: #2563EB;
}

.nav-center-wrap {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-top: -28px;
}

.nav-btn--center {
  width: 64px;
  height: 64px;
  flex: none;
  border-radius: 50%;
  background: #2563EB;
  color: #ffffff;
  box-shadow: 0 6px 24px rgba(37, 99, 235, 0.50);
  transition: background 0.15s, transform 0.12s, box-shadow 0.15s;
  gap: 0;
}

.nav-btn--center:active {
  transform: scale(0.90);
  box-shadow: 0 3px 12px rgba(37, 99, 235, 0.40);
}

.nav-btn--center-active {
  background: #1d4ed8;
  box-shadow: 0 6px 28px rgba(29, 78, 216, 0.60);
}

.nav-btn__icon--center svg  { width: 28px; height: 28px; }

.nav-btn__label--center {
  font-size: 9px;
  margin-top: 2px;
  color: rgba(255, 255, 255, 0.85);
}
</style>
