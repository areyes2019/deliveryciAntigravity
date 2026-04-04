import { defineStore } from 'pinia'
import api from '../api'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('token') || null,
    user: JSON.parse(localStorage.getItem('user')) || null,
  }),
  getters: {
    isAuthenticated: (state) => !!state.token,
    userName: (state) => state.user?.name || 'User',
    userRole: (state) => state.user?.role || '',
  },
  actions: {
    async login(email, password) {
      try {
        const response = await api.post('/auth/login', { email, password })
        if (response.data.status) {
          this.token = response.data.data.token
          this.user = response.data.data.user
          localStorage.setItem('token', this.token)
          localStorage.setItem('user', JSON.stringify(this.user))
          return { success: true }
        }
        return { success: false, message: response.data.message || 'Login failed' }
      } catch (error) {
        console.error('Login Error:', error)
        const message = error.response?.data?.message || 'Network error'
        return { success: false, message }
      }
    },
    logout() {
      this.token = null
      this.user = null
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      if (window.location.pathname !== '/login') {
         window.location.href = '/login'
      }
    }
  }
})
