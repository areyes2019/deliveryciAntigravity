<template>
  <div class="data-sidebar data-sidebar--fleet">
    <div class="sidebar-header">
      <h3><span class="sidebar-header__icon" aria-hidden="true">🏎️</span> Flotilla</h3>
      <span class="badge badge--fleet" v-if="activeDrivers.length > 0">{{ activeDrivers.length }} en línea</span>
    </div>
    <div class="sidebar-list scrollable" v-if="activeDrivers.length > 0">
      <DriverCard
        v-for="driver in activeDrivers"
        :key="driver.id"
        :driver="driver"
        :is-en-route="isDriverEnRoute(driver)"
        @click="$emit('focus-driver', driver)"
      />
    </div>
    <div class="sidebar-empty" v-else>
      No hay conductores en línea.
    </div>
  </div>
</template>

<script setup>
import DriverCard from './DriverCard.vue'

defineProps({
  activeDrivers: {
    type: Array,
    default: () => []
  },
  isDriverEnRoute: {
    type: Function,
    default: () => false
  }
})

defineEmits(['focus-driver'])
</script>

<style scoped>
.data-sidebar--fleet {
  border-left: 1px solid rgba(148, 163, 184, 0.35);
  box-shadow: -8px 0 24px -18px rgba(15, 23, 42, 0.35);
}

.sidebar-header {
    padding: 0.85rem 1rem;
    border-bottom: 1px solid rgba(241, 245, 249, 0.9);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(180deg, #fafbfc 0%, #fff 100%);
}

.sidebar-header h3 {
  font-size: 0.78rem;
  font-weight: 800;
  margin: 0;
  color: #475569;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.sidebar-header__icon {
  font-size: 0.95rem;
  opacity: 0.9;
}

.sidebar-header .badge { padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.75rem; font-weight: 800; }
.sidebar-header .badge--fleet {
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #a7f3d0;
}

.sidebar-list.scrollable {
    overflow-y: auto;
    scrollbar-width: thin;
}

.sidebar-list.scrollable::-webkit-scrollbar {
    width: 6px;
}

.sidebar-list.scrollable::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 10px;
}

.sidebar-list {
    flex: 1; overflow-y: auto; display: flex; flex-direction: column;
}

.sidebar-empty { padding: 2rem 1rem; text-align: center; color: #64748b; font-size: 0.9rem; }
</style>
