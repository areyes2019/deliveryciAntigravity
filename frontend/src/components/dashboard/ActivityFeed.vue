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
          <span>EN VIVO</span>
        </div>
      </div>
    </header>

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
      <!-- ═══ Si hay una cancelación, se limpia toda la lista ═══ -->
      <div v-if="cancelledTripId" class="ops-panel__cancelled-overlay">
        <div class="ops-panel__cancelled-icon">🚫</div>
        <h3 class="ops-panel__cancelled-title">Viaje Cancelado</h3>
        <p class="ops-panel__cancelled-text">El viaje #{{ cancelledTripId }} ha sido cancelado. Todos los viajes activos han sido removidos.</p>
        <button class="ops-panel__cancelled-btn" @click="dismissCancellation">Reanudar monitoreo</button>
      </div>

      <template v-else>
        <template v-for="group in sortedGroups" :key="group.status">
          <div class="group-header">
            <span class="group-header__dot" :style="{ background: statusColor(group.status) }"></span>
            <h2 class="group-header__title">{{ groupLabel(group.status) }}</h2>
            <span class="group-header__count">{{ group.trips.length }}</span>
            <span class="group-header__eta" v-if="group.status === 'en_camino' || group.status === 'arribado'">Próximo en ~{{ minRemaining(group.trips) }} min</span>
          </div>

          <div v-for="trip in group.trips" :key="trip.id" :class="['trip-card', `trip-card--${trip.status}`, { 'trip-card--delayed': isDelayed(trip), 'trip-card--selectable': trip.status === 'tomado', 'trip-card--selected': selectedTrip?.id === trip.id }]" @click="openDetail(trip)">
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

            <!-- ═══ Línea de tracking tipo DHL/FedEx con milestones fijos ═══ -->
            <div class="trip-card__progress">
              <div class="progress-bar">
                <!-- Barra de relleno que avanza continuamente -->
                <div
                  class="progress-bar__fill"
                  :style="{
                    width: progressPercent(trip) + '%',
                    background: progressColor(trip),
                    transition: 'width 0.8s cubic-bezier(0.4, 0, 0.2, 1), background 0.5s ease'
                  }"
                ></div>

                <!-- ═══ Milestones fijos (no se mueven aunque la barra avance) ═══ -->
                <!-- Aceptado → siempre en 0% -->
                <div
                  v-if="getMilestones(trip.id).acceptedAtPercent !== null"
                  class="progress-bar__milestone"
                  :class="'progress-bar__milestone--accepted'"
                  :style="{ left: getMilestones(trip.id).acceptedAtPercent + '%' }"
                >
                  <span class="progress-bar__milestone-dot"></span>
                  <span class="progress-bar__milestone-label">Aceptado</span>
                </div>

                <!-- Arribado → fijado cuando ocurrió la transición -->
                <div
                  v-if="getMilestones(trip.id).arrivedAtPercent !== null"
                  class="progress-bar__milestone"
                  :class="'progress-bar__milestone--arrived'"
                  :style="{ left: getMilestones(trip.id).arrivedAtPercent + '%' }"
                >
                  <span class="progress-bar__milestone-dot"></span>
                  <span class="progress-bar__milestone-label">Arribado</span>
                </div>

                <!-- En camino → fijado cuando ocurrió la transición -->
                <div
                  v-if="getMilestones(trip.id).enRouteAtPercent !== null"
                  class="progress-bar__milestone"
                  :class="'progress-bar__milestone--enroute'"
                  :style="{ left: getMilestones(trip.id).enRouteAtPercent + '%' }"
                >
                  <span class="progress-bar__milestone-dot"></span>
                  <span class="progress-bar__milestone-label">En camino</span>
                </div>

                <!-- Entregado → siempre en 100% -->
                <div
                  v-if="getMilestones(trip.id).deliveredAtPercent !== null"
                  class="progress-bar__milestone"
                  :class="'progress-bar__milestone--delivered'"
                  :style="{ left: getMilestones(trip.id).deliveredAtPercent + '%' }"
                >
                  <span class="progress-bar__milestone-dot"></span>
                  <span class="progress-bar__milestone-label">Entregado</span>
                </div>
              </div>
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
              <div class="trip-card__actions">
                <button v-if="trip.status === 'tomado'" class="trip-card__btn trip-card__btn--accept" @click.stop="acceptTrip(trip.id); closeDetail()">Aceptar</button>
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
          <div class="ops-panel__empty-icon">�</div>
          <h3 class="ops-panel__empty-title">Sin resultados</h3>
          <p class="ops-panel__empty-text">No se encontraron viajes con los filtros actuales.</p>
        </div>
      </template>
    </div>

    <!-- ── Panel de referencia para viajes "tomado" ── -->
    <transition name="panel-slide">
      <div v-if="showDetailPanel && selectedTrip" class="ref-panel">
        <div class="ref-panel__header">
          <div class="ref-panel__header-left">
            <span class="ref-panel__badge">Referencia</span>
            <span class="ref-panel__id">#{{ selectedTrip.id }}</span>
          </div>
          <button class="ref-panel__close" @click="closeDetail" aria-label="Cerrar panel">&times;</button>
        </div>

        <div class="ref-panel__driver">
          <div class="ref-panel__avatar" :style="{ background: avatarColor(selectedTrip.driver_name) }">{{ initials(selectedTrip.driver_name) }}</div>
          <div class="ref-panel__driver-info">
            <span class="ref-panel__driver-name">{{ selectedTrip.driver_name }}</span>
            <span class="ref-panel__driver-label">Conductor asignado</span>
          </div>
        </div>

        <div class="ref-panel__addresses">
          <div class="ref-panel__address">
            <span class="ref-panel__address-dot ref-panel__address-dot--pickup"></span>
            <div class="ref-panel__address-text">
              <span class="ref-panel__address-label">Recogida</span>
              <span class="ref-panel__address-value">{{ selectedTrip.pickup_address }}</span>
            </div>
          </div>
          <div class="ref-panel__address-line"></div>
          <div class="ref-panel__address">
            <span class="ref-panel__address-dot ref-panel__address-dot--dropoff"></span>
            <div class="ref-panel__address-text">
              <span class="ref-panel__address-label">Destino</span>
              <span class="ref-panel__address-value">{{ selectedTrip.dropoff_address }}</span>
            </div>
          </div>
        </div>

        <div class="ref-panel__stats">
          <div class="ref-panel__stat">
            <span class="ref-panel__stat-label">Tarifa</span>
            <span class="ref-panel__stat-value ref-panel__stat-value--fare">${{ selectedTrip.fare.toFixed(2) }}</span>
          </div>
          <div class="ref-panel__stat">
            <span class="ref-panel__stat-label">Duración Est.</span>
            <span class="ref-panel__stat-value">{{ selectedTrip.estimated_duration }} min</span>
          </div>
          <div class="ref-panel__stat">
            <span class="ref-panel__stat-label">Transcurrido</span>
            <span class="ref-panel__stat-value">{{ selectedTrip.elapsed_time }} min</span>
          </div>
          <div class="ref-panel__stat">
            <span class="ref-panel__stat-label">ETA</span>
            <span class="ref-panel__stat-value ref-panel__stat-value--eta">{{ selectedTrip.eta }}</span>
          </div>
        </div>

        <div class="ref-panel__progress">
          <div class="ref-panel__progress-bar">
            <div class="ref-panel__progress-fill" :style="{ width: progressPercent(selectedTrip) + '%', background: progressColor(selectedTrip), transition: 'width 0.8s cubic-bezier(0.4, 0, 0.2, 1), background 0.5s ease' }"></div>
          </div>
          <span class="ref-panel__progress-label">{{ progressPercent(selectedTrip) }}%</span>
        </div>

        <div class="ref-panel__actions">
          <button class="ref-panel__btn ref-panel__btn--accept" @click="acceptTrip(selectedTrip.id); closeDetail()">
            <svg class="ref-panel__btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            Aceptar viaje
          </button>
          <button class="ref-panel__btn ref-panel__btn--cancel" @click="handleCancelTrip(selectedTrip.id)">
            <svg class="ref-panel__btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            Cancelar viaje
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onUnmounted, watch } from 'vue'
import api from '../../api'

