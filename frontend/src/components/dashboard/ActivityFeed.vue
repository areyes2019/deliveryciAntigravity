<template>
  <div class="ops-panel">

    <!-- // ENCABEZADO: Muestra el título del panel y el punto parpadeante "EN VIVO" en la esquina -->
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

    <!-- // BARRA DE ACCIONES RÁPIDAS: Muestra cuántos pedidos hay en cola, cuántos conductores están online,
         y los botones para crear un pedido con IA o manualmente.
         Si no hay zonas configuradas, los botones se deshabilitan y aparece un aviso amarillo. -->
    <div class="ops-panel__quickactions">
      <span class="quickactions__label">Acciones rápidas</span>

      <!-- // Contador de pedidos en cola (punto verde parpadeante) -->
      <div class="action-pill action-pill--stat">
        <span class="action-pill__dot action-pill__dot--pulse"></span>
        {{ stats.activeOrders }} en cola
      </div>

      <!-- // Contador de conductores conectados -->
      <div class="action-pill action-pill--ghost">
        <span class="action-pill__icon">🏎️</span>
        {{ stats.totalDrivers }} online
      </div>

      <!-- // Botón para generar un pedido usando IA. Se bloquea si no hay zonas configuradas -->
      <button
        type="button"
        class="action-pill action-pill--ai"
        :class="{ 'action-pill--disabled': !hasZones }"
        :disabled="!hasZones"
        :title="!hasZones ? 'Debes configurar al menos una zona de operación antes de generar viajes' : ''"
        @click="hasZones && emit('create-order')"
      >
        <span class="action-pill__icon">✨</span> Generar con IA
      </button>

      <!-- // Botón para crear un pedido manual. También se bloquea sin zonas -->
      <button
        type="button"
        class="action-pill action-pill--manual"
        :class="{ 'action-pill--disabled': !hasZones }"
        :disabled="!hasZones"
        :title="!hasZones ? 'Debes configurar al menos una zona de operación antes de generar viajes' : ''"
        @click="hasZones && emit('create-order-manual')"
      >
        <span class="action-pill__icon">📝</span> Manual
      </button>

      <!-- // Aviso que solo aparece cuando no hay zonas configuradas -->
      <div v-if="!hasZones" class="action-pill action-pill--warning" role="status">
        Sin zonas configuradas
      </div>
    </div>

    <!-- // BARRA DE BÚSQUEDA Y FILTROS:
         Arriba hay un campo de texto para buscar por nombre de conductor, dirección o número de pedido.
         Abajo hay chips (botoncitos) para filtrar la lista por estado: Todos, En camino, En destino, Arribado, Tomado.
         Cada chip muestra cuántos viajes hay en ese estado. -->
    <div class="ops-panel__toolbar">
      <div class="ops-panel__search">
        <svg class="ops-panel__search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
        <input v-model="searchQuery" type="text" class="ops-panel__search-input" placeholder="Buscar por conductor, dirección o ID..." />
      </div>

      <!-- // Chips de filtro: al hacer clic en uno, la lista solo muestra los viajes de ese estado -->
      <div class="ops-panel__filter-chips">
        <button v-for="f in filterOptions" :key="f.key" :class="['filter-chip', { 'filter-chip--active': activeFilter === f.key }]" @click="activeFilter = f.key">
          <span class="filter-chip__dot" :style="{ background: f.color }"></span>
          {{ f.label }}
          <span class="filter-chip__count">{{ f.count }}</span>
        </button>
      </div>
    </div>

    <!-- // LISTA PRINCIPAL DE VIAJES: Todo el contenido scrolleable está aquí dentro -->
    <div class="ops-panel__list">

        <!-- // GRUPOS DE VIAJES: Los viajes se agrupan por estado (En destino, En camino, Arribado, Tomado)
             Cada grupo tiene su propio encabezado con el nombre del estado, cuántos viajes hay,
             y el tiempo aproximado al próximo viaje que va a terminar. -->
        <template v-for="group in sortedGroups" :key="group.status">

          <!-- // ENCABEZADO DEL GRUPO: Punto de color + nombre del estado + cantidad + ETA del más próximo -->
          <div class="group-header">
            <span class="group-header__dot" :style="{ background: statusColor(group.status) }"></span>
            <h2 class="group-header__title">{{ groupLabel(group.status) }}</h2>
            <span class="group-header__count">{{ group.trips.length }}</span>
            <span class="group-header__eta" v-if="['en_camino', 'arribado', 'arribado_a_entrega'].includes(group.status)">Próximo en ~{{ minRemaining(group.trips) }} min</span>
          </div>

          <!-- // TARJETA DE VIAJE: Una tarjeta por cada viaje activo.
               Si el viaje está retrasado, la tarjeta se marca en rojo.
               Si el viaje está en estado "tomado", se puede hacer clic para abrir el panel de detalle. -->
          <div v-for="trip in group.trips" :key="trip.id" :class="['trip-card', `trip-card--${trip.status}`, { 'trip-card--delayed': isDelayed(trip), 'trip-card--selectable': true, 'trip-card--selected': selectedTrip?.id === trip.id }]" @click="openDetail(trip)">

            <!-- ══ PANEL IZQUIERDO: Avatar, conductor, direcciones, hitos ══ -->
            <div class="trip-card__left">

              <div class="trip-card__badges">
                <span v-if="isDelayed(trip)" class="badge badge--delayed">
                  <svg class="badge__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  Retrasado
                </span>
                <span :class="['badge', `badge--${trip.status}`]"><span class="badge__dot"></span>{{ statusLabel(trip.status) }}</span>
              </div>

              <div class="trip-card__addresses">
                <div class="trip-card__address">
                  <span class="trip-card__address-dot trip-card__address-dot--pickup"></span>
                  <div class="trip-card__address-text">
                    <span class="trip-card__address-label">Recogida</span>
                    <span class="trip-card__address-value">{{ trip.pickup_address }}</span>
                  </div>
                </div>
                <div class="trip-card__address-connector"></div>
                <div class="trip-card__address">
                  <span class="trip-card__address-dot trip-card__address-dot--dropoff"></span>
                  <div class="trip-card__address-text">
                    <span class="trip-card__address-label">Destino</span>
                    <span class="trip-card__address-value">{{ trip.dropoff_address }}</span>
                  </div>
                </div>
              </div>

              <div class="trip-stepper" role="list" aria-label="Progreso de entrega">
                <template v-for="(step, idx) in STEPS" :key="step.key">
                  <div
                    class="trip-stepper__step"
                    :class="`trip-stepper__step--${getStepState(idx, trip.status)}`"
                    role="listitem"
                    :aria-current="getStepState(idx, trip.status) === 'current' ? 'step' : undefined"
                  >
                    <div class="trip-stepper__dot"></div>
                    <span class="trip-stepper__label">{{ step.label }}</span>
                  </div>
                  <div
                    v-if="idx < STEPS.length - 1"
                    class="trip-stepper__line"
                    :class="`trip-stepper__line--${getLineState(idx, trip.status)}`"
                    aria-hidden="true"
                  ></div>
                </template>
              </div>

            </div>

            <!-- ══ PANEL DERECHO: Conductor, tarifa, tiempos y botón Cancelar ══ -->
            <div class="trip-card__right">

              <div class="trip-card__driver">
                <div class="trip-card__avatar" :style="{ background: avatarColor(trip.driver_name) }">{{ initials(trip.driver_name) }}</div>
                <div class="trip-card__driver-info">
                  <span class="trip-card__driver-name">{{ trip.driver_name }}</span>
                  <span class="trip-card__trip-id">#{{ trip.id }}</span>
                </div>
              </div>

              <div class="trip-card__stats">
                <div class="trip-card__stat">
                  <span class="trip-card__stat-label">Tarifa</span>
                  <span class="trip-card__stat-value">${{ trip.fare.toFixed(2) }}</span>
                </div>
                <div class="trip-card__stat">
                  <span class="trip-card__stat-label">Duración Est.</span>
                  <span class="trip-card__stat-value">{{ trip.estimated_duration }} min</span>
                </div>
                <div class="trip-card__stat">
                  <span class="trip-card__stat-label">Transcurrido</span>
                  <span class="trip-card__stat-value">{{ trip.elapsed_time }} min</span>
                </div>
                <div class="trip-card__stat">
                  <span class="trip-card__stat-label">ETA</span>
                  <span class="trip-card__stat-value">{{ releaseTime(trip) }}</span>
                </div>
              </div>

              <button v-if="['publicado', 'tomado', 'arribado'].includes(trip.status)" class="trip-card__btn trip-card__btn--cancel" @click.stop="handleCancelTrip(trip.id)">Cancelar</button>

            </div>

          </div>
        </template>

        <!-- // ESTADO VACÍO: Si no hay viajes que coincidan con el filtro o la búsqueda, se muestra este mensaje -->
        <div v-if="filteredTrips.length === 0" class="ops-panel__empty">
          <div class="ops-panel__empty-icon">📭</div>
          <h3 class="ops-panel__empty-title">Sin resultados</h3>
          <p class="ops-panel__empty-text">No se encontraron viajes con los filtros actuales.</p>
        </div>
    </div>

    <!-- // PANEL LATERAL DE DETALLE: Se desliza desde la derecha cuando el usuario hace clic en una tarjeta "tomado".
         Solo se muestra si hay un viaje seleccionado. Tiene animación de entrada y salida. -->
    <transition name="panel-slide">
      <div v-if="showDetailPanel && selectedTrip" class="ref-panel">
        <button class="ref-panel__close" @click="closeDetail" aria-label="Cerrar panel">&times;</button>
        <div id="ref-panel-map" class="ref-panel__map"></div>
      </div>
    </transition>
  </div>
