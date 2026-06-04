<template>
  <div class="data-sidebar data-sidebar--orders">
    <!-- Pending Trips Section -->
    <div class="sidebar-section">
      <div class="sidebar-header">
        <h3><span class="sidebar-header__icon" aria-hidden="true">⏳</span> Pendientes</h3>
        <span class="badge pending" v-if="pendingOrders.length > 0">{{ pendingOrders.length }}</span>
      </div>
      <div class="sidebar-list scrollable">
        <div v-if="pendingOrders.length > 0">
          <OrderCard
            v-for="order in pendingOrders"
            :key="order.id"
            :order="order"
            variant="pending"
            :is-active="selectedOrder?.id === order.id"
            :scheduled-time="formatScheduled(order.scheduled_at)"
            @click="$emit('select-order', order)"
          />
        </div>
        <div class="sidebar-empty mini" v-else>
          No hay viajes pendientes.
        </div>
      </div>
    </div>

    <!-- Scheduled Trips Section -->
    <div class="sidebar-section sidebar-section--divider sidebar-section--grow" v-if="scheduledOrders.length > 0">
      <div class="sidebar-header">
        <h3><span class="sidebar-header__icon" aria-hidden="true">📅</span> Programados</h3>
        <span class="badge scheduled">{{ scheduledOrders.length }}</span>
      </div>
      <div class="sidebar-list scrollable sidebar-list--fill">
        <OrderCard
          v-for="order in scheduledOrders"
          :key="order.id"
          :order="order"
          variant="scheduled"
          :scheduled-time="formatScheduled(order.scheduled_at)"
          @click="$emit('select-order', order)"
        />
      </div>
    </div>

  </div>
</template>

<script setup>
import OrderCard from './OrderCard.vue'

const props = defineProps({
  pendingOrders: {
    type: Array,
    default: () => []
  },
  scheduledOrders: {
    type: Array,
    default: () => []
  },
  selectedOrder: {
    type: Object,
    default: null
  },
  drivers: {
    type: Array,
    default: () => []
  }
})

defineEmits(['select-order'])


const formatScheduled = (scheduledAt) => {
  if (!scheduledAt) return ''
  try {
    const p = scheduledAt.split(/[- :]/)
    const date = new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]), parseInt(p[3]), parseInt(p[4]), parseInt(p[5] || 0))
    const dateStr = date.toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' })
    const timeStr = date.toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit', hour12: true })
    return `${dateStr} ${timeStr}`
  } catch {
    return scheduledAt
  }
}
</script>

<style scoped>
.data-sidebar--orders {
  width: 280px;
  flex-shrink: 0;
  border-right: 1px solid rgba(148, 163, 184, 0.35);
  box-shadow: 8px 0 24px -18px rgba(15, 23, 42, 0.35);
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.sidebar-section {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
}

.sidebar-section--divider {
  border-top: 1px solid rgba(226, 232, 240, 0.95);
}

.sidebar-section--grow {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.sidebar-list--fill {
  flex: 1;
  min-height: 0;
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
.sidebar-header .badge.pending { background: #FEF3C7; color: #92400E; }

.sidebar-header .badge.scheduled { background: #F3E8FF; color: #6D28D9; }

.sidebar-list {
    flex: 1; overflow-y: auto; display: flex; flex-direction: column;
}

.sidebar-empty.mini { padding: 1rem; font-size: 0.8rem; }

.sidebar-empty { padding: 2rem 1rem; text-align: center; color: #64748b; font-size: 0.9rem; }
</style>