const props = defineProps({
  orders: {
    type: Array,
    default: () => []
  },
  drivers: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['accept-trip', 'cancel-trip'])

// ── Reloj global para cálculos en tiempo real ──
const globalClock = ref(Date.now())
let timerId = null

timerId = setInterval(() => {
  globalClock.value = Date.now()
}, 1000)

onUnmounted(() => {
  if (timerId) {
    clearInterval(timerId)
    timerId = null
  }
})

// ── CACHE DE INICIOS SIMULADOS ──
// Cada orden recibe un timestamp de inicio simulado la primera vez
// que se procesa. Esto permite que elapsed_time avance en tiempo real
// incluso sin datos reales del backend (updated_at/created_at).
const simulatedStartCache = new Map()

function getSimulatedStart(orderId) {
  if (simulatedStartCache.has(orderId)) {
    return simulatedStartCache.get(orderId)
  }
  // Offset aleatorio entre 2 y 18 minutos (simula que ya avanzó algo)
  const randomOffsetMinutes = 2 + Math.floor(Math.random() * 16)
  const start = Date.now() - (randomOffsetMinutes * 60000)
  simulatedStartCache.set(orderId, start)
  return start
}

function cleanSimulatedCache(activeOrderIds) {
  const activeSet = new Set(activeOrderIds)
  for (const id of simulatedStartCache.keys()) {
    if (!activeSet.has(id)) {
      simulatedStartCache.delete(id)
    }
  }
}

// ── CACHE DE MILESTONES (HITOS DEL VIAJE) ──
// Almacena el porcentaje exacto de la barra en el momento exacto
// en que ocurrió cada transición de estado.
//
// Estructura por viaje:
// {
//   acceptedAtPercent: 0,       // Siempre 0% (tomado)
//   arrivedAtPercent: 30,       // Fijado cuando cambia a "arribado"
//   enRouteAtPercent: 65,       // Fijado cuando cambia a "en_camino"
//   deliveredAtPercent: 100     // Fijado cuando cambia a "entregado"
// }
//
// Una vez fijados, estos valores NUNCA se recalculan.
// La barra sigue avanzando independientemente.
const milestonesCache = new Map()

function getMilestones(tripId) {
  if (!milestonesCache.has(tripId)) {
    milestonesCache.set(tripId, {
      acceptedAtPercent: 0,
      arrivedAtPercent: null,
      enRouteAtPercent: null,
      deliveredAtPercent: null
    })
  }
  return milestonesCache.get(tripId)
}

function recordMilestone(tripId, status, currentProgress) {
  const ms = getMilestones(tripId)
  const clampedProgress = Math.min(100, Math.max(0, Math.round(currentProgress)))

  switch (status) {
    case 'tomado':
      ms.acceptedAtPercent = 0
      break
    case 'arribado':
      if (ms.arrivedAtPercent === null) {
        ms.arrivedAtPercent = clampedProgress
      }
      break
    case 'en_camino':
      if (ms.enRouteAtPercent === null) {
        ms.enRouteAtPercent = clampedProgress
      }
      break
    case 'entregado':
      ms.deliveredAtPercent = 100
      break
  }

  milestonesCache.set(tripId, ms)
}

function cleanMilestonesCache(activeOrderIds) {
  const activeSet = new Set(activeOrderIds)
  for (const id of milestonesCache.keys()) {
    if (!activeSet.has(id)) {
      milestonesCache.delete(id)
    }
  }
}

// ── Estado del panel de referencia ──
const searchQuery = ref('')
const activeFilter = ref('all')
const selectedTrip = ref(null)
const showDetailPanel = ref(false)

// ── Estado de cancelación ──
const cancelledTripId = ref(null)


function openDetail(trip) {
  if (trip.status === 'tomado') {
    selectedTrip.value = trip
    showDetailPanel.value = true
  }
}

function closeDetail() {
  selectedTrip.value = null
  showDetailPanel.value = false
}

function dismissCancellation() {
  cancelledTripId.value = null
}

async function handleCancelTrip(tripId) {
  try {
    await api.put(`/orders/${tripId}/cancel`)
    // Cuando se cancela un viaje, se limpia toda la lista
    cancelledTripId.value = tripId
  } catch (error) {
    console.error('[ActivityFeed] Error al cancelar viaje:', error.response?.data?.message || error.message)
  }
  closeDetail()
}

async function acceptTrip(tripId) {
  // Calcular el progreso actual ANTES de reiniciar el tiempo simulado
  const now = globalClock.value
  const order = props.orders.find(o => o.id === tripId)
  let currentProgress = 0
  if (order) {
    const estimatedDuration = estimateDuration(order)
    const elapsedTime = calcElapsedTime(order, now)
    currentProgress = Math.min(100, Math.max(0, Math.round((elapsedTime / estimatedDuration) * 100)))
  }

  // Registrar milestone "en_camino" en el porcentaje actual
  recordMilestone(tripId, 'en_camino', currentProgress)

  // Reiniciar el tiempo simulado para que la barra empiece desde 0
  simulatedStartCache.set(tripId, Date.now())

  try {
    await api.put(`/orders/${tripId}/accept`)
  } catch (error) {
    console.error('[ActivityFeed] Error al aceptar viaje:', error.response?.data?.message || error.message)
  }
  closeDetail()
}


// ── Mapa driver_id → driver name ──
const driverMap = computed(() => {
  const map = new Map()
  for (const d of props.drivers) {
    map.set(d.id, d.name || 'Conductor')
  }
  return map
})

// ── Transformar orden raw a trip con datos calculados ──
function transformOrder(order, now) {
  const estimatedDuration = estimateDuration(order)
  const elapsedTime = calcElapsedTime(order, now)
  const remaining = Math.max(0, estimatedDuration - elapsedTime)
  const progress = Math.min(100, Math.max(0, Math.round((elapsedTime / estimatedDuration) * 100)))

  const etaDate = new Date(now)
  etaDate.setMinutes(etaDate.getMinutes() + remaining)
  const eta = `${String(etaDate.getHours()).padStart(2, '0')}:${String(etaDate.getMinutes()).padStart(2, '0')}`

  return {
    id: order.id,
    status: order.status,
    driver_name: driverMap.value.get(order.driver_id) || 'Sin asignar',
    pickup_address: order.pickup_address || 'Sin dirección',
    dropoff_address: order.drop_address || 'Sin dirección',
    fare: parseFloat(order.cost) || 0,
    estimated_duration: estimatedDuration,
    elapsed_time: elapsedTime,
    remaining_time: remaining,
    eta,
    progress
  }
}

function estimateDuration(order) {
  const km = parseFloat(order.distance_km)
  if (!isNaN(km) && km > 0) return Math.max(5, Math.round(km * 2))
  return 20
}

function calcElapsedTime(order, now) {
  // Intentar con datos reales del backend primero
  const refDate = order.updated_at || order.created_at
  if (refDate) {
    const then = new Date(refDate.replace(' ', 'T') + 'Z')
    if (!isNaN(then.getTime())) {
      const diffMs = now - then
      return Math.max(0, Math.floor(diffMs / 60000))
    }
  }

  // Fallback: usar timestamp simulado para que la barra avance en tiempo real
  // 1 segundo real = 1 minuto de viaje simulado
  const simulatedStart = getSimulatedStart(order.id)
  const diffMs = now - simulatedStart
  return Math.max(0, Math.floor(diffMs / 60000))
}

function isActiveOrder(order) {
  return ['tomado', 'arribado', 'en_camino'].includes(order.status)
}

// ── WATCH: Detectar cambios de estado en órdenes y registrar milestones ──
// Cada vez que props.orders cambia (por polling o actualización),
// comparamos el estado anterior con el nuevo para detectar transiciones.
//
// ESTRATEGIA PARA EVITAR DOBLE REGISTRO:
//   - `acceptTrip()` registra "en_camino" manualmente ANTES de llamar a la API.
//   - Cuando el polling trae la respuesta del backend, el watch detecta
//     "tomado → en_camino" pero NO debe registrar de nuevo porque el milestone
//     ya existe (recordMilestone solo registra si es null).
//   - Esto funciona gracias al guard `if (ms.enRouteAtPercent === null)` en recordMilestone.
//
// INICIALIZACIÓN EN PRIMERA CARGA (immediate: true):
//   - Para viajes que ya existen con status "tomado", se inicializa acceptedAtPercent = 0.
//   - Para viajes que ya existen con status "arribado" o "en_camino",
//     se registran los milestones correspondientes en el progreso actual.
const previousOrderStatuses = new Map()
let isFirstRun = true

watch(() => props.orders, (newOrders) => {
  const now = globalClock.value

  for (const order of newOrders) {
    const prevStatus = previousOrderStatuses.get(order.id)
    const newStatus = order.status

    if (isFirstRun) {
      // ── PRIMERA CARGA: inicializar milestones para viajes existentes ──
      // Aseguramos que el milestone "acceptedAtPercent: 0" exista para todos
      getMilestones(order.id) // Esto inicializa acceptedAtPercent = 0

      // Si el viaje ya está en "arribado" o "en_camino", registrar el milestone
      // en el progreso actual (es lo mejor que podemos hacer sin saber cuándo
      // ocurrió exactamente la transición)
      if (newStatus === 'arribado' || newStatus === 'en_camino') {
        const estimatedDuration = estimateDuration(order)
        const elapsedTime = calcElapsedTime(order, now)
        const currentProgress = Math.min(100, Math.max(0, Math.round((elapsedTime / estimatedDuration) * 100)))
        recordMilestone(order.id, newStatus, currentProgress)
      }
    } else if (prevStatus && prevStatus !== newStatus) {
      // ── TRANSICIÓN DE ESTADO: detectada por polling ──
      // Solo registrar si el milestone aún no existe (evita doble registro
      // cuando acceptTrip() ya lo registró manualmente)
      const estimatedDuration = estimateDuration(order)
      const elapsedTime = calcElapsedTime(order, now)
      const currentProgress = Math.min(100, Math.max(0, Math.round((elapsedTime / estimatedDuration) * 100)))

      recordMilestone(order.id, newStatus, currentProgress)
    }

    // Actualizar el mapa de estados anteriores
    previousOrderStatuses.set(order.id, newStatus)
  }

  // Limpiar caché de órdenes que ya no están
  const currentIds = new Set(newOrders.map(o => o.id))
  for (const id of previousOrderStatuses.keys()) {
    if (!currentIds.has(id)) {
      previousOrderStatuses.delete(id)
    }
  }

  // Limpiar milestones de órdenes inactivas
  const activeIds = newOrders.filter(isActiveOrder).map(o => o.id)
  cleanMilestonesCache(activeIds)
  cleanSimulatedCache(activeIds)

  isFirstRun = false
}, { deep: true, immediate: true })

// ── Trips activos transformados (reactivo al reloj global) ──
const activeTrips = computed(() => {
  const now = globalClock.value
  return props.orders
    .filter(isActiveOrder)
    .map(order => transformOrder(order, now))
})


const tripsByStatus = computed(() => {
  const counts = { en_camino: 0, arribado: 0, tomado: 0 }
  for (const trip of activeTrips.value) {
    if (counts[trip.status] !== undefined) counts[trip.status]++
  }
  return counts
})

const filterOptions = computed(() => [
  { key: 'all', label: 'Todos', color: '#64748b', count: activeTrips.value.length },
  { key: 'en_camino', label: 'En camino', color: '#10b981', count: tripsByStatus.value.en_camino },
  { key: 'arribado', label: 'Arribado', color: '#f59e0b', count: tripsByStatus.value.arribado },
  { key: 'tomado', label: 'Tomado', color: '#3b82f6', count: tripsByStatus.value.tomado },
])

const filteredTrips = computed(() => {
  let result = activeTrips.value
  if (activeFilter.value !== 'all') result = result.filter(t => t.status === activeFilter.value)
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    result = result.filter(t => t.driver_name.toLowerCase().includes(q) || t.pickup_address.toLowerCase().includes(q) || t.dropoff_address.toLowerCase().includes(q) || String(t.id).includes(q))
  }
  return result
})

