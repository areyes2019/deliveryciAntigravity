<template>
  <div
    class="order-card"
    :class="[variantClass, { active: isActive }]"
    @click="$emit('click', order)"
  >
    <div class="order-header">
      <span class="id">{{ icon }} #{{ order.id }}</span>
      <span v-if="badgeText" class="status-tag" :class="badgeClass">{{ badgeText }}</span>
      <span v-if="scheduledTime" class="scheduled-time">{{ scheduledTime }}</span>
    </div>
    <div class="driver-assigned" v-if="driverName">
      <small>Conductor: {{ driverName }}</small>
    </div>
    <p class="addr"><span class="icon">🔵</span> {{ order.pickup_address }}</p>
    <p class="addr"><span class="icon">🔴</span> {{ order.drop_address }}</p>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  order: {
    type: Object,
    required: true
  },
  variant: {
    type: String,
    default: 'pending',
    validator: (v) => ['pending', 'scheduled', 'active'].includes(v)
  },
  isActive: {
    type: Boolean,
    default: false
  },
  badgeText: {
    type: String,
    default: ''
  },
  badgeClass: {
    type: String,
    default: ''
  },
  scheduledTime: {
    type: String,
    default: ''
  },
  driverName: {
    type: String,
    default: ''
  }
})

defineEmits(['click'])

const variantClass = computed(() => {
  const map = {
    pending: 'pending',
    scheduled: 'scheduled-card',
    active: 'active-trip'
  }
  return map[props.variant]
})

const icon = computed(() => {
  const map = {
    pending: '⏳',
    scheduled: '📅',
    active: '🏍️'
  }
  return map[props.variant]
})
</script>

<style scoped>
.order-card {
    margin: 0.5rem 0.65rem;
    padding: 0.85rem 0.95rem;
    background: #fff;
    border: 1px solid #e8ecf4;
    border-radius: 12px;
    cursor: pointer;
    transition: box-shadow 0.2s, border-color 0.2s, transform 0.2s;
    display: flex;
    flex-direction: column;
    gap: 0.45rem;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}
.order-card:hover {
  border-color: #c7d2fe;
  box-shadow: 0 8px 20px -12px rgba(79, 70, 229, 0.45);
  transform: translateY(-1px);
}
.order-card.active {
  border-color: #6366f1;
  background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
  box-shadow: 0 10px 28px -14px rgba(79, 70, 229, 0.55);
}
.order-card .order-header { display: flex; justify-content: space-between; margin-bottom: 0.25rem; }
.order-card .id { font-weight: 700; font-size: 0.85rem; color: #6366F1; display: flex; align-items: center; gap: 0.25rem; }
.order-card .addr { 
    margin: 0; font-size: 0.8rem; color: #64748b; 
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; 
    display: flex; align-items: center; gap: 0.35rem;
}
.order-card .icon { font-size: 0.7rem; }

.status-tag {
    font-size: 0.65rem;
    font-weight: 700;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    text-transform: uppercase;
}
.order-card.scheduled-card { border-left: 4px solid #8b5cf6; }
.order-card.scheduled-card:hover { border-left-color: #6d28d9; background: #faf5ff; }
.scheduled-time { font-size: 0.7rem; color: #6D28D9; font-weight: 700; }
.status-tag.tomado { background: #D1FAE5; color: #065F46; }
.status-tag.arribado { background: #E0F2FE; color: #075985; }
.status-tag.en_camino { background: #DBEAFE; color: #1E40AF; }

.driver-assigned {
    margin-top: -0.25rem;
    color: #6B7280;
    font-size: 0.75rem;
}
</style>
