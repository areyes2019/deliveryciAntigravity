<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '../stores/auth'
import api from '../api'

const authStore = useAuthStore()
const driverId = computed(() => authStore.user?.driver?.id)

// --- State ---
const balance   = ref(0)
const movements = ref([])
const isLoading = ref(true)

// --- Fetch ---
const fetchWalletData = async () => {
    if (!driverId.value) return
    try {
        isLoading.value = true
        const [balRes, movRes] = await Promise.all([
            api.get(`/wallet/balance/${driverId.value}`),
            api.get(`/wallet/movements/${driverId.value}`)
        ])
        if (balRes.data.status) balance.value = parseFloat(balRes.data.data.balance) || 0
        if (movRes.data.status) movements.value = movRes.data.data.movements || []
    } catch (e) {
        console.error('Error fetching wallet:', e)
    } finally {
        isLoading.value = false
    }
}

// --- Computed stats ---
const totalEarnings = computed(() =>
    movements.value
        .filter(m => m.type === 'ingreso')
        .reduce((s, m) => s + Math.abs(parseFloat(m.amount || 0)), 0)
)

const totalWithdrawn = computed(() =>
    movements.value
        .filter(m => m.type === 'retiro')
        .reduce((s, m) => s + Math.abs(parseFloat(m.amount || 0)), 0)
)

const totalTrips = computed(() =>
    movements.value.filter(m => m.reference_type === 'viaje').length
)

// --- Weekly bar chart ---
const weeklyData = computed(() => {
    const labels = ['D', 'L', 'M', 'X', 'J', 'V', 'S']
    const now = new Date()
    const todayDay = now.getDay()
    const weekStart = new Date(now)
    weekStart.setDate(now.getDate() - todayDay)
    weekStart.setHours(0, 0, 0, 0)

    const totals = Array(7).fill(0)
    movements.value.forEach(m => {
        if (m.type === 'ingreso') {
            const d = new Date(m.created_at)
            if (d >= weekStart) {
                totals[d.getDay()] += parseFloat(m.amount || 0)
            }
        }
    })

    const max = Math.max(...totals, 1)
    return labels.map((label, i) => ({
        label,
        amount: totals[i],
        heightPct: Math.round((totals[i] / max) * 100),
        isToday: i === todayDay
    }))
})

// --- Helpers ---
const fmt = (n) => Number(n).toFixed(2)

const movementIcon = (type) => {
    if (type === 'ingreso') return '↓'
    if (type === 'retiro')  return '↑'
    return '⟳'
}

const movementLabel = (m) => {
    if (m.description) return m.description
    if (m.type === 'ingreso') return `Ingreso por viaje #${m.reference_id}`
    if (m.type === 'retiro')  return 'Retiro / Liquidación'
    return 'Ajuste manual'
}

const formatDate = (dateStr) => {
    if (!dateStr) return ''
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-MX', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

onMounted(fetchWalletData)
</script>

<template>
  <div class="wallet-screen">

    <!-- 🔵 HEADER -->
    <div class="wallet-header">
      <p class="wallet-title">My Earnings</p>
    </div>

    <!-- 💳 BALANCE CARD -->
    <div class="wallet-balance-card">
      <div>
        <p class="wallet-label">Wallet Balance</p>
        <p class="wallet-amount">$ {{ fmt(balance) }}</p>
      </div>

      <button class="wallet-btn">
        WITHDRAW
      </button>
    </div>

    <!-- 📊 GRAPH CARD -->
    <div class="wallet-chart-card">

      <div class="wallet-chart-header">
        <span class="arrow">‹</span>
        <div>
          <p class="wallet-date">Dec 7 - 14</p>
          <p class="wallet-total">$ {{ fmt(totalWeeklyEarnings) }}</p>
        </div>
        <span class="arrow">›</span>
      </div>

      <!-- gráfico -->
      <div class="wallet-bars">
        <div
          v-for="day in weeklyData"
          :key="day.label"
          class="bar-wrapper"
        >
          <div
            class="bar"
            :class="{ active: day.isToday }"
            :style="{ height: Math.max(day.heightPct, 10) + '%' }"
          ></div>
          <span>{{ day.label }}</span>
        </div>
      </div>

      <!-- stats -->
      <div class="wallet-stats">
        <div>
          <p>Total Trips</p>
          <strong>{{ totalTrips }}</strong>
        </div>
        <div>
          <p>Time Online</p>
          <strong>--</strong>
        </div>
        <div>
          <p>Total Distance</p>
          <strong>--</strong>
        </div>
      </div>

    </div>

  </div>
</template>

<style scoped>

/* 🧱 BASE */
.wallet-screen {
  min-height: 100vh;
  background: #f2f3f7;
  font-family: system-ui;
}

/* 🔵 HEADER */
.wallet-header {
  background: linear-gradient(135deg, #4f6cff, #3b5bdb);
  padding: 50px 20px 80px;
  text-align: center;
  color: white;
}

.wallet-title {
  font-weight: 600;
  font-size: 16px;
}

/* 💳 BALANCE */
.wallet-balance-card {
  background: white;
  margin: -50px 16px 16px;
  border-radius: 16px;
  padding: 16px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0 8px 25px rgba(0,0,0,0.1);
}

.wallet-label {
  font-size: 13px;
  color: #888;
}

.wallet-amount {
  font-size: 26px;
  font-weight: 700;
}

.wallet-btn {
  background: linear-gradient(135deg, #4f6cff, #3b5bdb);
  color: white;
  border: none;
  padding: 10px 16px;
  border-radius: 10px;
  font-weight: 600;
}

/* 📊 CHART CARD */
.wallet-chart-card {
  background: white;
  margin: 16px;
  border-radius: 20px;
  padding: 20px;
  box-shadow: 0 8px 30px rgba(0,0,0,0.08);
}

/* header chart */
.wallet-chart-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.wallet-date {
  font-size: 12px;
  color: #999;
}

.wallet-total {
  font-size: 22px;
  font-weight: bold;
}

.arrow {
  font-size: 20px;
  color: #bbb;
}

/* bars */
.wallet-bars {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  height: 140px;
  margin-bottom: 16px;
}

.bar-wrapper {
  flex: 1;
  text-align: center;
}

.bar {
  width: 60%;
  margin: 0 auto;
  background: #cdd6f4;
  border-radius: 6px;
  transition: all 0.3s;
}

.bar.active {
  background: #4f6cff;
}

.bar-wrapper span {
  font-size: 11px;
  color: #777;
  margin-top: 6px;
  display: block;
}

/* stats */
.wallet-stats {
  display: flex;
  justify-content: space-between;
  text-align: center;
  font-size: 12px;
  color: #888;
}

.wallet-stats strong {
  display: block;
  margin-top: 4px;
  color: #111;
  font-size: 14px;
}

</style>