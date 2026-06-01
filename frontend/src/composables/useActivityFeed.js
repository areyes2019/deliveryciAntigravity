import { ref, computed, onUnmounted } from 'vue'
import api from '../api'

/**
 * Composable para ActivityFeed.vue
 *
 * Responsabilidades:
 *  - Obtener órdenes activas (tomado, arribado, en_camino) desde GET /api/v1/orders
 *  - Obtener drivers desde GET /api/v1/drivers
 *  - Cruzar driver_id con drivers para obtener driver_name
 *  - Calcular elapsed_time, remaining_time, eta, progress
 *  - Excluir órdenes con status: publicado, entregado, cancelado, pendiente, rechazado
 *
 * Mapeo de campos backend → frontend:
 *   pickup_address  → pickup_address
 *   drop_address    → dropoff_address
 *   cost            → fare
 *   distance_km     → usado para estimar duración (estimated_duration)
 *   driver_id       → cruzado con drivers → name
 *   updated_at      → usado como referencia para elapsed_time
 *                     (cuando el driver tomó la orden, updated_at cambia)
 *
 * ─────────────────────────────────────────────────────────────
 * SIMULACIÓN EN TIEMPO REAL
 * ─────────────────────────────────────────────────────────────
 *
 * Para desarrollo sin backend:
 *   - Se genera un timestamp de inicio simulado para cada orden
 *     basado en un offset aleatorio para que los viajes aparezcan
 *     en diferentes estados de progreso.
 *   - 1 segundo real = 1 minuto de viaje simulado.
 *   - Un único reloj global (globalClock) actualizado cada segundo
 *     con setInterval. Todos los cálculos dependen de este reloj.
 *   - Escalabilidad: O(1) por tick, sin importar cuántos viajes haya.
 */

// ─────────────────────────────────────────────────────────────
// RELOJ GLOBAL REACTIVO (UN SOLO TIMER PARA TODOS LOS VIAJES)
// ─────────────────────────────────────────────────────────────
const globalClock = ref(Date.now())
let globalTimerId = null
let globalTimerRefCount = 0

function startGlobalClock() {
  if (globalTimerId) {
    globalTimerRefCount++
    return
  }
  globalTimerRefCount = 1
  globalTimerId = setInterval(() => {
    globalClock.value = Date.now()
  }, 1000) // 1 tick por segundo real
}

function stopGlobalClock() {
  globalTimerRefCount--
  if (globalTimerRefCount <= 0 && globalTimerId) {
    clearInterval(globalTimerId)
    globalTimerId = null
    globalTimerRefCount = 0
  }
}

// ─────────────────────────────────────────────────────────────
// ESTADO COMPARTIDO (singleton a nivel de módulo)
// ─────────────────────────────────────────────────────────────
const orders = ref([])
const drivers = ref([])
const loading = ref(false)
const error = ref(null)

// Mapa driver_id → driver name
const driverMap = computed(() => {
  const map = new Map()
  for (const d of drivers.value) {
    map.set(d.id, d.name || 'Conductor')
  }
  return map
})

// ─────────────────────────────────────────────────────────────
// DATOS MOCK PARA DESARROLLO SIN BACKEND
// ─────────────────────────────────────────────────────────────
// Estos datos se cargan automáticamente cuando la API falla
// (por ejemplo, cuando no hay backend disponible).
// Cada orden tiene un estado, direcciones, costo, distancia y driver.
// El elapsed_time se calcula en tiempo real a partir del simulatedStartCache.
// ─────────────────────────────────────────────────────────────

const MOCK_DRIVERS = [
  { id: 1, name: 'Carlos Mendoza' },
  { id: 2, name: 'María García' },
  { id: 3, name: 'Juan Pérez' },
  { id: 4, name: 'Ana López' },
  { id: 5, name: 'Roberto Sánchez' },
  { id: 6, name: 'Laura Martínez' },
  { id: 7, name: 'Pedro Ramírez' },
  { id: 8, name: 'Sofía Torres' },
]

