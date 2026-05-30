<template>
  <div class="ops-panel">
    <header class="ops-panel__header">
      <div class="ops-panel__header-left">
        <div class="ops-panel__brand">
          <span class="ops-panel__brand-icon">⚡</span>
          <div>
            <h1 class="ops-panel__title">Panel de Viajes Activos</h1>
            <p class="ops-panel__subtitle">Centro de monitoreo operativo</p>
          </div>
        </div>
      </div>
      <div class="ops-panel__header-right">
        <div class="ops-panel__live-badge">
          <span class="ops-panel__live-dot"></span>
          <span>En vivo</span>
        </div>
      </div>
    </header>

    <section class="ops-panel__ribbon">
      <div class="ribbon-card ribbon-card--primary">
        <span class="ribbon-card__icon">🚗</span>
        <div class="ribbon-card__body">
          <span class="ribbon-card__value">{{ activeTripsCount }}</span>
          <span class="ribbon-card__label">Viajes activos</span>
        </div>
      </div>
      <div class="ribbon-card ribbon-card--accent">
        <span class="ribbon-card__icon">⏱️</span>
        <div class="ribbon-card__body">
          <span class="ribbon-card__value">{{ avgCompletionTime }}</span>
          <span class="ribbon-card__label">Tiempo promedio</span>
        </div>
      </div>
      <div class="ribbon-card ribbon-card--success">
        <span class="ribbon-card__icon">💰</span>
        <div class="ribbon-card__body">
          <span class="ribbon-card__value">${{ totalFareInProgress }}</span>
          <span class="ribbon-card__label">Monto en curso</span>
        </div>
      </div>
      <div class="ribbon-card ribbon-card--warning">
        <span class="ribbon-card__icon">🔄</span>
        <div class="ribbon-card__body">
          <span class="ribbon-card__value">{{ delayedCount }}</span>
          <span class="ribbon-card__label">Retrasados</span>
        </div>
      </div>
    </section>

    <div class="ops-panel__toolbar">
      <div class="ops-panel__search">
        <svg class="ops-panel__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input v-model="searchQuery" type="text" class="ops-panel__search-input" placeholder="Buscar por conductor, dirección o ID..." />
      </div>
      <div class="ops-panel__filter-chips">
        <button v-for="f in filterOptions" :key="f.key" :class="['filter-chip', { 'filter-chip--active': activeFilter === f.key }]" @click="activeFilter = f.key">
          <span class="filter-chip__dot" :style="{ background: f.color }"></span>
          {{ f.label }}
          <span class="filter-chip__count">{{ f.count }}</span>
        </button>
      </div>
    </div>

    <div class="ops-panel__list">
      <template v-for="group in sortedGroups" :key="group.status">
        <div class="group-header">
          <span class="group-header__dot" :style="{ background: statusColor(group.status) }"></span>
          <h2 class="group-header__title">{{ groupLabel(group.status) }}</h2>
          <span class="group-header__count">{{ group.trips.length }}</span>
          <span class="group-header__eta" v-if="group.status === 'en_camino' || group.status === 'arribado'">Próximo en ~{{ minRemaining(group.trips) }} min</span>
        </div>

        <div v-for="trip in group.trips" :key="trip.id" :class="['trip-card', `trip-card--${trip.status}`, { 'trip-card--delayed': isDelayed(trip) }]">
          <div class="trip-card__top">
            <div class="trip-card__driver">
              <div class="trip-card__avatar" :style="{ background: avatarColor(trip.driver_name) }">{{ initials(trip.driver_name) }}</div>
              <div class="trip-card__driver-info">
                <span class="trip-card__driver-name">{{ trip.driver_name }}</span>
                <span class="trip-card__trip-id">#{{ trip.id }}</span>
              </div>
            </div>
            <div class="trip-card__badges">
              <span v-if="isDelayed(trip)" class="badge badge--delayed">
                <svg class="badge__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Retrasado
              </span>
              <span :class="['badge', `badge--${trip.status}`]"><span class="badge__dot"></span>{{ statusLabel(trip.status) }}</span>
            </div>
          </div>

          <div class="trip-card__addresses">
            <div class="trip-card__address">
              <span class="trip-card__address-icon trip-card__address-icon--pickup">●</span>
              <div class="trip-card__address-text">
                <span class="trip-card__address-label">Recogida</span>
                <span class="trip-card__address-value">{{ trip.pickup_address }}</span>
              </div>
            </div>
            <div class="trip-card__address-connector"></div>
            <div class="trip-card__address">
              <span class="trip-card__address-icon trip-card__address-icon--dropoff">■</span>
              <div class="trip-card__address-text">
                <span class="trip-card__address-label">Destino</span>
                <span class="trip-card__address-value">{{ trip.dropoff_address }}</span>
              </div>
            </div>
          </div>

          <div class="trip-card__progress">
            <div class="progress-bar"><div class="progress-bar__fill" :style="{ width: progressPercent(trip) + '%', background: progressColor(trip) }"></div></div>
            <span class="progress-bar__label">{{ progressPercent(trip) }}%</span>
          </div>

          <div class="trip-card__times">
            <div class="trip-card__time-block">
              <span class="trip-card__time-label">Estimado</span>
              <span class="trip-card__time-value">{{ trip.estimated_duration }} min</span>
            </div>
            <div class="trip-card__time-block">
              <span class="trip-card__time-label">Transcurrido</span>
              <span class="trip-card__time-value">{{ trip.elapsed_time }} min</span>
            </div>
            <div class="trip-card__time-block">
              <span class="trip-card__time-label">Restante</span>
              <span class="trip-card__time-value trip-card__time-value--remaining">{{ remainingTime(trip) }} min</span>
            </div>
            <div class="trip-card__time-block trip-card__time-block--release">
              <span class="trip-card__time-label">Liberación</span>
              <span class="trip-card__time-value trip-card__time-value--release">{{ releaseTime(trip) }}</span>
            </div>
          </div>

          <div class="trip-card__bottom">
            <div class="trip-card__fare">
              <svg class="trip-card__fare-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
              <span class="trip-card__fare-amount">${{ trip.fare.toFixed(2) }}</span>
            </div>
            <div class="trip-card__meta">
              <span class="trip-card__meta-item" :class="{ 'trip-card__meta-item--warn': isDelayed(trip) }">
                <svg class="trip-card__meta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                {{ trip.elapsed_time > trip.estimated_duration ? '+' : '' }}{{ trip.elapsed_time - trip.estimated_duration }} min
              </span>
            </div>
          </div>
        </div>
      </template>

      <div v-if="filteredTrips.length === 0" class="ops-panel__empty">
        <div class="ops-panel__empty-icon">🔍</div>
        <h3 class="ops-panel__empty-title">Sin resultados</h3>
        <p class="ops-panel__empty-text">No se encontraron viajes con los filtros actuales.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const trips = ref([
  { id: 1201, status: 'en_camino', driver_name: 'Juan Pérez', pickup_address: 'Av Tecnológico 123', dropoff_address: 'Centro Comercial Galerías', estimated_duration: 25, elapsed_time: 15, fare: 185.50 },
  { id: 1202, status: 'en_camino', driver_name: 'María García', pickup_address: 'Calle Hidalgo 456', dropoff_address: 'Plaza Mayor', estimated_duration: 18, elapsed_time: 12, fare: 142.00 },
  { id: 1203, status: 'en_camino', driver_name: 'Carlos López', pickup_address: 'Blvd. Independencia 789', dropoff_address: 'Hospital General', estimated_duration: 30, elapsed_time: 28, fare: 220.00 },
  { id: 1204, status: 'en_camino', driver_name: 'Ana Martínez', pickup_address: 'Av. Universidad 234', dropoff_address: 'Terminal de Autobuses', estimated_duration: 15, elapsed_time: 5, fare: 98.75 },
  { id: 1205, status: 'en_camino', driver_name: 'Roberto Sánchez', pickup_address: 'Calle Juárez 567', dropoff_address: 'Parque Industrial', estimated_duration: 22, elapsed_time: 20, fare: 167.30 },
  { id: 1206, status: 'arribado', driver_name: 'Laura Fernández', pickup_address: 'Av. Reforma 890', dropoff_address: 'Hotel Fiesta Inn', estimated_duration: 12, elapsed_time: 10, fare: 85.00 },
  { id: 1207, status: 'arribado', driver_name: 'Pedro Ramírez', pickup_address: 'Calle 5 de Mayo 123', dropoff_address: 'Auditorio Municipal', estimated_duration: 8, elapsed_time: 7, fare: 65.50 },
  { id: 1208, status: 'arribado', driver_name: 'Sofía Torres', pickup_address: 'Av. Morelos 456', dropoff_address: 'Clínica del Valle', estimated_duration: 10, elapsed_time: 9, fare: 78.00 },
  { id: 1209, status: 'arribado', driver_name: 'Diego Hernández', pickup_address: 'Calle Allende 789', dropoff_address: 'Universidad Politécnica', estimated_duration: 14, elapsed_time: 13, fare: 95.25 },
  { id: 1210, status: 'tomado', driver_name: 'Gabriela Ruiz', pickup_address: 'Av. Zaragoza 321', dropoff_address: 'Plaza Cívica', estimated_duration: 20, elapsed_time: 3, fare: 155.00 },
  { id: 1211, status: 'tomado', driver_name: 'Fernando Castillo', pickup_address: 'Calle Guerrero 654', dropoff_address: 'Mercado de Abastos', estimated_duration: 16, elapsed_time: 2, fare: 112.80 },
  { id: 1212, status: 'tomado', driver_name: 'Valentina Ortiz', pickup_address: 'Blvd. Adolfo López Mateos 987', dropoff_address: 'Centro Histórico', estimated_duration: 25, elapsed_time: 1, fare: 198.00 },
  { id: 1213, status: 'tomado', driver_name: 'Andrés Vega', pickup_address: 'Av. Constitución 147', dropoff_address: 'Estadio Corona', estimated_duration: 10, elapsed_time: 0, fare: 72.40 },
  { id: 1214, status: 'publicado', driver_name: 'Daniela Ríos', pickup_address: 'Calle Madero 258', dropoff_address: 'Plaza Sendero', estimated_duration: 18, elapsed_time: 0, fare: 135.00 },
  { id: 1215, status: 'publicado', driver_name: 'Ricardo Mendoza', pickup_address: 'Av. México 369', dropoff_address: 'Aeropuerto Internacional', estimated_duration: 35, elapsed_time: 0, fare: 280.00 },
  { id: 1216, status: 'publicado', driver_name: 'Patricia Navarro', pickup_address: 'Calle Obregón 741', dropoff_address: 'Palacio Municipal', estimated_duration: 12, elapsed_time: 0, fare: 88.50 },
  { id: 1217, status: 'en_camino', driver_name: 'Jorge Aguilar', pickup_address: 'Av. Patriotismo 852', dropoff_address: 'Hospital Ángeles', estimated_duration: 20, elapsed_time: 35, fare: 175.00 },
  { id: 1218, status: 'en_camino', driver_name: 'Mónica Delgado', pickup_address: 'Calle Victoria 963', dropoff_address: 'Plaza Galerías', estimated_duration: 15, elapsed_time: 22, fare: 110.00 },
])