const sortedGroups = computed(() => {
  const groups = []
  for (const status of ['en_camino', 'arribado', 'tomado']) {
    const tripsInGroup = filteredTrips.value.filter(t => t.status === status).sort((a, b) => remainingTime(a) - remainingTime(b))
    if (tripsInGroup.length > 0) groups.push({ status, trips: tripsInGroup })
  }
  return groups
})

// ── Funciones puras ──
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
function releaseTime(trip) { return trip.eta }
function minRemaining(trips) { return Math.min(...trips.map(t => remainingTime(t))) }
function statusLabel(s) { return { en_camino: 'En camino', arribado: 'Arribado', tomado: 'Tomado' }[s] || s }
function groupLabel(s) { return { en_camino: 'En Camino', arribado: 'Arribado', tomado: 'Tomado' }[s] || s }
function statusColor(s) { return { en_camino: '#10b981', arribado: '#f59e0b', tomado: '#3b82f6' }[s] || '#64748b' }
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
  background: #f8fafc; overflow: hidden; position: relative;
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

/* ═══ Overlay de cancelación — limpia toda la lista ═══ */
.ops-panel__cancelled-overlay {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
  animation: cancelled-fade-in 0.4s ease;
}
@keyframes cancelled-fade-in {
  from { opacity: 0; transform: scale(0.95); }
  to { opacity: 1; transform: scale(1); }
}
.ops-panel__cancelled-icon {
  font-size: 3.5rem;
  margin-bottom: 1rem;
  animation: cancelled-shake 0.6s ease;
}
@keyframes cancelled-shake {
  0%, 100% { transform: translateX(0); }
  20% { transform: translateX(-8px); }
  40% { transform: translateX(8px); }
  60% { transform: translateX(-5px); }
  80% { transform: translateX(5px); }
}
.ops-panel__cancelled-title {
  font-size: 1.25rem;
  font-weight: 800;
  color: #dc2626;
  margin: 0 0 0.5rem;
}
.ops-panel__cancelled-text {
  font-size: 0.85rem;
  color: #64748b;
  margin: 0 0 1.5rem;
  max-width: 320px;
  line-height: 1.5;
}
.ops-panel__cancelled-btn {
  padding: 0.6rem 1.5rem;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  background: #fff;
  font-size: 0.8rem;
  font-weight: 700;
  color: #6366f1;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
}
.ops-panel__cancelled-btn:hover {
  background: #eef2ff;
  border-color: #6366f1;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(99,102,241,0.15);
}

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

