import { ref, computed } from 'vue'
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
 */

// Estado compartido (singleton) para que múltiples instancias compartan datos
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

/**
 * Estima la duración de un viaje basado en distancia.
 * Fallback: 20 minutos si no hay distancia disponible.
 *
 * TODO: Reemplazar con duración real cuando el backend la proporcione.
 */
function estimateDuration(order) {
  const km = parseFloat(order.distance_km)
  if (!isNaN(km) && km > 0) {
    // Velocidad promedio estimada: 30 km/h en ciudad
    // 1 km ≈ 2 minutos
    return Math.max(5, Math.round(km * 2))
  }
  return 20 // fallback por defecto
}

/**
 * Calcula el tiempo transcurrido desde que la orden fue tomada.
 * Usa updated_at como referencia (es el timestamp más cercano a cuando
 * el driver tomó/empezó a procesar la orden).
 *
 * TODO: Cuando el backend agregue un campo `taken_at`, usarlo en su lugar.
 */
function calcElapsedTime(order) {
  const refDate = order.updated_at || order.created_at
  if (!refDate) return 0
  const then = new Date(refDate.replace(' ', 'T') + 'Z')
  const now = new Date()
  const diffMs = now - then
  return Math.max(0, Math.floor(diffMs / 60000))
}

/**
 * Procesa una orden cruda del backend y la transforma al formato
 * que espera ActivityFeed.vue.
 */
function transformOrder(order) {
  const estimatedDuration = estimateDuration(order)
  const elapsedTime = calcElapsedTime(order)
  const remainingTime = Math.max(0, estimatedDuration - elapsedTime)
  const progress = Math.min(100, Math.max(0, Math.round((elapsedTime / estimatedDuration) * 100)))

  // Calcular ETA
  const now = new Date()
  now.setMinutes(now.getMinutes() + remainingTime)
  const eta = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`

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

/**
 * Filtra solo las órdenes activas (tomado, arribado, en_camino).
 * Excluye: publicado, entregado, cancelado, pendiente, rechazado.
 */
function isActiveOrder(order) {
  return ['tomado', 'arribado', 'en_camino'].includes(order.status)
}

export function useActivityFeed() {
  /**
   * Carga órdenes y drivers desde la API.
   * Se llama manualmente (ej. onMounted, polling, etc.)
   */
  const fetchData = async () => {
    loading.value = true
    error.value = null
    try {
      const [ordersRes, driversRes] = await Promise.all([
        api.get('/orders'),
        api.get('/drivers')
      ])

      if (ordersRes.data.status) {
        orders.value = ordersRes.data.data || []
      }
      if (driversRes.data.status) {
        drivers.value = driversRes.data.data || []
      }
    } catch (e) {
      console.error('[useActivityFeed] Error fetching data:', e)
      error.value = 'Error al cargar datos'
    } finally {
      loading.value = false
    }
  }

  /**
   * Órdenes activas transformadas y listas para consumir por ActivityFeed.vue
   */
  const activeTrips = computed(() => {
    return orders.value
      .filter(isActiveOrder)
      .map(transformOrder)
  })

  /**
   * Cuenta de viajes activos por status
   */
  const tripsByStatus = computed(() => {
    const counts = { en_camino: 0, arribado: 0, tomado: 0 }
    for (const trip of activeTrips.value) {
      if (counts[trip.status] !== undefined) {
        counts[trip.status]++
      }
    }
    return counts
  })

  return {
    // Estado
    orders,
    drivers,
    loading,
    error,

    // Métodos
    fetchData,

    // Datos procesados
    activeTrips,
    tripsByStatus
  }
}