const MOCK_ORDERS = [
  {
    id: 1001, status: 'en_camino', driver_id: 1,
    pickup_address: 'Av. Reforma 250, Col. Juárez',
    drop_address: 'Insurgentes Sur 1234, Col. Del Valle',
    cost: 185.50, distance_km: 8.5,
  },
  {
    id: 1002, status: 'en_camino', driver_id: 2,
    pickup_address: 'Calle 5 de Mayo 45, Centro',
    drop_address: 'Blvd. Manuel Ávila Camacho 678, Lomas',
    cost: 220.00, distance_km: 12.3,
  },
  {
    id: 1003, status: 'en_camino', driver_id: 3,
    pickup_address: 'Av. Universidad 890, Col. Narvarte',
    drop_address: 'Calzada de Tlalpan 456, Col. Sta. Úrsula',
    cost: 150.75, distance_km: 6.8,
  },
  {
    id: 1004, status: 'arribado', driver_id: 4,
    pickup_address: 'Plaza Satélite Local 34, Naucalpan',
    drop_address: 'Av. Ejército Nacional 567, Polanco',
    cost: 95.25, distance_km: 4.2,
  },
  {
    id: 1005, status: 'arribado', driver_id: 5,
    pickup_address: 'Mercado de la Merced, Local 12',
    drop_address: 'Av. Tláhuac 890, Col. Los Reyes',
    cost: 130.00, distance_km: 7.1,
  },
  {
    id: 1006, status: 'tomado', driver_id: 6,
    pickup_address: 'Aeropuerto Internacional, Terminal 1',
    drop_address: 'Hotel Marriott, Av. Paseo de la Reforma 500',
    cost: 310.00, distance_km: 15.0,
  },
  {
    id: 1007, status: 'tomado', driver_id: 7,
    pickup_address: 'Centro Comercial Santa Fe, Local 88',
    drop_address: 'Av. Vasco de Quiroga 3400, Santa Fe',
    cost: 78.50, distance_km: 3.5,
  },
  {
    id: 1008, status: 'tomado', driver_id: 8,
    pickup_address: 'Estación Buenavista, Puerta 5',
    drop_address: 'Col. Guerrero, Calle Héroes 123',
    cost: 65.00, distance_km: 2.8,
  },
]

function loadMockData() {
  orders.value = MOCK_ORDERS
  drivers.value = MOCK_DRIVERS
}

// ─────────────────────────────────────────────────────────────
// CACHE DE INICIOS SIMULADOS
// ─────────────────────────────────────────────────────────────
// Cada orden recibe un timestamp de inicio simulado la primera vez
// que se procesa. Esto permite que el elapsed_time avance en tiempo real
// incluso sin datos reales del backend.
//
// El offset aleatorio (2-18 min) hace que los viajes aparezcan
// en diferentes estados de progreso desde el inicio.
// ─────────────────────────────────────────────────────────────

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

/**
 * Limpia la caché de inicios simulados cuando los datos se refrescan
 * para evitar que órdenes completadas sigan en caché.
 */
function cleanSimulatedCache(activeOrderIds) {
  const activeSet = new Set(activeOrderIds)
  for (const id of simulatedStartCache.keys()) {
    if (!activeSet.has(id)) {
      simulatedStartCache.delete(id)
    }
  }
}

// ─────────────────────────────────────────────────────────────
// CACHE DE MILESTONES (HITOS DEL VIAJE)
// ─────────────────────────────────────────────────────────────
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
// ─────────────────────────────────────────────────────────────

const milestonesCache = new Map()

/**
 * Obtiene los milestones de un viaje. Si no existen, los inicializa.
 * El milestone "acceptedAtPercent" siempre es 0.
 */
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

/**
 * Registra un milestone en el porcentaje actual de la barra.
 * Solo se registra si aún no existe (no se sobreescribe).
 *
 * @param {number} tripId - ID del viaje
 * @param {string} status - Estado que provocó el milestone ('tomado', 'arribado', 'en_camino', 'entregado')
 * @param {number} currentProgress - Porcentaje actual de la barra (0-100)
 */
