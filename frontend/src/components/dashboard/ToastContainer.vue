<template>
  <div class="toast-container">
    <transition-group name="toast">
      <div v-for="toast in toasts" :key="toast.id" class="toast-message" :class="toast.type">
        {{ toast.message }}
      </div>
    </transition-group>
  </div>
</template>

<script setup>
import { useToast } from '../../composables/useToast'

const { toasts } = useToast()
</script>

<style scoped>
.toast-container {
    position: fixed; top: 1.5rem; right: 1.5rem; 
    z-index: 2000; display: flex; flex-direction: column; gap: 0.75rem;
}
.toast-message {
    padding: 1rem 1.5rem; border-radius: 12px; background: #1F2937; color: white;
    box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); 
    font-weight: 600; font-size: 0.9rem; min-width: 280px;
    border-left: 5px solid #6366F1;
    animation: slideInLeft 0.3s ease-out;
}
.toast-message.success { border-left-color: #10B981; }
.toast-message.error { border-left-color: #EF4444; }

@keyframes slideInLeft {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}
</style>
