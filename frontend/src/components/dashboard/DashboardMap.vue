<template>
  <div class="map-area map-area--main">
    <div id="map-root" class="map-root-frame"></div>

    <!-- Floating Navigation Overlays -->
    <div class="map-controls-top">
      <div class="map-toolbar-label">Acciones rápidas</div>
      <div class="map-pill map-pill--stat">
        <span class="dot pulse green"></span> {{ stats.activeOrders }} en cola
      </div>
      <button type="button" class="map-pill map-pill--stat map-pill--ghost">
        <span class="icon">🏎️</span> {{ stats.totalDrivers }} online
      </button>
      <button
        type="button"
        class="map-pill generate"
        :class="{ disabled: !hasZones }"
        :disabled="!hasZones"
        :title="!hasZones ? 'Debes configurar al menos una zona de operación antes de generar viajes' : ''"
        @click="hasZones && $emit('create-order')"
      >
        <span class="icon">✨</span> Generar con IA
      </button>
      <button
        type="button"
        class="map-pill generate-manual"
        :class="{ disabled: !hasZones }"
        :disabled="!hasZones"
        :title="!hasZones ? 'Debes configurar al menos una zona de operación antes de generar viajes' : ''"
        @click="hasZones && $emit('create-order-manual')"
      >
        <span class="icon">📝</span> Manual
      </button>
      <div v-if="!hasZones" class="map-pill warning-pill" role="status">
        Sin zonas configuradas
      </div>
    </div>

    <!-- Side Route Detail Panel -->
    <transition name="slide-right">
      <div v-if="selectedOrder" class="map-detail-panel">
        <button type="button" class="close-panel" aria-label="Cerrar panel" @click="$emit('clear-selection')">&times;</button>
        <p class="map-detail-panel__eyebrow">Viaje seleccionado</p>
        <h3 class="map-detail-panel__title">Detalle del envío</h3>

        <div class="route-visual">
          <div class="route-stop">
            <span class="dot green"></span>
            <div class="stop-info">
              <p class="label">Recogida</p>
              <p class="address">{{ selectedOrder.pickup_address }}</p>
            </div>
          </div>
          <div class="route-line"></div>
          <div class="route-stop">
            <span class="dot red"></span>
            <div class="stop-info">
              <p class="label">Entrega</p>
              <p class="address">{{ selectedOrder.drop_address }}</p>
            </div>
          </div>
        </div>

        <div class="receiver-info">
          <div class="receiver-section-title">📋 Datos de envío</div>
          <div class="receiver-row" v-if="selectedOrder.receiver_name">
            <span class="receiver-label">👤</span>
            <span class="receiver-key">Recibe:</span>
            <span class="receiver-value">{{ selectedOrder.receiver_name }}</span>
          </div>
          <div class="receiver-row" v-if="selectedOrder.receiver_phone">
            <span class="receiver-label">📞</span>
            <span class="receiver-key">Teléfono:</span>
            <span class="receiver-value">{{ selectedOrder.receiver_phone }}</span>
          </div>
        </div>

        <div class="order-meta">
          <div class="meta-item" v-if="routeInfo">
            <span class="label">Distancia</span>
            <span class="value">{{ routeInfo.distance }}</span>
          </div>
          <div class="meta-item" v-if="routeInfo">
            <span class="label">Tiempo Est.</span>
            <span class="value">{{ routeInfo.duration }}</span>
          </div>
          <div class="meta-item">
            <span class="label">Envío</span>
            <span class="value">${{ Number(selectedOrder.cost).toFixed(2) }}</span>
          </div>
          <div class="meta-item" v-if="selectedOrder.payment_type === 'cash_full'">
            <span class="label">Producto</span>
            <span class="value">${{ Number(selectedOrder.product_amount || 0).toFixed(2) }}</span>
          </div>
          <div class="meta-item price-row" v-if="selectedOrder.payment_type !== 'prepaid'">
            <span class="label">💰 Total a Cobrar</span>
            <span class="value price-highlight">${{ Number(selectedOrder.total_to_collect).toFixed(2) }} MXN</span>
          </div>
          <div class="meta-item price-row" v-else>
            <span class="label">💰 Pagado (Prepaid)</span>
            <span class="value price-highlight">${{ Number(selectedOrder.cost).toFixed(2) }} MXN</span>
          </div>
        </div>

        <button
          type="button"
          v-if="['pendiente', 'publicado', 'tomado', 'arribado'].includes(selectedOrder.status)"
          class="btn-danger full-width"
          @click="$emit('cancel-order')"
        >
          Cancelar viaje
        </button>
      </div>
    </transition>
  </div>
