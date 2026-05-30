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
  padding: 0.75rem 1rem;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border-radius: 10px;
  cursor: pointer;
  transition: background 0.2s, transform 0.15s;
  border: 1px solid transparent;
}
.driver-card:hover {
  background: #F1F5F9;
  transform: translateX(4px);
  border-color: #E2E8F0;
}
.driver-avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: linear-gradient(135deg, #6366F1, #8B5CF6);
  color: white;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 1.1rem;
  flex-shrink: 0;
}
.driver-info {
  flex: 1;
  min-width: 0;
}
.name-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 2px;
}
.driver-info h4 {
  margin: 0;
  font-size: 0.85rem;
  font-weight: 700;
  color: #0F172A;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.status-pill {
  font-size: 0.65rem;
  font-weight: 800;
  padding: 0.15rem 0.5rem;
  border-radius: 20px;
  text-transform: uppercase;
  white-space: nowrap;
}
.status-pill.available {
  background: #DCFCE7;
  color: #166534;
}
.status-pill.en-route {
  background: #DBEAFE;
  color: #1E40AF;
  animation: pulse-soft 2s infinite;
}
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

@keyframes pulse-soft {
  0% { opacity: 1; }
  50% { opacity: 0.7; }
  100% { opacity: 1; }
}
</style>