const searchQuery = ref('')
const activeFilter = ref('all')

function tripsByStatus(status) {
  return trips.value.filter(t => t.status === status)
}

const filterOptions = computed(() => [
  { key: 'all', label: 'Todos', color: '#64748b', count: filteredTrips.value.length },
  { key: 'en_camino', label: 'En camino', color: '#10b981', count: tripsByStatus('en_camino').length },
  { key: 'arribado', label: 'Arribado', color: '#f59e0b', count: tripsByStatus('arribado').length },
  { key: 'tomado', label: 'Tomado', color: '#3b82f6', count: tripsByStatus('tomado').length },
  { key: 'publicado', label: 'Publicado', color: '#94a3b8', count: tripsByStatus('publicado').length },
])

const filteredTrips = computed(() => {
  let result = trips.value
  if (activeFilter.value !== 'all') result = result.filter(t => t.status === activeFilter.value)
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    result = result.filter(t => t.driver_name.toLowerCase().includes(q) || t.pickup_address.toLowerCase().includes(q) || t.dropoff_address.toLowerCase().includes(q) || String(t.id).includes(q))
  }
  return result
})

const sortedGroups = computed(() => {
  const groups = []
  for (const status of ['en_camino', 'arribado', 'tomado', 'publicado']) {
    const tripsInGroup = filteredTrips.value.filter(t => t.status === status).sort((a, b) => remainingTime(a) - remainingTime(b))
    if (tripsInGroup.length > 0) groups.push({ status, trips: tripsInGroup })
  }
  return groups
})