function recordMilestone(tripId, status, currentProgress) {
  const ms = getMilestones(tripId)
  const clampedProgress = Math.min(100, Math.max(0, Math.round(currentProgress)))

  switch (status) {
    case 'tomado':
      // acceptedAtPercent siempre es 0
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

/**
 * Limpia los milestones de órdenes que ya no están activas.
 */
function cleanMilestonesCache(activeOrderIds) {
  const activeSet = new Set(activeOrderIds)
  for (const id of milestonesCache.keys()) {
    if (!activeSet.has(id)) {
      milestonesCache.delete(id)
    }
  }
}


/**
 * Estima la duración de un viaje basado en distancia.
 * Fallback: 20 minutos si no hay distancia disponible.
 */
function estimateDuration(order) {
  const km = parseFloat(order.distance_km)
  if (!isNaN(km) && km > 0) {
    return Math.max(5, Math.round(km * 2))
  }
  return 20
}

/**
 * Calcula el tiempo transcurrido desde que la orden fue tomada.
 *
 * REACTIVO: usa `now` (globalClock) en lugar de Date.now()
 * para que Vue re-calcule cuando el reloj avance.
 *
 * Escala: 1 segundo real = 1 minuto de viaje (simulación).
 *
 * Para datos reales del backend:
 *   - Usa updated_at como referencia de inicio del viaje.
 *   - La diferencia en ms se convierte a minutos.
 *
 * Para datos mock/simulación:
 *   - Usa un timestamp simulado almacenado en simulatedStartCache.
 *   - Esto permite que los viajes avancen aunque no haya updated_at real.
 */
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

  // Fallback: usar timestamp simulado
  const simulatedStart = getSimulatedStart(order.id)
  const diffMs = now - simulatedStart
  return Math.max(0, Math.floor(diffMs / 60000))
}

/**
 * Procesa una orden cruda del backend y la transforma al formato
 * que espera ActivityFeed.vue.
 *
 * RECIBE `now` como parámetro para que sea reactivo
 * a través del globalClock.
 *
 * Cada llamada produce un NUEVO objeto, lo que garantiza que
 * Vue detecte los cambios y reactive el template.
 */
function transformOrder(order, now) {
  const estimatedDuration = estimateDuration(order)
  const elapsedTime = calcElapsedTime(order, now)
  const remainingTime = Math.max(0, estimatedDuration - elapsedTime)
  const progress = Math.min(100, Math.max(0, Math.round((elapsedTime / estimatedDuration) * 100)))

  // Calcular ETA basado en el reloj global
  const etaDate = new Date(now)
  etaDate.setMinutes(etaDate.getMinutes() + remainingTime)
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
    remaining_time: remainingTime,
    eta,
    progress
  }
}

function isActiveOrder(order) {
  return ['tomado', 'arribado', 'en_camino', 'arribado_a_entrega'].includes(order.status)
}