</template>

<script setup>
defineProps({
  stats: {
    type: Object,
    required: true
  },
  hasZones: {
    type: Boolean,
    default: false
  },
  selectedOrder: {
    type: Object,
    default: null
  },
  routeInfo: {
    type: Object,
    default: null
  }
})

defineEmits(['create-order', 'create-order-manual', 'clear-selection', 'cancel-order'])
</script>

<style scoped>
.map-area--main {
  flex: 1;
  position: relative;
  height: 100%;
  min-width: 0;
}

.map-root-frame {
  width: 100%;
  height: 100%;
  border-radius: 0;
}

#map-root {
  width: 100%;
  height: 100%;
  background: #e2e8f0;
}

.map-controls-top {
    position: absolute;
    top: 1rem;
    left: 1rem;
    z-index: 100;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.65rem;
    max-width: calc(100% - 2rem);
    padding: 0.55rem 0.65rem 0.55rem 0.75rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.88);
    border: 1px solid rgba(226, 232, 240, 0.9);
    box-shadow: 0 10px 30px -12px rgba(15, 23, 42, 0.25);
    backdrop-filter: blur(10px);
}

.map-toolbar-label {
  width: 100%;
  flex-basis: 100%;
  font-size: 0.65rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 0.15rem;
}

.map-pill {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    padding: 0.45rem 0.95rem;
    border-radius: 999px;
    box-shadow: none;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-weight: 600;
    font-size: 0.8rem;
    cursor: default;
    transition: border-color 0.2s, background 0.2s, transform 0.2s;
    color: #334155;
}

