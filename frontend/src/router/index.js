import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import ClientsView from '../views/ClientsView.vue'
import DriversView from '../views/DriversView.vue'
import OrdersView from '../views/OrdersView.vue'
import ReportsView from '../views/ReportsView.vue'
import PricingConfigView from '../views/PricingConfigView.vue'
import DriverSimulatorView from '../views/DriverSimulatorView.vue'
import DriverBillingConfigView from '../views/DriverBillingConfigView.vue'
import DriverAppView from '../views/DriverAppView.vue'
import WalletView from '../views/WalletView.vue'
import ProfileView from '../views/ProfileView.vue'
import { useAuthStore } from '../stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { requiresGuest: true }
    },
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
      meta: { requiresAuth: true, roles: ['superadmin', 'client_admin'] }
    },
    {
      path: '/simulator',
      name: 'simulator',
      component: DriverSimulatorView,
      meta: { requiresAuth: true, roles: ['driver'] }
    },
    {
      path: '/driver',
      name: 'driver-app',
      component: DriverAppView,
      meta: { requiresAuth: true, roles: ['driver'] }
    },
    {
      path: '/wallet',
      name: 'wallet',
      component: WalletView,
      meta: { requiresAuth: true, roles: ['driver'] }
    },
    {
      path: '/profile',
      name: 'profile',
      component: ProfileView,
      meta: { requiresAuth: true, roles: ['driver'] }
    },
    {
      path: '/clients',
      name: 'clients',
      component: ClientsView,
      meta: { requiresAuth: true, roles: ['superadmin'] }
    },
    {
      path: '/drivers',
      name: 'drivers',
      component: DriversView,
      meta: { requiresAuth: true, roles: ['client_admin'] }
    },
    {
      path: '/orders',
      name: 'orders',
      component: OrdersView,
      meta: { requiresAuth: true, roles: ['client_admin'] }
    },
    {
      path: '/reports',
      name: 'reports',
      component: ReportsView,
      meta: { requiresAuth: true, roles: ['superadmin', 'client_admin'] }
    },
    {
      path: '/pricing',
      name: 'pricing',
      component: PricingConfigView,
      meta: { requiresAuth: true, roles: ['client_admin'] }
    },
    {
      path: '/driver-billing',
      name: 'driver-billing',
      component: DriverBillingConfigView,
      meta: { requiresAuth: true, roles: ['client_admin'] }
    }
  ]
})

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()
  const isAuthenticated = authStore.isAuthenticated
  const userRole = authStore.userRole

  if (to.meta.requiresAuth && !isAuthenticated) {
    next('/login')
  } else if (to.meta.requiresGuest && isAuthenticated) {
    if (userRole === 'driver') {
      next('/driver')
    } else {
      next('/')
    }
  } else if (to.meta.roles && !to.meta.roles.includes(userRole)) {
    // Role not authorized, redirect based on role
    if (userRole === 'driver') {
      next('/driver')
    } else {
      next('/')
    }
  } else if (to.path === '/' && userRole === 'driver') {
    next('/driver')
  } else {
    next()
  }
})

export default router
