import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  server: {
    allowedHosts: [
      'unalliterative-semimagnetic-tamiko.ngrok-free.dev',
      '.ngrok-free.dev',
      '.ngrok-free.app',
    ],
    proxy: {
      '/api': {
        target: 'http://delivery.test',
        changeOrigin: true,
      }
    }
  }
})
