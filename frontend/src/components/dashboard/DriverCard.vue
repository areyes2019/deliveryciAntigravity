<template>
  <div class="driver-card" @click="$emit('click', driver)">
    <div class="driver-avatar">{{ driver.name.charAt(0).toUpperCase() }}</div>
    <div class="driver-info">
      <div class="name-row">
        <h4>{{ driver.name }}</h4>
        <span class="status-pill" :class="isEnRoute ? 'en-route' : 'available'">
          {{ isEnRoute ? 'En Ruta' : 'Libre' }}
        </span>
      </div>
      <div class="balance-row">
        <span class="driver-balance" :class="{ 'has-debt': Number(driver.balance) > 0 }">
          Saldo: ${{ Number(driver.balance || 0).toFixed(2) }}
        </span>
        <span class="vehicle-small">{{ driver.vehicle_details || 'Vehículo estándar' }}</span>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  driver: {
    type: Object,
    required: true
  },
  isEnRoute: {
    type: Boolean,
    default: false
  }
})

defineEmits(['click'])
</script>

<style scoped>
.driver-card {
    display: flex; align-items: center; gap: 0.75rem; padding: 1rem 1.2rem;
    border-bottom: 1px solid #F3F4F6; cursor: pointer; transition: background 0.2s;
}
.driver-card:hover { background: #F9FAFB; }

.driver-avatar {
    width: 36px; height: 36px; border-radius: 50%; background: #E0E7FF; color: #4338CA;
    display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem;
    flex-shrink: 0;
}

.driver-info { flex: 1; overflow: hidden; }
.driver-info h4 { margin: 0; font-size: 0.9rem; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.name-row { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; margin-bottom: 2px; }
.status-pill {
    font-size: 0.65rem; font-weight: 800; padding: 0.15rem 0.5rem; border-radius: 20px; text-transform: uppercase;
}
.status-pill.available { background: #DCFCE7; color: #166534; }
.status-pill.en-route { background: #DBEAFE; color: #1E40AF; animation: pulse-soft 2s infinite; }

@keyframes pulse-soft { 0% { opacity: 1; } 50% { opacity: 0.7; } 100% { opacity: 1; } }

.balance-row {
    display: flex;
    flex-direction: column;
    gap: 0.1rem;
}

.driver-balance {
    font-size: 0.8rem;
    font-weight: 700;
    color: #64748b;
}

.driver-balance.has-debt {
    color: #166534;
}

.vehicle-small {
    font-size: 0.7rem;
    color: #94a3b8;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
