<template>
  <div class="stats-grid">
    <div class="stat-card" v-if="role === 'superadmin'">
      <div class="stat-icon clients">🏢</div>
      <div class="stat-info">
        <p class="stat-label">Clientes Totales</p>
        <h3 class="stat-value">{{ stats.totalClients }}</h3>
      </div>
    </div>

    <div class="stat-card" v-if="role === 'client_admin'">
      <div class="stat-icon drivers">🏍️</div>
      <div class="stat-info">
        <p class="stat-label">Mis Conductores</p>
        <h3 class="stat-value">{{ stats.totalDrivers }}</h3>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-icon orders">📦</div>
      <div class="stat-info">
        <p class="stat-label">Entregas Activas</p>
        <h3 class="stat-value">{{ stats.activeOrders }}</h3>
      </div>
    </div>

    <div class="stat-card">
      <div class="stat-icon balance">💰</div>
      <div class="stat-info">
        <p class="stat-label">{{ role === 'superadmin' ? 'Saldo Total Sistema' : 'Mi Saldo (Créditos)' }}</p>
        <h3 class="stat-value">${{ stats.balance.toFixed(2) }}</h3>
      </div>
    </div>

    <div class="stat-card" v-if="role === 'client_admin'">
      <div class="stat-icon fleet">🏦</div>
      <div class="stat-info">
        <p class="stat-label">Efectivo en Flota</p>
        <h3 class="stat-value">${{ stats.fleetBalance.toFixed(2) }}</h3>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  stats: {
    type: Object,
    required: true
  },
  role: {
    type: String,
    required: true
  }
})
</script>

<style scoped>
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem;
  padding: 1rem 1.5rem;
}

.stat-card {
  background: #fff;
  border: 1px solid #e8ecf4;
  border-radius: 14px;
  padding: 1rem 1.25rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
  transition: box-shadow 0.2s, transform 0.2s;
}
.stat-card:hover {
  box-shadow: 0 8px 20px -12px rgba(79, 70, 229, 0.35);
  transform: translateY(-2px);
}

.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.4rem;
  flex-shrink: 0;
}
.stat-icon.clients { background: #F3E8FF; }
.stat-icon.drivers { background: #DBEAFE; }
.stat-icon.orders { background: #FEF3C7; }
.stat-icon.balance { background: #D1FAE5; }
.stat-icon.fleet { background: #E0E7FF; }

.stat-info { flex: 1; }
.stat-label { margin: 0; font-size: 0.75rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
.stat-value { margin: 0; font-size: 1.5rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
</style>
