import { createApp } from 'vue'
import { createPinia } from 'pinia'
import './style.css'
import App from './App.vue'
import router from './router'

// Desregistrar cualquier service worker instalado previamente
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.getRegistrations().then(registrations => {
    registrations.forEach(sw => sw.unregister())
  })
}

const app = createApp(App)

app.use(createPinia())
app.use(router)

app.mount('#app')