export function useActivityFeed() {
  // Iniciar el reloj global (con ref-counting para múltiples instancias)
  startGlobalClock()

  // Limpiar al desmontar
  onUnmounted(() => {
    stopGlobalClock()
  })

  const fetchData = async () => {
    loading.value = true
    error.value = null
    try {
      const [ordersRes, driversRes] = await Promise.all([
        api.get('/orders'),
        api.get('/drivers')
      ])

      if (ordersRes.data.status) {
        const rawOrders = ordersRes.data.data || []
        orders.value = rawOrders

        // Limpiar caché de simulación: remover órdenes que ya no están activas
        const activeIds = rawOrders.filter(isActiveOrder).map(o => o.id)
        cleanSimulatedCache(activeIds)
      }
      if (driversRes.data.status) {
        drivers.value = driversRes.data.data || []
      }
    } catch (e) {
      console.warn('[useActivityFeed] API no disponible, usando datos mock:', e.message)
      // Cargar datos mock para desarrollo
      loadMockData()
    } finally {
      loading.value = false
    }
  }

  /**
   * ACEPTAR VIAJE
   * Cambia el estado de una orden de 'tomado' a 'en_camino'
   * vía API y reinicia su tiempo simulado.
   * Registra el milestone "en_camino" en el porcentaje actual.
   */
  async function acceptTrip(tripId) {
    const order = orders.value.find(o => o.id === tripId)
    if (!order) return
    if (order.status !== 'tomado') return

    const previousStatus = order.status

    // Calcular el progreso actual ANTES de cambiar el estado
    const now = globalClock.value
    const estimatedDuration = estimateDuration(order)
    const elapsedTime = calcElapsedTime(order, now)
    const currentProgress = Math.min(100, Math.max(0, Math.round((elapsedTime / estimatedDuration) * 100)))

    // Registrar milestone "en_camino" en el porcentaje actual
    recordMilestone(tripId, 'en_camino', currentProgress)

    // Optimistic update
    order.status = 'en_camino'
    simulatedStartCache.set(tripId, Date.now())
    orders.value = [...orders.value]

    try {
      const response = await api.put(`/orders/${tripId}/accept`)
      if (!response.data.status) {
        order.status = previousStatus
        orders.value = [...orders.value]
        console.error('[useActivityFeed] Error al aceptar viaje:', response.data.message)
      }
    } catch (error) {
      order.status = previousStatus
      orders.value = [...orders.value]
      console.error('[useActivityFeed] Error al aceptar viaje:', error.response?.data?.message || error.message)
    }
  }


  /**
   * CANCELAR VIAJE
   * Llama al endpoint API para cancelar la orden y actualiza el estado local.
   * Si la API falla, se revierte el cambio local.
   */
  async function cancelTrip(tripId) {
    const order = orders.value.find(o => o.id === tripId)
    if (!order) return
    if (order.status !== 'tomado') return

    // Guardar estado anterior por si hay que revertir
    const previousStatus = order.status

    // Optimistic update: cambiar inmediatamente en UI
    order.status = 'publicado'
    simulatedStartCache.delete(tripId)
    orders.value = [...orders.value]

    try {
      const response = await api.put(`/orders/${tripId}/cancel`)
      if (!response.data.status) {
        // Revertir si la API falló
        order.status = previousStatus
        orders.value = [...orders.value]
        console.error('[useActivityFeed] Error al cancelar viaje:', response.data.message)
      }
    } catch (error) {
      // Revertir el cambio local si la llamada API falla
      order.status = previousStatus
      orders.value = [...orders.value]
      console.error('[useActivityFeed] Error al cancelar viaje:', error.response?.data?.message || error.message)
    }
  }

  /**
   * Órdenes activas transformadas.
   * DEPENDE DE globalClock → se re-evalúa cada segundo.
   *
   * Esto hace que elapsed_time, remaining_time, progress, eta
   * se actualicen automáticamente sin necesidad de polling.
   *
   * Cada tick del reloj global produce NUEVOS objetos trip,
   * lo que garantiza que Vue detecte los cambios y reactive
   * el template.
   */
  const activeTrips = computed(() => {
    const now = globalClock.value
    return orders.value
      .filter(isActiveOrder)
      .map(order => transformOrder(order, now))
  })

  const tripsByStatus = computed(() => {
    const counts = { en_camino: 0, arribado: 0, tomado: 0, arribado_a_entrega: 0 }
    for (const trip of activeTrips.value) {
      if (counts[trip.status] !== undefined) {
        counts[trip.status]++
      }
    }
    return counts
  })

  return {
    orders,
    drivers,
    loading,
    error,
    fetchData,
    activeTrips,
    tripsByStatus,
    acceptTrip,
    cancelTrip,
    // Milestones API
    getMilestones,
    recordMilestone,
    cleanMilestonesCache
  }

}