const activeTripsCount = computed(() => trips.value.filter(t => t.status !== 'publicado').length)

const avgCompletionTime = computed(() => {
  const active = trips.value.filter(t => t.status === 'en_camino' || t.status === 'arribado')
  if (active.length === 0) return '—'
  return `${Math.round(active.reduce((s, t) => s + t.elapsed_time, 0) / active.length)} min`
})

const totalFareInProgress = computed(() => {
  return trips.value.filter(t => t.status !== 'publicado').reduce((s, t) => s + t.fare, 0).toFixed(2)
})

const delayedCount = computed(() => trips.value.filter(t => isDelayed(t)).length)

function isDelayed(trip) { return trip.elapsed_time > trip.estimated_duration }
function remainingTime(trip) { return Math.max(0, trip.estimated_duration - trip.elapsed_time) }
function progressPercent(trip) {
  if (trip.estimated_duration === 0) return 0
  return Math.min(100, Math.max(0, Math.round((trip.elapsed_time / trip.estimated_duration) * 100)))
}
function progressColor(trip) {
  if (isDelayed(trip)) return '#ef4444'
  const p = progressPercent(trip)
  if (p >= 80) return '#f59e0b'
  if (p >= 50) return '#10b981'
  return '#3b82f6'
}
function releaseTime(trip) {
  const n = new Date()
  n.setMinutes(n.getMinutes() + remainingTime(trip))
  return `${String(n.getHours()).padStart(2, '0')}:${String(n.getMinutes()).padStart(2, '0')}`
}
function minRemaining(trips) { return Math.min(...trips.map(t => remainingTime(t))) }
function statusLabel(s) { return { en_camino: 'En camino', arribado: 'Arribado', tomado: 'Tomado', publicado: 'Publicado' }[s] || s }
function groupLabel(s) { return { en_camino: 'En Camino', arribado: 'Arribado', tomado: 'Tomado', publicado: 'Publicado' }[s] || s }
function statusColor(s) { return { en_camino: '#10b981', arribado: '#f59e0b', tomado: '#3b82f6', publicado: '#94a3b8' }[s] || '#64748b' }
function initials(name) { return name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase() }