</template>

<script setup>
import { ref, computed, onUnmounted, watch, nextTick } from 'vue'
import api from '../../api'
import { useToast } from '../../composables/useToast'
import GoogleProvider from '../../services/maps/GoogleProvider'
import { subscribe } from '../../services/realtime'
import { useAuthStore } from '../../stores/auth'

const props = defineProps({
  orders:   { type: Array,   default: () => [] },
  drivers:  { type: Array,   default: () => [] },
  stats:    { type: Object,  default: () => ({ activeOrders: 0, totalDrivers: 0 }) },
  hasZones: { type: Boolean, default: false }
})

const emit = defineEmits(['accept-trip', 'cancel-trip', 'create-order', 'create-order-manual', 'track-trip'])

// ── Reloj global para elapsed_time en tiempo real ──
const globalClock = ref(Date.now())
let timerId = setInterval(() => { globalClock.value = Date.now() }, 1000)
onUnmounted(() => { clearInterval(timerId); timerId = null })

// ── Inicio simulado por orden (fallback cuando backend no entrega timestamps) ──
const simulatedStartCache = new Map()

function getSimulatedStart(orderId) {
  if (simulatedStartCache.has(orderId)) return simulatedStartCache.get(orderId)
  const randomOffsetMinutes = 2 + Math.floor(Math.random() * 16)
  const start = Date.now() - randomOffsetMinutes * 60000
  simulatedStartCache.set(orderId, start)
  return start
}

