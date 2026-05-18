Actúa como un desarrollador Frontend Senior experto en Vue 3 (Vite) y Tailwind CSS v4. Necesito que modifiques la estructura de mi archivo `LoginView.vue` para crear un diseño de pantalla dividida (Split-Screen), utilizando Tailwind v4 ÚNICAMENTE para el layout exterior, pero respetando intactos los estilos y clases actuales del formulario.

Aquí tienes mi código fuente actual:

=========================================
CÓDIGO FUENTE LoginView.vue:
=========================================
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
  background-color: var(--bg-app);
}
.login-panel {
  width: 100%;
  max-width: 440px;
  padding: 3.5rem 3rem;
  background-color: var(--bg-panel);
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
=========================================

REQUISITOS OBLIGATORIOS DE REESTRUCTURACIÓN:
1. Nuevo Contenedor Padre: Crea un contenedor raíz utilizando Tailwind v4 que ocupe `min-h-screen w-full`. Configúralo como un grid o flexbox responsivo: en pantallas medianas/grandes (`md:`) se dividirá en dos columnas (50% izquierda, 50% derecha). En móviles, solo se mostrará la columna de la derecha (el formulario).
2. Columna Izquierda (Imagen): Debe ser exactamente un `<div class="login-wrapper">`. Dentro de este div SOLO debe renderizarse la imagen del banner: `<img src="/banner.png" class="w-full h-full object-cover" />`. No incluyas ningún texto, título o subtítulo aquí dentro. (Nota: Elimina las propiedades de Flexbox y centrado del CSS actual de `.login-wrapper` para que Tailwind v4 controle su tamaño como columna, o adáptalo en el <style scoped>).
3. Columna Derecha (Formulario): Debe contener el div `<div class="login-panel">` centrado vertical y horizontalmente mediante clases de Tailwind v4 en su contenedor contenedor de columna.
4. Preservación Estricta de Estilos del Formulario: NO apliques clases de utilidad de Tailwind a las etiquetas internas del formulario (`<form>`, `<input>`, `<label>`, `<button>`). Deben conservar exactamente sus clases originales (`form-control`, `login-form`, `form-group`, `login-btn`, etc.) y seguir dependiendo al 100% de las reglas declaradas en el bloque `<style scoped>`.
5. Seguridad CSRF: Incluye un input oculto `<input type="hidden" name="csrf_test_name" value="" />` dentro del formulario de manera limpia, respetando la estructura del HTML.

RESTRICCIONES CRÍTICAS:
- NO modifiques absolutamente nada del bloque `<script setup>`. Preserva todas las importaciones, lógica de negocio y variables reactivas.
- No alteres las directivas de Vue en el template (`v-model`, `@submit.prevent`, `v-if`, etc.).
- No crees archivos nuevos.

Devuélveme el código completo del archivo `LoginView.vue`, limpio y listo para usar.