const avatarColors = ['#6366f1', '#8b5cf6', '#ec4899', '#f43f5e', '#f97316', '#14b8a6', '#06b6d4', '#84cc16']
function avatarColor(name) {
  let h = 0
  for (let i = 0; i < name.length; i++) h = name.charCodeAt(i) + ((h << 5) - h)
  return avatarColors[Math.abs(h) % avatarColors.length]
}
</script>

<style scoped>
.ops-panel {
  height: 100%; display: flex; flex-direction: column;
  background: #f8fafc; overflow: hidden;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
}
.ops-panel__header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 1rem 1.25rem;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
  border-bottom: 1px solid rgba(255,255,255,0.06); flex-shrink: 0;
}
.ops-panel__header-left { display: flex; align-items: center; gap: 0.75rem; }
.ops-panel__brand { display: flex; align-items: center; gap: 0.75rem; }
.ops-panel__brand-icon {
  width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #6366f1, #8b5cf6); border-radius: 10px; font-size: 1.15rem;
  box-shadow: 0 2px 8px rgba(99,102,241,0.35); flex-shrink: 0;
}
.ops-panel__title { font-size: 1rem; font-weight: 700; color: #fff; margin: 0; line-height: 1.2; letter-spacing: -0.01em; }
.ops-panel__subtitle { font-size: 0.7rem; color: #94a3b8; margin: 0; font-weight: 500; }
.ops-panel__header-right { display: flex; align-items: center; gap: 0.75rem; }
.ops-panel__live-badge {
  display: flex; align-items: center; gap: 0.4rem; padding: 0.25rem 0.65rem; border-radius: 999px;
  background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.25);
  font-size: 0.7rem; font-weight: 600; color: #fca5a5; text-transform: uppercase; letter-spacing: 0.04em;
}
.ops-panel__live-dot {
  width: 6px; height: 6px; border-radius: 50%; background: #ef4444;
  animation: live-pulse 1.5s ease-in-out infinite;
}
@keyframes live-pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.8)} }

