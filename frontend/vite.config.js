import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => ({
  base: '/',
  plugins: [
    vue(),
    tailwindcss(),
  ],
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
  },
  build: {
    outDir: '../public',
    emptyOutDir: false,
  }
}))