/* ═══ Progress bar con milestones fijos (estilo DHL/FedEx) ═══ */

.trip-card__progress { display: flex; align-items: center; gap: .6rem; }
.progress-bar {
  flex: 1; height: 6px; background: #e2e8f0; border-radius: 999px; overflow: visible; position: relative;
}
.progress-bar__fill { height: 100%; border-radius: 999px; }
.progress-bar__label { font-size: .7rem; font-weight: 700; color: #475569; min-width: 32px; text-align: right; }

/* ═══ Milestones fijos sobre la barra de tracking (estilo DHL/FedEx) ═══ */
.progress-bar__milestone {
  position: absolute;
  top: 50%;
  transform: translate(-50%, -50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  z-index: 5;
  pointer-events: none;
  /* SIN transición de left — el milestone queda fijo permanentemente */
}

.progress-bar__milestone-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2.5px solid #fff;
  box-shadow: 0 0 0 3px rgba(99,102,241,0.3), 0 2px 6px rgba(0,0,0,0.15);
  background: #6366f1;
  transition: none;
}

.progress-bar__milestone-label {
  font-size: 0.55rem;
  font-weight: 700;
  color: #6366f1;
  background: #eef2ff;
  padding: 1px 5px;
  border-radius: 999px;
  border: 1px solid #e0e7ff;
  white-space: nowrap;
  letter-spacing: 0.02em;
  margin-top: 10px;
  /* Posicionar la etiqueta debajo del dot */
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  margin-top: 4px;
}

/* ── Milestone "Aceptado" ── */
.progress-bar__milestone--accepted .progress-bar__milestone-dot {
  background: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59,130,246,0.3), 0 2px 6px rgba(0,0,0,0.15);
}
.progress-bar__milestone--accepted .progress-bar__milestone-label {
  color: #2563eb;
  background: #eff6ff;
  border-color: #bfdbfe;
}