.ops-panel__ribbon {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;
  padding: 0.75rem 1.25rem; background: #fff; border-bottom: 1px solid #e2e8f0; flex-shrink: 0;
}
.ribbon-card {
  display: flex; align-items: center; gap: 0.65rem; padding: 0.65rem 0.85rem; border-radius: 10px;
  background: #f8fafc; border: 1px solid #e2e8f0; transition: transform .15s,box-shadow .15s;
}
.ribbon-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.06); }
.ribbon-card__icon { font-size: 1.25rem; line-height: 1; }
.ribbon-card__body { display: flex; flex-direction: column; }
.ribbon-card__value { font-size: 1.1rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
.ribbon-card__label { font-size: .65rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .04em; }
.ribbon-card--primary .ribbon-card__value { color: #6366f1; }
.ribbon-card--accent .ribbon-card__value { color: #8b5cf6; }
.ribbon-card--success .ribbon-card__value { color: #10b981; }
.ribbon-card--warning .ribbon-card__value { color: #f59e0b; }

.ops-panel__toolbar {
  display: flex; align-items: center; gap: .75rem;
  padding: .65rem 1.25rem; background: #fff; border-bottom: 1px solid #e2e8f0; flex-shrink: 0;
}
.ops-panel__search { position: relative; flex: 1; max-width: 320px; }
.ops-panel__search-icon {
  position: absolute; left: .65rem; top: 50%; transform: translateY(-50%);
  width: 14px; height: 14px; color: #94a3b8; pointer-events: none;
}
.ops-panel__search-input {
  width: 100%; padding: .45rem .75rem .45rem 2rem; border: 1px solid #e2e8f0; border-radius: 8px;
  font-size: .8rem; font-weight: 500; color: #0f172a; background: #f8fafc;
  outline: none; transition: border-color .2s,box-shadow .2s; box-sizing: border-box;
}
.ops-panel__search-input::placeholder { color: #94a3b8; font-weight: 400; }
.ops-panel__search-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.1); background: #fff; }
.ops-panel__filter-chips { display: flex; gap: .35rem; flex-wrap: wrap; }
.filter-chip {
  display: flex; align-items: center; gap: .3rem; padding: .3rem .6rem; border-radius: 999px;
  border: 1px solid #e2e8f0; background: #fff; font-size: .7rem; font-weight: 600;
  color: #475569; cursor: pointer; transition: all .15s; white-space: nowrap; font-family: inherit;
}
.filter-chip:hover { border-color: #cbd5e1; background: #f8fafc; }
.filter-chip--active { background: #eef2ff; border-color: #6366f1; color: #6366f1; }
.filter-chip__dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.filter-chip__count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 16px; height: 16px; padding: 0 4px; border-radius: 999px;
  background: #e2e8f0; font-size: .6rem; font-weight: 700; color: #475569;
}
.filter-chip--active .filter-chip__count { background: #6366f1; color: #fff; }

.ops-panel__list {
  flex: 1; overflow-y: auto; padding: .75rem 1.25rem 1.25rem;
  display: flex; flex-direction: column; gap: .5rem;
}
.ops-panel__list::-webkit-scrollbar { width: 5px; }
.ops-panel__list::-webkit-scrollbar-track { background: transparent; }
.ops-panel__list::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
.ops-panel__list::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.group-header {
  display: flex; align-items: center; gap: .5rem; padding: .5rem 0 .35rem;
  margin-top: .25rem; border-bottom: 1px solid #e2e8f0;
  position: sticky; top: 0; background: #f8fafc; z-index: 2;
}
.group-header:first-child { margin-top: 0; }
.group-header__dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.group-header__title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #475569; margin: 0; }
.group-header__count {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 18px; height: 18px; padding: 0 5px; border-radius: 999px;
  background: #e2e8f0; font-size: .6rem; font-weight: 700; color: #475569;
}
.group-header__eta { margin-left: auto; font-size: .65rem; font-weight: 600; color: #64748b; }

.trip-card {
  background: #fff; border: 1px solid #e2e8f0; border-radius: 12px;
  padding: .85rem 1rem; display: flex; flex-direction: column; gap: .6rem;
  transition: all .2s; box-shadow: 0 1px 3px rgba(0,0,0,.04); position: relative;
}
.trip-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); border-color: #cbd5e1; transform: translateY(-1px); }
.trip-card--en_camino { border-left: 3px solid #10b981; }
.trip-card--arribado { border-left: 3px solid #f59e0b; }
.trip-card--tomado { border-left: 3px solid #3b82f6; }
.trip-card--publicado { border-left: 3px solid #94a3b8; }
.trip-card--delayed { border-left-color: #ef4444 !important; }

.trip-card__top { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.trip-card__driver { display: flex; align-items: center; gap: .6rem; }
.trip-card__avatar {
  width: 34px; height: 34px; border-radius: 10px; display: flex; align-items: center; justify-content: center;
  font-size: .7rem; font-weight: 700; color: #fff; flex-shrink: 0; letter-spacing: .02em;
}
.trip-card__driver-info { display: flex; flex-direction: column; }
.trip-card__driver-name { font-size: .85rem; font-weight: 700; color: #0f172a; line-height: 1.2; }
.trip-card__trip-id { font-size: .65rem; font-weight: 600; color: #94a3b8; }
.trip-card__badges { display: flex; align-items: center; gap: .35rem; }

.badge {
  display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .5rem; border-radius: 999px;
  font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap;
}
.badge__dot { width: 5px; height: 5px; border-radius: 50%; }
.badge__icon { width: 10px; height: 10px; }
.badge--delayed { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.badge--delayed .badge__dot { background: #dc2626; }
.badge--en_camino { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
.badge--en_camino .badge__dot { background: #10b981; }
.badge--arribado { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }
.badge--arribado .badge__dot { background: #f59e0b; }
.badge--tomado { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.badge--tomado .badge__dot { background: #3b82f6; }
.badge--publicado { background: #f8fafc; color: #64748b; border: 1px solid #e2e8f0; }
.badge--publicado .badge__dot { background: #94a3b8; }

.trip-card__addresses {
  display: flex; flex-direction: column; gap: .2rem;
  padding: .4rem .5rem; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9;
}
.trip-card__address { display: flex; align-items: flex-start; gap: .5rem; }
.trip-card__address-icon { font-size: .5rem; line-height: 1.4; flex-shrink: 0; margin-top: .25rem; }
.trip-card__address-icon--pickup { color: #10b981; }
.trip-card__address-icon--dropoff { color: #ef4444; }
.trip-card__address-connector { width: 1px; height: 8px; background: #cbd5e1; margin-left: .25rem; }
.trip-card__address-text { display: flex; flex-direction: column; min-width: 0; }
.trip-card__address-label { font-size: .6rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.trip-card__address-value { font-size: .78rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.trip-card__progress { display: flex; align-items: center; gap: .6rem; }
.progress-bar { flex: 1; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: hidden; }
.progress-bar__fill { height: 100%; border-radius: 999px; transition: width .4s ease,background .3s ease; }
.progress-bar__label { font-size: .7rem; font-weight: 700; color: #475569; min-width: 32px; text-align: right; }

.trip-card__times { display: grid; grid-template-columns: repeat(4, 1fr); gap: .25rem; }
.trip-card__time-block {
  display: flex; flex-direction: column; align-items: center;
  padding: .3rem .2rem; border-radius: 8px;
  background: #f8fafc; border: 1px solid #f1f5f9;
}
.trip-card__time-block--release { background: #eef2ff; border-color: #e0e7ff; }
.trip-card__time-label { font-size: .55rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.trip-card__time-value { font-size: .8rem; font-weight: 700; color: #0f172a; }
.trip-card__time-value--remaining { color: #6366f1; }
.trip-card__time-value--release { color: #6366f1; font-size: .85rem; }

.trip-card__bottom { display: flex; align-items: center; justify-content: space-between; padding-top: .25rem; border-top: 1px solid #f1f5f9; }
.trip-card__fare { display: flex; align-items: center; gap: .35rem; }
.trip-card__fare-icon { width: 14px; height: 14px; color: #10b981; }
.trip-card__fare-amount { font-size: .95rem; font-weight: 800; color: #0f172a; }
.trip-card__meta { display: flex; align-items: center; gap: .5rem; }
.trip-card__meta-item { display: flex; align-items: center; gap: .25rem; font-size: .7rem; font-weight: 600; color: #64748b; }
.trip-card__meta-item--warn { color: #dc2626; }
.trip-card__meta-icon { width: 12px; height: 12px; }

.ops-panel__empty { display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 3rem 1rem; text-align: center; }
.ops-panel__empty-icon { font-size: 2.5rem; margin-bottom: .75rem; opacity: .5; }
.ops-panel__empty-title { font-size: 1rem; font-weight: 700; color: #475569; margin: 0 0 .25rem; }
.ops-panel__empty-text { font-size: .8rem; color: #94a3b8; margin: 0; }

@media (max-width: 900px) {
  .ops-panel__ribbon { grid-template-columns: repeat(2, 1fr); }
  .ops-panel__toolbar { flex-direction: column; align-items: stretch; }
  .ops-panel__search { max-width: 100%; }
  .trip-card__times { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
  .ops-panel__header { padding: .75rem 1rem; }
  .ops-panel__ribbon { grid-template-columns: 1fr 1fr; gap: .5rem; padding: .5rem 1rem; }
  .ops-panel__list { padding: .5rem 1rem 1rem; }
  .trip-card { padding: .7rem; }
  .trip-card__top { flex-direction: column; align-items: flex-start; }
  .trip-card__badges { align-self: flex-start; }
  .trip-card__times { grid-template-columns: repeat(2, 1fr); }
  .ops-panel__filter-chips { flex-wrap: wrap; }
}
</style>
