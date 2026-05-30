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
  }
})

defineEmits(['create-order', 'create-order-manual'])

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

@media (max-width: 900px) {
    .map-area--main { min-height: 400px; }
}

</style>