/* ── Milestone "Arribado" ── */
.progress-bar__milestone--arrived .progress-bar__milestone-dot {
  background: #f59e0b;
  box-shadow: 0 0 0 3px rgba(245,158,11,0.3), 0 2px 6px rgba(0,0,0,0.15);
}
.progress-bar__milestone--arrived .progress-bar__milestone-label {
  color: #d97706;
  background: #fffbeb;
  border-color: #fde68a;
}

/* ── Milestone "En camino" ── */
.progress-bar__milestone--enroute .progress-bar__milestone-dot {
  background: #10b981;
  box-shadow: 0 0 0 3px rgba(16,185,129,0.3), 0 2px 6px rgba(0,0,0,0.15);
}
.progress-bar__milestone--enroute .progress-bar__milestone-label {
  color: #059669;
  background: #ecfdf5;
  border-color: #a7f3d0;
}

/* ── Milestone "Entregado" ── */
.progress-bar__milestone--delivered .progress-bar__milestone-dot {
  background: #8b5cf6;
  box-shadow: 0 0 0 3px rgba(139,92,246,0.3), 0 2px 6px rgba(0,0,0,0.15);
}
.progress-bar__milestone--delivered .progress-bar__milestone-label {
  color: #7c3aed;
  background: #f5f3ff;
  border-color: #ddd6fe;
}


