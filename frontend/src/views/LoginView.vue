<template>
  <div class="login-wrapper">
    <div class="login-panel">
      <div class="login-header">
        <div class="logo-box">
          <span class="logo-icon">📦</span>
          <h2>DeliveryCloud</h2>
        </div>
        <p class="subtitle">Welcome back! Please enter your details.</p>
      </div>

      <form @submit.prevent="handleLogin" class="login-form">
        <div class="form-group">
          <label>Email Address</label>
          <input 
            type="email" 
            v-model="email" 
            class="form-control" 
            placeholder="admin@delivery.com"
            required
          />
        </div>
        
        <div class="form-group">
          <label>Password</label>
          <input 
            type="password" 
            v-model="password" 
            class="form-control" 
            placeholder="••••••••"
            required
          />
        </div>

        <div class="form-options">
          <label class="remember-me">
            <input type="checkbox" /> Remember me
          </label>
          <a href="#" class="forgot-link">Forgot password?</a>
        </div>

        <div v-if="errorMessage" class="error-msg">
          {{ errorMessage }}
        </div>

        <button type="submit" class="btn btn-primary login-btn" :disabled="isLoading">
          {{ isLoading ? 'Authenticating...' : 'Sign In' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const email = ref('')
const password = ref('')
const errorMessage = ref('')
const isLoading = ref(false)

const router = useRouter()
const authStore = useAuthStore()

const handleLogin = async () => {
  isLoading.value = true
  errorMessage.value = ''
  
  const result = await authStore.login(email.value, password.value)
  
  if (result.success) {
    router.push('/')
  } else {
    errorMessage.value = result.message
  }
  
  isLoading.value = false
}
</script>

<style scoped>
.login-wrapper {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 100vh;
  width: 100%;
  background-color: var(--bg-app); /* light gray */
}

.login-panel {
  width: 100%;
  max-width: 440px;
  padding: 3.5rem 3rem;
  background-color: var(--bg-panel); /* pure white */
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-md);
  border: 1px solid var(--border-light);
}

.login-header {
  text-align: center;
  margin-bottom: 2.5rem;
}

.logo-box {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.logo-icon {
  font-size: 2rem;
}

.logo-box h2 {
  font-size: 1.8rem;
  font-weight: 700;
  color: var(--text-main);
}

.subtitle {
  color: var(--text-muted);
  font-size: 0.95rem;
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.form-group label {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text-main);
}

.form-options {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 0.85rem;
  margin-top: -0.5rem;
}

.remember-me {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  color: var(--text-muted);
  cursor: pointer;
}

.forgot-link {
  color: var(--primary);
  font-weight: 500;
}

.forgot-link:hover {
  text-decoration: underline;
}

.login-btn {
  width: 100%;
  padding: 12px;
  font-size: 1rem;
  font-weight: 600;
  height: 48px;
}

.login-btn:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.error-msg {
  color: #DC2626;
  background: #FEE2E2;
  padding: 0.75rem;
  border-radius: var(--radius-sm);
  font-size: 0.9rem;
  text-align: center;
  border: 1px solid #F87171;
}
</style>