function cleanSimulatedCache(activeOrderIds) {
  const activeSet = new Set(activeOrderIds)
  for (const id of simulatedStartCache.keys()) {
    if (!activeSet.has(id)) simulatedStartCache.delete(id)
  }
}

// ── Step Progress Indicator (FedEx-style) ──
const STEPS = [
  { key: 'tomado',              label: 'Tomado'    },
  { key: 'arribado',            label: 'Origen'    },
  { key: 'en_camino',           label: 'Ruta'      },
  { key: 'arribado_a_entrega',  label: 'Destino'   },
  { key: 'entregado',           label: 'Entregado' }
]

function getStepIndex(status) {
  return STEPS.findIndex(s => s.key === status)
}

// 'completed' | 'current' | 'pending'
function getStepState(stepIdx, currentStatus) {
  const currentIdx = getStepIndex(currentStatus)
  if (stepIdx < currentIdx) return 'completed'
  if (stepIdx === currentIdx) return 'current'
  return 'pending'
}

// 'completed' | 'active' | 'pending'  (para la línea entre step[lineIdx] y step[lineIdx+1])
function getLineState(lineIdx, currentStatus) {
  const currentIdx = getStepIndex(currentStatus)
  if (lineIdx + 1 < currentIdx) return 'completed'
  if (lineIdx + 1 === currentIdx) return 'active'
  return 'pending'
}

// ── Google Maps en tiempo real (instancia propia, separada del singleton del dashboard) ──
const refMapProvider = new GoogleProvider()
const authStore = useAuthStore()

// El movimiento del marcador se maneja exclusivamente a través del
// watcher reactivo _trackedDriverLocation, que reacciona tanto a
// eventos Pusher (vía DashboardView) como al polling de recuperación.

// Suscripción directa a Pusher para driver-location (idea de useRealTracking.js).
// Nos enganchamos al canal que ya usa DashboardView; Pusher reutiliza la misma
// conexión WebSocket. Al cerrar el panel, desenlazamos SOLO nuestro handler,
// sin afectar los listeners del dashboard.
let _driverHandler = null

function _bindTracking() {
  // LOG 1 — ¿Tenemos clientId?
  const clientId = authStore.user?.client?.id ?? authStore.user?.client_id
  console.log('[TRACKING-AUDIT] _bindTracking called. authStore.user =', JSON.stringify(authStore.user))
  console.log('[TRACKING-AUDIT] clientId resuelto =', clientId)
  if (!clientId) {
    console.warn('[TRACKING-AUDIT] ❌ RETURN: clientId es null/undefined. No se suscribe al canal.')
    return
  }

  // LOG 2 — Suscripción al canal
  const channelName = `trips.${clientId}`
  console.log(`[TRACKING-AUDIT] Suscribiéndose al canal: "${channelName}"`)
  const channel = subscribe(channelName)
  console.log('[TRACKING-AUDIT] Canal Pusher obtenido =', channel)

  _driverHandler = (data) => {
    const { driver_id, lat, lng } = data
    // LOG 3 — Evento recibido con payload completo
    console.log('[TRACKING-AUDIT] 📡 Evento driver-location RECIBIDO. Payload raw =', JSON.stringify(data))

    // LOG 4 — Estado del panel al momento del evento
    console.log('[TRACKING-AUDIT] selectedTrip.value =', selectedTrip.value?.id, '| refMapProvider.map =', !!refMapProvider.map)
    if (!selectedTrip.value) { console.warn('[TRACKING-AUDIT] ❌ RETURN: selectedTrip es null'); return }

    // LOG 5 — Búsqueda de la orden en props.orders
    const rawOrder = props.orders.find(o => o.id === selectedTrip.value.id)
    console.log('[TRACKING-AUDIT] Buscando orden con id =', selectedTrip.value.id, '→ rawOrder =', rawOrder ? `id:${rawOrder.id} driver_id:${rawOrder.driver_id}` : 'NOT FOUND')
    if (!rawOrder) { console.warn('[TRACKING-AUDIT] ❌ RETURN: rawOrder no encontrado en props.orders'); return }

    // LOG 6 — Comparación driver_id
    console.log(`[TRACKING-AUDIT] Comparando driver_id: rawOrder.driver_id="${String(rawOrder.driver_id)}" vs evento="${String(driver_id)}" → match=${String(rawOrder.driver_id) === String(driver_id)}`)
    if (String(rawOrder.driver_id) !== String(driver_id)) {
      console.warn('[TRACKING-AUDIT] ❌ RETURN: driver_id NO coincide')
      return
    }

    // LOG 7 — Validación de coordenadas
    const la = parseFloat(lat), lo = parseFloat(lng)
    console.log(`[TRACKING-AUDIT] Coordenadas: lat=${la} lng=${lo} isNaN=${isNaN(la)} esZero=${la === 0}`)
    if (isNaN(la) || la === 0) { console.warn('[TRACKING-AUDIT] ❌ RETURN: coordenadas inválidas'); return }

    // LOG 8 — Guardar en pizarra y dibujar si el mapa ya está listo
    _lastKnownDriverPos.value = { lat: la, lng: lo }
    if (refMapProvider.map) {
      refMapProvider.updateMarker('driver-tracking', [la, lo], { icon: MOTO_ICON, popup: `🏍️ ${selectedTrip.value.driver_name}` })
      console.log(`[TRACKING-AUDIT] ✅ updateMarker ejecutado [${la}, ${lo}]`)
    } else {
      console.log(`[TRACKING-AUDIT] 📦 Posición guardada en buffer (mapa aún no listo): [${la}, ${lo}]`)
    }
  }

  channel.bind('driver-location', _driverHandler)
  console.log(`[TRACKING-AUDIT] ✅ Handler enlazado al evento "driver-location" en canal "${channelName}"`)
}