.map-pill--stat {
  background: linear-gradient(135deg, #4f46e5, #6366f1);
  color: #fff;
  border-color: transparent;
  cursor: default;
  box-shadow: 0 4px 14px rgba(79, 70, 229, 0.35);
}

.map-pill--ghost {
  background: #fff;
  color: #0f172a;
  border-color: #e2e8f0;
  cursor: default;
}

button.map-pill { cursor: pointer; font-family: inherit; }

.map-pill.generate {
    background: linear-gradient(135deg, #6366F1, #8B5CF6);
    color: white;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
}
.map-pill.generate:hover:not(.disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99, 102, 241, 0.45); }
.map-pill.generate.disabled { background: #9CA3AF; box-shadow: none; cursor: not-allowed; opacity: 0.7; }
.map-pill.generate-manual {
    background: linear-gradient(135deg, #059669, #10B981);
    color: white;
    box-shadow: 0 4px 12px rgba(5, 150, 105, 0.35);
}
.map-pill.generate-manual:hover:not(.disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.45); }
.map-pill.generate-manual.disabled { background: #9CA3AF; box-shadow: none; cursor: not-allowed; opacity: 0.7; }
.warning-pill {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
  font-size: 0.75rem;
  font-weight: 700;
  cursor: default;
}

.pulse { width: 8px; height: 8px; border-radius: 50%; display: inline-block; animation: pulse-animation 2s infinite; }
.pulse.green { background: #4ADE80; }

@keyframes pulse-animation { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(74, 222, 128, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74, 222, 128, 0); } }

/* Detail Panel */
.map-detail-panel {
    position: absolute;
    right: 1rem;
    top: 1rem;
    bottom: 1rem;
    width: min(340px, calc(100% - 2rem));
    max-height: calc(100% - 2rem);
    overflow-y: auto;
    background: rgba(255, 255, 255, 0.96);
    z-index: 100;
    border-radius: 16px;
    padding: 1.35rem 1.35rem 1.25rem;
    border: 1px solid rgba(226, 232, 240, 0.95);
    box-shadow: 0 24px 50px -20px rgba(15, 23, 42, 0.35);
    backdrop-filter: blur(12px);
}

.map-detail-panel__eyebrow {
  margin: 0 0 0.2rem;
  font-size: 0.65rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: #94a3b8;
}

.map-detail-panel__title {
  font-size: 1rem;
  font-weight: 800;
  margin: 0 0 0.75rem;
  color: #0f172a;
  letter-spacing: -0.02em;
}

.route-visual { position: relative; margin-bottom: 0.75rem; padding-left: 10px; }
.route-stop { display: flex; align-items: flex-start; gap: 1rem; z-index: 2; position: relative; margin-bottom: 0.5rem; }
.route-line { position: absolute; left: 14px; top: 20px; bottom: 20px; width: 2px; background: #E5E7EB; border-left: 2px dashed #6366F1; z-index: 1; }

.dot { width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; }
.dot.green { background: #10B981; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2); }
.dot.red { background: #EF4444; box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.2); }

.stop-info p.label { font-size: 0.7rem; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 2px; }
.stop-info p.address { font-size: 0.85rem; font-weight: 500; }

.receiver-info { display: flex; flex-direction: column; gap: 0.35rem; border-top: 1px solid #F3F4F6; padding-top: 0.6rem; margin-bottom: 0.75rem; }
.receiver-section-title { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 0.25rem; }
.receiver-row { display: flex; align-items: center; gap: 0.5rem; }
.receiver-label { font-size: 0.9rem; }
.receiver-key { font-size: 0.8rem; color: #64748b; font-weight: 500; }
.receiver-value { font-weight: 700; font-size: 0.85rem; color: #0f172a; }

.order-meta { display: flex; flex-direction: column; gap: 0.5rem; border-top: 1px solid #F3F4F6; padding-top: 0.6rem; margin-bottom: 0.75rem; }
.meta-item { display: flex; justify-content: space-between; }
.meta-item .label { color: #64748b; font-size: 0.85rem; }
.meta-item .value { font-weight: 600; font-size: 0.85rem; }
.meta-item .value.price-highlight { color: #059669; font-size: 1rem; font-weight: 800; }
.meta-item .value.muted { color: #9CA3AF; font-style: italic; font-weight: 400; }
.price-row { background: #F0FDF4; border-radius: 8px; padding: 0.5rem 0.75rem; margin-top: 0.25rem; }

.close-panel {
  position: absolute;
  top: 0.75rem;
  right: 0.75rem;
  width: 2rem;
  height: 2rem;
  border: none;
  border-radius: 10px;
  background: #f1f5f9;
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
  color: #64748b;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: background 0.2s, color 0.2s;
}
.close-panel:hover {
  background: #e2e8f0;
  color: #0f172a;
}

/* Transitions */
.slide-right-enter-active, .slide-right-leave-active { transition: all 0.3s ease; }
.slide-right-enter-from, .slide-right-leave-to { transform: translateX(50px); opacity: 0; }

.full-width { width: 100%; justify-content: center; }

.btn-danger {
    background: #EF4444; color: white;
    padding: 0.75rem 1.5rem; border-radius: 8px; border: none;
    font-weight: 600; cursor: pointer; transition: all 0.2s ease;
}
.btn-danger:hover { background: #DC2626; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4); }
.btn-danger:active { transform: translateY(0); }

@media (max-width: 900px) {
    .map-area--main { min-height: 400px; }
    .map-detail-panel { width: calc(100% - 2rem); left: 1rem; right: 1rem; }
}
</style>