.trip-card__times { display: grid; grid-template-columns: repeat(4, 1fr); gap: .25rem; }
.trip-card__time-block {
  display: flex; flex-direction: column; align-items: center;
  padding: .3rem .2rem; border-radius: 8px;
  background: #f8fafc; border: 1px solid #f1f5f9;
}
.trip-card__time-block--release { background: #eef2ff; border-color: #e0e7ff; }
.trip-card__time-label { font-size: .55rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.trip-card__time-value { font-size: .8rem; font-weight: 800; color: #0f172a; }
.trip-card__time-value--remaining { color: #6366f1; }
.trip-card__time-value--release { color: #6366f1; }

.trip-card__bottom { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.trip-card__fare { display: flex; align-items: center; gap: .35rem; }
.trip-card__fare-icon { width: 14px; height: 14px; color: #10b981; }
.trip-card__fare-amount { font-size: .85rem; font-weight: 800; color: #059669; }
.trip-card__actions { display: flex; gap: .35rem; }
.trip-card__btn {
  padding: .35rem .65rem; border-radius: 8px; border: none;
  font-size: .7rem; font-weight: 700; cursor: pointer; transition: all .15s;
  font-family: inherit; line-height: 1;
}
.trip-card__btn--accept { background: #10b981; color: #fff; }
.trip-card__btn--accept:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(16,185,129,.3); }
.trip-card__meta { display: flex; align-items: center; gap: .35rem; }
.trip-card__meta-item {
  display: flex; align-items: center; gap: .25rem;
  font-size: .65rem; font-weight: 600; color: #64748b;
}
.trip-card__meta-item--warn { color: #ef4444; }
.trip-card__meta-icon { width: 12px; height: 12px; }

.ops-panel__empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 3rem 1rem; text-align: center;
}
.ops-panel__empty-icon { font-size: 2rem; margin-bottom: .5rem; }
.ops-panel__empty-title { font-size: 1rem; font-weight: 700; color: #475569; margin: 0 0 .25rem; }
.ops-panel__empty-text { font-size: .8rem; color: #94a3b8; margin: 0; }

/* ── Trip card selectable (tomado) ── */
.trip-card--selectable {
  cursor: pointer;
}
.trip-card--selectable:hover {
  border-color: #6366f1;
  box-shadow: 0 4px 20px rgba(99,102,241,0.15);
}
.trip-card--selected {
  border-color: #6366f1 !important;
  border-left-color: #6366f1 !important;
  background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
  box-shadow: 0 4px 20px rgba(99,102,241,0.2);
}

/* ── Reference Panel (slide-in overlay) ── */
.ref-panel {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: min(380px, 100%);
  background: #fff;
  border-left: 1px solid #e2e8f0;
  box-shadow: -8px 0 30px rgba(0,0,0,0.08);
  z-index: 50;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  padding: 1.25rem;
  gap: 1rem;
}

.ref-panel__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
}
.ref-panel__header-left {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.ref-panel__badge {
  font-size: 0.6rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
  background: #eef2ff;
  color: #6366f1;
  border: 1px solid #e0e7ff;
}
.ref-panel__id {
  font-size: 0.85rem;
  font-weight: 800;
  color: #0f172a;
}
.ref-panel__close {
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
  flex-shrink: 0;
}
.ref-panel__close:hover {
  background: #e2e8f0;
  color: #0f172a;
}

.ref-panel__driver {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem;
  background: #f8fafc;
  border-radius: 12px;
  border: 1px solid #f1f5f9;
}
.ref-panel__avatar {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.8rem;
  font-weight: 700;
  color: #fff;
  flex-shrink: 0;
}
.ref-panel__driver-info {
  display: flex;
  flex-direction: column;
}
.ref-panel__driver-name {
  font-size: 0.9rem;
  font-weight: 700;
  color: #0f172a;
}
.ref-panel__driver-label {
  font-size: 0.65rem;
  font-weight: 600;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.ref-panel__addresses {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: 0.75rem;
  background: #f8fafc;
  border-radius: 12px;
  border: 1px solid #f1f5f9;
}
.ref-panel__address {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
}
.ref-panel__address-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  margin-top: 0.3rem;
  flex-shrink: 0;
}
.ref-panel__address-dot--pickup {
  background: #10b981;
  box-shadow: 0 0 0 4px rgba(16,185,129,0.15);
}
.ref-panel__address-dot--dropoff {
  background: #ef4444;
  box-shadow: 0 0 0 4px rgba(239,68,68,0.15);
}
.ref-panel__address-line {
  width: 2px;
  height: 12px;
  background: #e2e8f0;
  margin-left: 0.3rem;
}
.ref-panel__address-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.ref-panel__address-label {
  font-size: 0.6rem;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.ref-panel__address-value {
  font-size: 0.82rem;
  font-weight: 600;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.ref-panel__stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 0.5rem;
}
.ref-panel__stat {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0.5rem 0.25rem;
  border-radius: 10px;
  background: #f8fafc;
  border: 1px solid #f1f5f9;
}
.ref-panel__stat-label {
  font-size: 0.55rem;
  font-weight: 700;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.ref-panel__stat-value {
  font-size: 0.9rem;
  font-weight: 800;
  color: #0f172a;
}
.ref-panel__stat-value--fare {
  color: #10b981;
}
.ref-panel__stat-value--eta {
  color: #6366f1;
}

.ref-panel__progress {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.ref-panel__progress-bar {
  flex: 1;
  height: 8px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
}
.ref-panel__progress-fill {
  height: 100%;
  border-radius: 999px;
}

.ref-panel__actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-top: auto;
  padding-top: 0.75rem;
  border-top: 1px solid #f1f5f9;
}
.ref-panel__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.4rem;
  padding: 0.65rem 1rem;
  border-radius: 10px;
  border: 1px solid transparent;
  font-size: 0.8rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
  font-family: inherit;
  line-height: 1;
}
.ref-panel__btn-icon {
  width: 16px;
  height: 16px;
}
.ref-panel__btn--accept {
  background: #10b981;
  color: #fff;
  border-color: #10b981;
}
.ref-panel__btn--accept:hover {
  background: #059669;
  border-color: #059669;
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(16,185,129,0.3);
}
.ref-panel__btn--cancel {
  background: #fff;
  color: #ef4444;
  border-color: #fecaca;
}
.ref-panel__btn--cancel:hover {
  background: #fef2f2;
  border-color: #fca5a5;
  transform: translateY(-1px);
}

/* ── Panel slide transition ── */
.panel-slide-enter-active,
.panel-slide-leave-active {
  transition: all 0.3s ease;
}
.panel-slide-enter-from {
  transform: translateX(100%);
  opacity: 0;
}
.panel-slide-leave-to {
  transform: translateX(100%);
  opacity: 0;
}

@media (max-width: 900px) {
  .ops-panel__toolbar { flex-direction: column; align-items: stretch; }
  .ops-panel__search { max-width: 100%; }
  .trip-card__times { grid-template-columns: repeat(2, 1fr); }
  .ref-panel { width: 100%; }
}
@media (max-width: 600px) {
  .ops-panel__header { padding: .75rem 1rem; }
  .ops-panel__list { padding: .5rem 1rem 1rem; }
  .trip-card { padding: .7rem; }
  .trip-card__top { flex-direction: column; align-items: flex-start; }
  .trip-card__badges { align-self: flex-start; }
  .trip-card__times { grid-template-columns: repeat(2, 1fr); }
  .ops-panel__filter-chips { flex-wrap: wrap; }
  .ref-panel { width: 100%; }
}
</style>