function _unbindTracking() {
  if (!_driverHandler) return
  const clientId = authStore.user?.client?.id ?? authStore.user?.client_id
  if (!clientId) return
  subscribe(`trips.${clientId}`).unbind('driver-location', _driverHandler)
  _driverHandler = null
}

const MOTO_ICON = '🏍️'

async function initRefMap(trip) {
  await nextTick()

  const rawOrder = props.orders.find(o => o.id === trip.id)

  await refMapProvider.initialize('ref-panel-map', { zoom: 13 })
  refMapProvider.clearMarkers()
  refMapProvider.clearRoutes()

  // ── Ruta pickup → dropoff ──
  const pLat = rawOrder ? parseFloat(rawOrder.pickup_lat) : NaN
  const pLng = rawOrder ? parseFloat(rawOrder.pickup_lng) : NaN
  const dLat = rawOrder ? parseFloat(rawOrder.drop_lat)   : NaN
  const dLng = rawOrder ? parseFloat(rawOrder.drop_lng)   : NaN
  const hasCoords = !isNaN(pLat) && pLat !== 0 && !isNaN(dLat) && dLat !== 0

  if (hasCoords) {
    refMapProvider.drawRoute('trip-route', [[pLat, pLng], [dLat, dLng]], { color: '#6366f1', fitBounds: true })
    refMapProvider.addMarker('pickup',  [pLat, pLng], { icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png', popup: `Recogida: ${trip.pickup_address}` })
    refMapProvider.addMarker('dropoff', [dLat, dLng], { icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png',   popup: `Destino: ${trip.dropoff_address}` })
  }

  // ── Marcador inicial del conductor (posición actual al abrir el panel) ──
  if (rawOrder) {
    const driver = props.drivers.find(d => String(d.id) === String(rawOrder.driver_id))
    if (driver) _updateDriverMarker(driver, trip.driver_name)
  }

  // ── Aplicar posición del buffer si llegó un evento Pusher durante la inicialización ──
  if (_lastKnownDriverPos.value) {
    const { lat, lng } = _lastKnownDriverPos.value
    refMapProvider.updateMarker('driver-tracking', [lat, lng], { icon: MOTO_ICON, popup: `🏍️ ${trip.driver_name}` })
    console.log('[TRACKING-AUDIT] ✅ Posición del buffer aplicada al mapa:', _lastKnownDriverPos.value)
  }
}

function _updateDriverMarker(driver, driverName) {
  const lat = parseFloat(driver.current_lat)
  const lng = parseFloat(driver.current_lng)
  console.log(`[TRACKING-AUDIT] _updateDriverMarker → lat=${lat} lng=${lng} driver_id=${driver.id}`)
  if (!isNaN(lat) && lat !== 0) {
    refMapProvider.updateMarker('driver-tracking', [lat, lng], { icon: MOTO_ICON, popup: `🏍️ ${driverName}` })
    console.log('[TRACKING-AUDIT] ✅ updateMarker ejecutado')
  } else {
    console.warn('[TRACKING-AUDIT] ⚠️ Coords inválidas o en cero — marcador NO actualizado')
  }
}

onUnmounted(() => { _unbindTracking(); refMapProvider.destroy() })

// ── Estado del panel de referencia ──
const { showToast } = useToast()
const searchQuery = ref('')
const activeFilter = ref('all')
const selectedTrip = ref(null)
const showDetailPanel = ref(false)
const _lastKnownDriverPos = ref(null)   // "pizarra" de posición: persiste aunque el mapa no esté listo

// Computed que extrae exactamente lat/lng del conductor activo.
// Vue crea dependencias precisas sobre current_lat y current_lng → el watch
// se dispara únicamente cuando ESAS propiedades cambian, sin importar cómo
// llegó el cambio (Pusher, polling, mutación directa).
const _trackedDriverLocation = computed(() => {
  if (!selectedTrip.value) return null
  const rawOrder = props.orders.find(o => o.id === selectedTrip.value.id)
  if (!rawOrder?.driver_id) return null
  const driver = props.drivers.find(d => String(d.id) === String(rawOrder.driver_id))
  if (!driver) return null
  return {
    lat: driver.current_lat,
    lng: driver.current_lng,
    name: selectedTrip.value.driver_name
  }
})

watch(_trackedDriverLocation, (location) => {
  if (!location) return
  const lat = parseFloat(location.lat)
  const lng = parseFloat(location.lng)
  if (!isNaN(lat) && lat !== 0) {
    _lastKnownDriverPos.value = { lat, lng }
    if (refMapProvider.map) {
      refMapProvider.updateMarker('driver-tracking', [lat, lng], { icon: MOTO_ICON, popup: `🏍️ ${location.name}` })
    }
  }
})

async function openDetail(trip) {
  _unbindTracking()
  _lastKnownDriverPos.value = null    // limpia la pizarra del viaje anterior
  selectedTrip.value = trip
  showDetailPanel.value = true
  _bindTracking()                     // arranca tracking de inmediato; los eventos que lleguen
                                      // antes de que el mapa esté listo se guardan en _lastKnownDriverPos
  try {
    await initRefMap(trip)            // inicializa el mapa y aplica el buffer de posición al final
  } catch (err) {
    console.error('[ActivityFeed] initRefMap error:', err)
  }
}

function closeDetail() {
  _unbindTracking()
  selectedTrip.value = null
  showDetailPanel.value = false
  _lastKnownDriverPos.value = null
  refMapProvider.destroy()
}

async function handleTrackTrip(trip) {
  // Emitir evento para abrir el mapa con el viaje seleccionado
  emit('track-trip', trip)
}

async function handleCancelTrip(tripId) {
  const confirmed = window.confirm('¿Estás seguro de que deseas cancelar este viaje? Esta acción no se puede deshacer.')
  if (!confirmed) return

  try {
    await api.put(`/orders/${tripId}/cancel`)
    showToast(`El viaje #${tripId} ha sido cancelado`)
  } catch (error) {
    console.error('[ActivityFeed] Error al cancelar viaje:', error.response?.data?.message || error.message)
  }
  closeDetail()
}

async function acceptTrip(tripId) {
  try {
    await api.put(`/orders/${tripId}/accept`)
  } catch (error) {
    console.error('[ActivityFeed] Error al aceptar viaje:', error.response?.data?.message || error.message)
  }
  closeDetail()
}

// ── Mapa driver_id → nombre ──
const driverMap = computed(() => {
  const map = new Map()
  for (const d of props.drivers) map.set(d.id, d.name || 'Conductor')
  return map
})

// ── Transformar orden raw → trip con datos calculados ──
function transformOrder(order, now) {
  const estimatedDuration = estimateDuration(order)
  const elapsedTime = calcElapsedTime(order, now)
  const remaining = Math.max(0, estimatedDuration - elapsedTime)

  const etaDate = new Date(now)
  etaDate.setMinutes(etaDate.getMinutes() + remaining)
  const h = etaDate.getHours()
  const eta = `${String(h % 12 || 12).padStart(2, '0')}:${String(etaDate.getMinutes()).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`

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
    eta
  }
}

function estimateDuration(order) {
  const km = parseFloat(order.distance_km)
  if (!isNaN(km) && km > 0) return Math.max(5, Math.round(km * 2))
  return 20
}

function calcElapsedTime(order, now) {
  const refDate = order.updated_at || order.created_at
  if (refDate) {
    const then = new Date(refDate.replace(' ', 'T') + 'Z')
    if (!isNaN(then.getTime())) return Math.max(0, Math.floor((now - then) / 60000))
  }
  return Math.max(0, Math.floor((now - getSimulatedStart(order.id)) / 60000))
}

function isActiveOrder(order) {
  return ['tomado', 'arribado', 'en_camino', 'arribado_a_entrega'].includes(order.status)
}

// ── Limpiar cache de simulación cuando órdenes desaparecen ──
watch(() => props.orders, (newOrders) => {
  cleanSimulatedCache(newOrders.filter(isActiveOrder).map(o => o.id))
}, { deep: true })

// ── Trips activos reactivos al reloj ──
const activeTrips = computed(() => {
  const now = globalClock.value
  return props.orders.filter(isActiveOrder).map(order => transformOrder(order, now))
})

const tripsByStatus = computed(() => {
  const counts = { en_camino: 0, arribado: 0, tomado: 0, arribado_a_entrega: 0 }
  for (const trip of activeTrips.value) {
    if (counts[trip.status] !== undefined) counts[trip.status]++
  }
  return counts
})

const filterOptions = computed(() => [
  { key: 'all',               label: 'Todos',      color: '#64748b', count: activeTrips.value.length },
  { key: 'en_camino',         label: 'En camino',  color: '#10b981', count: tripsByStatus.value.en_camino },
  { key: 'arribado_a_entrega',label: 'En destino', color: '#8b5cf6', count: tripsByStatus.value.arribado_a_entrega },
  { key: 'arribado',          label: 'Arribado',   color: '#f59e0b', count: tripsByStatus.value.arribado },
  { key: 'tomado',            label: 'Tomado',     color: '#3b82f6', count: tripsByStatus.value.tomado },
])

const filteredTrips = computed(() => {
  let result = activeTrips.value
  if (activeFilter.value !== 'all') result = result.filter(t => t.status === activeFilter.value)
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    result = result.filter(t =>
      t.driver_name.toLowerCase().includes(q) ||
      t.pickup_address.toLowerCase().includes(q) ||
      t.dropoff_address.toLowerCase().includes(q) ||
      String(t.id).includes(q)
    )
  }
  return result
})

const sortedGroups = computed(() => {
  const groups = []
  for (const status of ['arribado_a_entrega', 'en_camino', 'arribado', 'tomado']) {
    const tripsInGroup = filteredTrips.value
      .filter(t => t.status === status)
      .sort((a, b) => remainingTime(a) - remainingTime(b))
    if (tripsInGroup.length > 0) groups.push({ status, trips: tripsInGroup })
  }
  return groups
})

// ── Funciones puras ──
function isDelayed(trip) { return trip.elapsed_time > trip.estimated_duration }
function remainingTime(trip) { return Math.max(0, trip.estimated_duration - trip.elapsed_time) }
function releaseTime(trip) { return trip.eta }
function minRemaining(trips) { return Math.min(...trips.map(t => remainingTime(t))) }

function statusLabel(s) {
  return { en_camino: 'En camino', arribado: 'Arribado', tomado: 'Tomado', arribado_a_entrega: 'En destino' }[s] || s
}
function groupLabel(s) {
  return { en_camino: 'En Camino', arribado: 'Arribado', tomado: 'Tomado', arribado_a_entrega: 'En Destino' }[s] || s
}
function statusColor(s) {
  return { en_camino: '#10b981', arribado: '#f59e0b', tomado: '#3b82f6', arribado_a_entrega: '#8b5cf6' }[s] || '#64748b'
}

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

/* ═══ Barra de acciones rápidas ═══ */
.ops-panel__quickactions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.55rem;
  padding: 0.55rem 1.25rem;
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  flex-shrink: 0;
}
.quickactions__label {
  font-size: 0.6rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #94a3b8;
  margin-right: 0.15rem;
}
.action-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.35rem 0.8rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #334155;
  white-space: nowrap;
  transition: transform 0.2s, box-shadow 0.2s;
}
.action-pill__icon { font-size: 0.85rem; line-height: 1; }
.action-pill__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}
.action-pill__dot--pulse {
  background: #4ade80;
  animation: quickaction-pulse 2s infinite;
}
@keyframes quickaction-pulse {
  0%   { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74,222,128,0.7); }
  70%  { transform: scale(1);    box-shadow: 0 0 0 8px rgba(74,222,128,0); }
  100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(74,222,128,0); }
}
.action-pill--stat {
  background: linear-gradient(135deg, #4f46e5, #6366f1);
  color: #fff;
  border-color: transparent;
  box-shadow: 0 3px 10px rgba(79,70,229,0.3);
}
.action-pill--ghost {
  background: #fff;
  color: #0f172a;
  border-color: #e2e8f0;
}
.action-pill--ai {
  background: linear-gradient(135deg, #6366f1, #8b5cf6);
  color: #fff;
  border-color: transparent;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 3px 10px rgba(99,102,241,0.3);
}
.action-pill--ai:hover:not(.action-pill--disabled) {
  transform: translateY(-1px);
  box-shadow: 0 5px 14px rgba(99,102,241,0.4);
}
.action-pill--manual {
  background: linear-gradient(135deg, #059669, #10b981);
  color: #fff;
  border-color: transparent;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 3px 10px rgba(5,150,105,0.3);
}
.action-pill--manual:hover:not(.action-pill--disabled) {
  transform: translateY(-1px);
  box-shadow: 0 5px 14px rgba(5,150,105,0.4);
}
.action-pill--disabled {
  background: #9ca3af !important;
  box-shadow: none !important;
  cursor: not-allowed;
  opacity: 0.7;
}
.action-pill--warning {
  background: #fffbeb;
  color: #b45309;
  border-color: #fde68a;
  font-size: 0.7rem;
  font-weight: 700;
}

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
  display: flex; align-items: center; gap: .5rem; padding: .5rem 0 .35rem; margin-top: .25rem;
  border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; background: #f8fafc; z-index: 2;
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
  padding: .85rem 1rem; display: flex; flex-direction: row; gap: .75rem;
  transition: all .2s; box-shadow: 0 1px 3px rgba(0,0,0,.04); position: relative;
}
.trip-card__left {
  flex: 1; display: flex; flex-direction: column; gap: .6rem; min-width: 0;
}
.trip-card__right {
  width: 200px; flex-shrink: 0; display: flex; flex-direction: column; gap: .4rem;
  padding-left: .75rem; border-left: 1px solid #f1f5f9;
}
.trip-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); border-color: #cbd5e1; transform: translateY(-1px); }
.trip-card--en_camino { border-left: 3px solid #10b981; }
.trip-card--arribado { border-left: 3px solid #f59e0b; }
.trip-card--tomado { border-left: 3px solid #3b82f6; }
.trip-card--arribado_a_entrega { border-left: 3px solid #8b5cf6; }
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
.badge--arribado_a_entrega { background: #f5f3ff; color: #7c3aed; border: 1px solid #ddd6fe; }
.badge--arribado_a_entrega .badge__dot { background: #8b5cf6; }

.trip-card__addresses {
  display: flex; flex-direction: column; gap: .2rem;
  padding: .4rem .5rem; background: #f8fafc; border-radius: 8px; border: 1px solid #f1f5f9;
}
.trip-card__address { display: flex; align-items: flex-start; gap: .5rem; }
.trip-card__address-dot {
  width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; margin-top: .2rem;
}
.trip-card__address-dot--pickup  { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.2); }
.trip-card__address-dot--dropoff { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.2); }
.trip-card__address-connector { width: 1px; height: 10px; background: #cbd5e1; margin-left: .45rem; }
.trip-card__address-text { display: flex; flex-direction: column; min-width: 0; }
.trip-card__address-label { font-size: .6rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.trip-card__address-value { font-size: .78rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* ═══ Step Progress Indicator ═══ */
.trip-stepper {
  display: flex;
  align-items: flex-start;
  width: 100%;
  padding-bottom: 1.1rem; /* espacio para etiquetas absolutas */
}

.trip-stepper__step {
  display: flex;
  flex-direction: column;
  align-items: center;
  flex: 0 0 auto;
  width: 12px;
  position: relative;
}

/* Variante grande para el ref-panel */
.trip-stepper__step--lg {
  width: 16px;
}
.trip-stepper__step--lg .trip-stepper__dot {
  width: 16px;
  height: 16px;
}
.trip-stepper__step--lg .trip-stepper__label {
  font-size: 0.6rem;
}

.trip-stepper__dot {
  width: 12px;
  height: 12px;
  border-radius: 50%;
  flex-shrink: 0;
  transition: background 0.25s, box-shadow 0.25s;
}

.trip-stepper__label {
  font-size: 0.5rem;
  font-weight: 600;
  text-align: center;
  white-space: nowrap;
  line-height: 1;
  position: absolute;
  top: calc(100% + 4px);
  left: 50%;
  transform: translateX(-50%);
  pointer-events: none;
}

/* Estado: completado */
.trip-stepper__step--completed .trip-stepper__dot {
  background: #10b981;
}
.trip-stepper__step--completed .trip-stepper__label {
  color: #059669;
}

/* Estado: actual */
.trip-stepper__step--current .trip-stepper__dot {
  background: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
  animation: stepper-pulse 2s ease-in-out infinite;
}
.trip-stepper__step--current .trip-stepper__label {
  color: #1d4ed8;
  font-weight: 700;
}

@keyframes stepper-pulse {
  0%, 100% { box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25); }
  50%       { box-shadow: 0 0 0 5px rgba(59, 130, 246, 0.10); }
}

/* Estado: pendiente */
.trip-stepper__step--pending .trip-stepper__dot {
  background: #e2e8f0;
}
.trip-stepper__step--pending .trip-stepper__label {
  color: #94a3b8;
}

/* Líneas de conexión */
.trip-stepper__line {
  flex: 1 1 0;
  height: 2px;
  margin-top: 5px; /* alinea con el centro del dot de 12px */
  min-width: 6px;
  border-radius: 999px;
  transition: background 0.25s;
}
.trip-stepper__step--lg + .trip-stepper__line,
.ref-panel__stepper .trip-stepper__line {
  margin-top: 7px; /* alinea con dot de 16px en ref-panel */
}

.trip-stepper__line--completed { background: #10b981; }
.trip-stepper__line--active    { background: #3b82f6; }
.trip-stepper__line--pending   { background: #e2e8f0; }

/* Contenedor del stepper en ref-panel */
.ref-panel__stepper {
  display: flex;
  align-items: flex-start;
  width: 100%;
  padding: 0.5rem 0.25rem 1.4rem;
  background: #f8fafc;
  border-radius: 10px;
  border: 1px solid #f1f5f9;
}

/* ═══ Stats del panel derecho ═══ */
.trip-card__stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: .25rem; }
.trip-card__stat {
  display: flex; flex-direction: column; align-items: center;
  padding: .35rem .15rem; border-radius: 8px;
  background: #f8fafc; border: 1px solid #f1f5f9;
}
.trip-card__stat-label { font-size: .5rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .04em; }
.trip-card__stat-value { font-size: .82rem; font-weight: 800; color: #0f172a; }

/* ═══ Botones del panel derecho ═══ */
.trip-card__btn {
  width: 100%; padding: .45rem .5rem; border-radius: 8px; border: none;
  font-size: .75rem; font-weight: 700; cursor: pointer; transition: all .15s;
  font-family: inherit; line-height: 1; text-align: center;
}
.trip-card__btn--accept { background: #10b981; color: #fff; }
.trip-card__btn--accept:hover { background: #059669; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(16,185,129,.3); }
.trip-card__btn--track { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.trip-card__btn--track:hover { background: #dbeafe; border-color: #93c5fd; transform: translateY(-1px); }
.trip-card__btn--cancel { background: #ef4444; color: #fff; border: 1px solid #ef4444; }
.trip-card__btn--cancel:hover { background: #dc2626; border-color: #dc2626; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(239,68,68,.3); }

.ops-panel__empty {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 3rem 1rem; text-align: center;
}
.ops-panel__empty-icon { font-size: 2rem; margin-bottom: .5rem; }
.ops-panel__empty-title { font-size: 1rem; font-weight: 700; color: #475569; margin: 0 0 .25rem; }
.ops-panel__empty-text { font-size: .8rem; color: #94a3b8; margin: 0; }

.trip-card--selectable { cursor: pointer; }
.trip-card--selectable:hover { border-color: #6366f1; box-shadow: 0 4px 20px rgba(99,102,241,0.15); }
.trip-card--selected {
  border-color: #6366f1 !important; border-left-color: #6366f1 !important;
  background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
  box-shadow: 0 4px 20px rgba(99,102,241,0.2);
}

/* ── Reference Panel ── */
.ref-panel {
  position: absolute; top: 0; right: 0; bottom: 0; width: min(950px, 100%);
  background: #fff; border-left: 1px solid #e2e8f0; box-shadow: -8px 0 30px rgba(0,0,0,0.08);
  z-index: 50; display: flex; flex-direction: column; overflow-y: auto; padding: 1.25rem; gap: 1rem;
}
.ref-panel__header { display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.ref-panel__header-left { display: flex; align-items: center; gap: 0.5rem; }
.ref-panel__badge {
  font-size: 0.6rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em;
  padding: 0.2rem 0.5rem; border-radius: 999px; background: #eef2ff; color: #6366f1; border: 1px solid #e0e7ff;
}
.ref-panel__id { font-size: 0.85rem; font-weight: 800; color: #0f172a; }
.ref-panel__map {
  flex: 1; width: 100%; min-height: 0; border-radius: 12px; overflow: hidden;
}
.ref-panel__close {
  position: absolute; top: .75rem; right: .75rem; z-index: 10;
  width: 2rem; height: 2rem; border: none; border-radius: 10px;
  background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15);
  font-size: 1.25rem; line-height: 1; cursor: pointer; color: #64748b;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.2s, color 0.2s;
}
.ref-panel__close:hover { background: #f1f5f9; color: #0f172a; }
.ref-panel__driver {
  display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem;
  background: #f8fafc; border-radius: 12px; border: 1px solid #f1f5f9;
}
.ref-panel__avatar {
  width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center;
  font-size: 0.8rem; font-weight: 700; color: #fff; flex-shrink: 0;
}
.ref-panel__driver-info { display: flex; flex-direction: column; }
.ref-panel__driver-name { font-size: 0.9rem; font-weight: 700; color: #0f172a; }
.ref-panel__driver-label { font-size: 0.65rem; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; }
.ref-panel__addresses {
  display: flex; flex-direction: column; gap: 0.25rem; padding: 0.75rem;
  background: #f8fafc; border-radius: 12px; border: 1px solid #f1f5f9;
}
.ref-panel__address { display: flex; align-items: flex-start; gap: 0.65rem; }
.ref-panel__address-dot {
  width: 10px; height: 10px; border-radius: 50%; margin-top: 0.3rem; flex-shrink: 0;
}
.ref-panel__address-dot--pickup { background: #10b981; box-shadow: 0 0 0 4px rgba(16,185,129,0.15); }
.ref-panel__address-dot--dropoff { background: #ef4444; box-shadow: 0 0 0 4px rgba(239,68,68,0.15); }
.ref-panel__address-line { width: 2px; height: 12px; background: #e2e8f0; margin-left: 0.3rem; }
.ref-panel__address-text { display: flex; flex-direction: column; min-width: 0; }
.ref-panel__address-label { font-size: 0.6rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; }
.ref-panel__address-value { font-size: 0.82rem; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ref-panel__stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
.ref-panel__stat {
  display: flex; flex-direction: column; align-items: center; padding: 0.5rem 0.25rem;
  border-radius: 10px; background: #f8fafc; border: 1px solid #f1f5f9;
}
.ref-panel__stat-label { font-size: 0.55rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; }
.ref-panel__stat-value { font-size: 0.9rem; font-weight: 800; color: #0f172a; }
.ref-panel__stat-value--fare { color: #10b981; }
.ref-panel__stat-value--eta { color: #6366f1; }
.ref-panel__btn {
  display: flex; align-items: center; justify-content: center; gap: 0.4rem;
  padding: 0.65rem 1rem; border-radius: 10px; border: 1px solid transparent;
  font-size: 0.8rem; font-weight: 700; cursor: pointer; transition: all 0.15s; font-family: inherit; line-height: 1;
}
.ref-panel__btn-icon { width: 16px; height: 16px; }
.ref-panel__btn--accept { background: #10b981; color: #fff; border-color: #10b981; }
.ref-panel__btn--accept:hover { background: #059669; border-color: #059669; transform: translateY(-1px); box-shadow: 0 4px 12px rgba(16,185,129,0.3); }
.ref-panel__btn--cancel { background: #fff; color: #ef4444; border-color: #fecaca; }
.ref-panel__btn--cancel:hover { background: #fef2f2; border-color: #fca5a5; transform: translateY(-1px); }

.panel-slide-enter-active, .panel-slide-leave-active { transition: all 0.3s ease; }
.panel-slide-enter-from { transform: translateX(100%); opacity: 0; }
.panel-slide-leave-to { transform: translateX(100%); opacity: 0; }

@media (max-width: 900px) {
  .ops-panel__toolbar { flex-direction: column; align-items: stretch; }
  .ops-panel__search { max-width: 100%; }
  .ref-panel { width: 100%; }
}
@media (max-width: 600px) {
  .ops-panel__header { padding: .75rem 1rem; }
  .ops-panel__list { padding: .5rem 1rem 1rem; }
  .trip-card { padding: .7rem; flex-direction: column; }
  .trip-card__right { width: 100%; padding-left: 0; border-left: none; border-top: 1px solid #f1f5f9; padding-top: .6rem; }
  .trip-card__top { flex-direction: column; align-items: flex-start; }
  .trip-card__badges { align-self: flex-start; }
  .ops-panel__filter-chips { flex-wrap: wrap; }
  .ref-panel { width: 100%; }
}
</style>
