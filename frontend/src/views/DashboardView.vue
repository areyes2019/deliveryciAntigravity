<script setup>
// ─────────────────────────────────────────────────────────────────────────────
// IMPORTS DE VUE 3 — COMPOSITION API
// ─────────────────────────────────────────────────────────────────────────────
// Estas son las utilidades reactivas del núcleo de Vue 3:
// - ref:         envuelve un valor primitivo (número, string, boolean, objeto)
//                en un objeto reactivo. Cuando `.value` cambia, Vue actualiza el DOM.
// - computed:    crea una propiedad derivada que se recalcula automáticamente
//                solo cuando sus dependencias reactivas cambian. Tiene caché.
// - watch:       observa una fuente reactiva y ejecuta un callback cuando cambia.
// - onMounted:   hook de ciclo de vida → se ejecuta DESPUÉS de que el componente
//                esté insertado en el DOM (el HTML ya existe y podemos tocarlo).
// - onUnmounted: hook de ciclo de vida → se ejecuta justo ANTES de que el componente
//                sea eliminado del DOM (momento ideal para limpiar recursos).
// - nextTick:    retorna una promesa que se resuelve después de que Vue ha terminado
//                de procesar todos los cambios reactivos pendientes en el DOM.
//                Imprescindible cuando necesitas leer/escribir el DOM justo después
//                de cambiar datos reactivos.
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'

// Store de Pinia que mantiene el estado de autenticación de forma global.
// Guarda el usuario logueado, su token JWT, su rol, etc.
// Al ser un store de Pinia, su estado persiste aunque este componente se destruya.
import { useAuthStore } from '../stores/auth'

// Instancia preconfigurada de Axios para hacer peticiones HTTP al backend.
// Ya tiene el `baseURL` de la API y el interceptor que inyecta el token JWT
// en cada cabecera `Authorization`. Importando este objeto ya no hay que
// configurar nada más al hacer llamadas a la API.
import api from '../api'

// ─────────────────────────────────────────────────────────────────────────────
// IMPORTS DE COMPOSABLES
// Un composable es una función que encapsula lógica de negocio reactiva y
// reutilizable. Siguen la convención de nombrarse con el prefijo "use".
// Cada uno gestiona un dominio específico de la aplicación.
// ─────────────────────────────────────────────────────────────────────────────
import { useOrders }       from '../composables/useOrders'        // Estado y CRUD de pedidos
import { useDrivers }      from '../composables/useDrivers'       // Estado de la flota de conductores
import { useRealtimeSync } from '../composables/useRealtimeSync'  // Sincronización en tiempo real (Pusher + polling)
import { useDashboardMap } from '../composables/useDashboardMap'  // Toda la integración con Google Maps
import { useToast }        from '../composables/useToast'         // Sistema de notificaciones toast (no bloqueantes)

// ─────────────────────────────────────────────────────────────────────────────
// IMPORTS DE COMPONENTES HIJOS
// Cada componente es una pieza independiente de la UI. DashboardView actúa
// como componente "contenedor" o "página" que los orquesta.
// ─────────────────────────────────────────────────────────────────────────────
import StatsGrid          from '../components/dashboard/StatsGrid.vue'          // Fila de tarjetas con KPIs (pedidos, conductores, saldo)
import OrdersSidebar      from '../components/dashboard/OrdersSidebar.vue'      // Panel lateral izquierdo con lista de pedidos
import DashboardMap       from '../components/dashboard/DashboardMap.vue'       // Wrapper del mapa de Google Maps
import OrderDetailPanel   from '../components/dashboard/OrderDetailPanel.vue'   // Panel deslizable con detalle del pedido seleccionado
import FleetSidebar       from '../components/dashboard/FleetSidebar.vue'       // Panel lateral derecho con conductores activos
import ActivityFeed       from '../components/dashboard/ActivityFeed.vue'       // Feed con actividad reciente y acciones rápidas
import ToastContainer     from '../components/dashboard/ToastContainer.vue'     // Contenedor de notificaciones flotantes

// Modales que aparecen sobre el dashboard para crear pedidos
import CreateOrderModal       from '../components/CreateOrderModal.vue'        // Modal de pedido rápido (elige origen/destino en el mapa)
import CreateOrderManualModal from '../components/CreateOrderManualModal.vue'  // Modal de pedido manual (formulario completo)

// ─────────────────────────────────────────────────────────────────────────────
// ESTADO DE AUTENTICACIÓN
// ─────────────────────────────────────────────────────────────────────────────

// Instanciamos el store de Pinia. Como Pinia usa singletons, todos los componentes
// que llamen a `useAuthStore()` obtienen el mismo objeto compartido.
const authStore = useAuthStore()

// `role`: el rol del usuario autenticado ('superadmin' o 'client_admin').
// Es `computed` porque si el rol cambiara (improbable pero posible), el template
// se re-renderizaría automáticamente mostrando/ocultando las secciones correctas.
const role = computed(() => authStore.userRole)

// `userName`: nombre para el saludo en el header. El operador `?.` (optional chaining)
// evita un error si `authStore.user` es null durante la carga inicial.
// El `|| 'User'` es el valor por defecto si el nombre no está disponible.
const userName = computed(() => authStore.user?.name || 'User')

// ─────────────────────────────────────────────────────────────────────────────
// DESESTRUCTURACIÓN DE COMPOSABLES
// Al llamar a cada composable obtenemos un objeto con múltiples propiedades.
// Desestructuramos solo lo que este componente necesita.
// ─────────────────────────────────────────────────────────────────────────────

// useOrders devuelve el array reactivo de pedidos y las acciones para gestionarlos:
// - orders:        ref([]) con todos los pedidos del cliente logueado
// - selectedOrder: ref(null) — el pedido que el usuario tiene actualmente seleccionado
// - routeInfo:     ref(null) — info de la ruta del pedido seleccionado (distancia, tiempo)
// - selectOrder:   async fn que carga la ruta del pedido en Google Maps
// - clearSelection: limpia el pedido seleccionado y oculta el panel de detalle
// - cancelOrder:   envía DELETE/PATCH a la API para cancelar el pedido seleccionado
const { orders, selectedOrder, routeInfo, selectOrder, clearSelection, cancelOrder } = useOrders()

// useDrivers expone:
// - drivers:       ref([]) con todos los conductores de la flota
// - activeDrivers: computed con solo los conductores que tienen GPS reciente (activos)
const { drivers, activeDrivers } = useDrivers()

// useToast expone:
// - toasts:    ref([]) array de notificaciones activas (se renderizan en ToastContainer)
// - showToast: fn(message, type) para disparar una nueva notificación
const { toasts, showToast } = useToast()

// useDashboardMap encapsula toda la interacción con Google Maps:
// - isDriverEnRoute:    fn(driver, orders) → boolean, dice si el conductor tiene pedido activo
// - initDashboardMap:   inicializa o reinicializa el mapa con pedidos y conductores
// - resizeMap:          fuerza un reajuste del mapa si el contenedor cambia de tamaño
// - updateMapMarkers:   añade/mueve/elimina los marcadores del mapa según el estado actual
// - focusDriver:        centra el mapa en la posición de un conductor y lo resalta
// - destroyMap:         destruye la instancia de Google Maps y libera event listeners
const { isDriverEnRoute, initDashboardMap, resizeMap, updateMapMarkers, focusDriver, destroyMap } = useDashboardMap()

// useRealtimeSync gestiona la doble estrategia de sincronización:
// - startPolling:           arranca un setInterval que refresca datos periódicamente (respaldo)
// - stopPolling:            limpia el intervalo (llamado en onUnmounted)
// - setupRealtimeListeners: conecta a Pusher y suscribe a los canales del cliente
const { startPolling, stopPolling, setupRealtimeListeners } = useRealtimeSync()

// ─────────────────────────────────────────────────────────────────────────────
// ESTADO LOCAL DEL COMPONENTE
// ─────────────────────────────────────────────────────────────────────────────

// `stats`: KPIs que se muestran en las tarjetas del dashboard.
// Se inicializa todo en cero; `fetchDashboardData` los rellena con datos reales.
// - totalClients:  solo para superadmin — cantidad de clientes en la plataforma
// - totalDrivers:  conductores registrados en la flota del cliente
// - activeOrders:  pedidos en estados activos (publicado, tomado, en_camino, etc.)
// - balance:       saldo de créditos del cliente (o suma de todos para superadmin)
// - fleetBalance:  saldo acumulado de todos los conductores de la flota
const stats = ref({ totalClients: 0, totalDrivers: 0, activeOrders: 0, balance: 0, fleetBalance: 0 })

// `loading`: indica si la carga inicial de datos está en progreso.
// Se usa para mostrar spinners o bloquear interacciones mientras carga.
const loading = ref(true)

// `viewMode`: controla qué vista principal se muestra.
// - 'map':   vista operativa con mapa (usada por client_admin)
// - 'stats': vista de métricas (usada principalmente por superadmin)
const viewMode = ref('map')

// Flags de visibilidad de los modales de creación de pedidos.
// `v-if` en el template los monta/desmonta del DOM según estos valores.
const showCreateOrder = ref(false)
const showCreateOrderManual = ref(false)

// `showFeed`: alterna el área central del dashboard entre dos vistas:
// - true  → ActivityFeed (feed de actividad, acciones, estadísticas)
// - false → DashboardMap (mapa de Google Maps con pedidos y conductores)
// Controlado por el toggle switch en la barra de comando.
const showFeed = ref(true)

// `clientZones`: array con las zonas geográficas (geofences) del cliente.
// Se cargan en `fetchDashboardData` y se pasan al ActivityFeed para
// mostrar opciones de gestión de zonas si existen.
const clientZones = ref([])

// `hasZones`: devuelve `true` si hay al menos una zona configurada.
// Se pasa como prop al ActivityFeed para mostrar/ocultar el panel de zonas.
const hasZones = computed(() => clientZones.value.length > 0)

// ─────────────────────────────────────────────────────────────────────────────
// PROPIEDADES COMPUTADAS — FILTROS SOBRE EL ARRAY DE PEDIDOS
// ─────────────────────────────────────────────────────────────────────────────

// `pendingOrders`: pedidos con status 'pendiente' que tienen fecha programada en el FUTURO.
// Son pedidos agendados que aún no se han publicado en la plataforma.
// La fecha se parsea manualmente desde el string del backend (formato "YYYY-MM-DD HH:MM:SS")
// para evitar discrepancias de zona horaria que `new Date(string)` podría introducir.
const pendingOrders = computed(() => {
  const now = new Date()
  return orders.value.filter(o => o.status === 'pendiente' && o.scheduled_at && (() => {
    const p = o.scheduled_at.split(/[- :]/)
    return new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]), parseInt(p[3]), parseInt(p[4]), parseInt(p[5] || 0)) > now
  })())
})

// `scheduledOrders`: pedidos con status 'publicado'.
// Ya están en la plataforma y disponibles para que un conductor los tome.
const scheduledOrders = computed(() => orders.value.filter(o => o.status === 'publicado'))

// `activeOrdersList`: pedidos en curso — un conductor ya los aceptó y está en ruta.
// Los tres status representan las etapas del viaje:
// - 'tomado':   conductor aceptó el pedido y se dirige al punto de recogida
// - 'arribado': conductor llegó al punto de recogida
// - 'en_camino': conductor recogió la mercancía y va al destino
const activeOrdersList = computed(() => orders.value.filter(o => ['tomado', 'arribado', 'en_camino'].includes(o.status)))

// ─────────────────────────────────────────────────────────────────────────────
// UTILIDADES INTERNAS
// ─────────────────────────────────────────────────────────────────────────────

// Lista de status que cuentan como "pedido activo" en el KPI del dashboard.
// No incluye 'pendiente' porque esos pedidos aún no son visibles para conductores.
const activeStatuses = ['publicado', 'tomado', 'arribado', 'en_camino']

// Helper que recuenta el número de pedidos activos en el estado actual.
// Se llama tras cualquier operación que modifique `orders.value` para
// mantener `stats.value.activeOrders` siempre sincronizado.
const countActive = () => orders.value.filter(o => activeStatuses.includes(o.status)).length

// ─────────────────────────────────────────────────────────────────────────────
// CARGA INICIAL DE DATOS
// ─────────────────────────────────────────────────────────────────────────────

const fetchDashboardData = async () => {
  loading.value = true
  try {
    if (role.value === 'superadmin') {
      // Superadmin: orders y clients son independientes — se lanzan en paralelo
      const [ordersRes, clientsRes] = await Promise.all([
        api.get('/orders'),
        api.get('/clients'),
      ])
      if (ordersRes.data.status) { orders.value = ordersRes.data.data; stats.value.activeOrders = countActive() }
      if (clientsRes.data.status) {
        stats.value.totalClients = clientsRes.data.data.length
        stats.value.balance = clientsRes.data.data.reduce((a, c) => a + (parseFloat(c.credits_balance) || 0), 0)
      }

    } else if (role.value === 'client_admin') {
      // Client_admin: las 4 llamadas son independientes entre sí — se lanzan todas en paralelo
      const [ordersRes, driversRes, geofencesRes, meRes] = await Promise.all([
        api.get('/orders'),
        api.get('/drivers'),
        api.get('/geofences'),
        api.get('/auth/me'),
      ])
      if (ordersRes.data.status) { orders.value = ordersRes.data.data; stats.value.activeOrders = countActive() }
      if (driversRes.data.status) {
        drivers.value = driversRes.data.data
        stats.value.totalDrivers = drivers.value.length
        stats.value.fleetBalance = drivers.value.reduce((a, d) => a + (parseFloat(d.balance) || 0), 0)
      }
      clientZones.value = geofencesRes.data?.data ?? []
      stats.value.balance = parseFloat(meRes.data.data.client_balance) || 0

      if (viewMode.value === 'map' && !showFeed.value) {
        await nextTick()
        setTimeout(() => initDashboardMap({ orders: orders.value, drivers: drivers.value, isDriverEnRoute: d => isDriverEnRoute(d, orders.value) }), 800)
      }
    }
  } catch (e) { console.error('Error fetching dashboard data:', e) }
  finally { loading.value = false }
}

// ─────────────────────────────────────────────────────────────────────────────
// CONTEXTO DEL MAPA — HELPER
// ─────────────────────────────────────────────────────────────────────────────

// `mapCtx` construye el objeto de contexto que necesitan las funciones del composable
// de mapa (updateMapMarkers, focusDriver, etc.) en cada llamada.
// Se define como función en lugar de computed para capturar los valores `.value`
// en el momento exacto de la llamada, no en el momento de creación del closure.
// Esto evita que el mapa use datos desactualizados.
const mapCtx = () => ({
  drivers: drivers.value, orders: orders.value,
  isDriverEnRoute: d => isDriverEnRoute(d, orders.value),
  selectedOrder: selectedOrder.value, clearSelection, showToast
})

// ─────────────────────────────────────────────────────────────────────────────
// MANEJADORES DE EVENTOS (EVENT HANDLERS)
// Funciones que responden a los eventos emitidos por los componentes hijos.
// ─────────────────────────────────────────────────────────────────────────────

// Ejecutado cuando CreateOrderModal o CreateOrderManualModal emiten '@created'.
// Agrega el nuevo pedido al INICIO del array (prepend) para que aparezca primero
// en el sidebar, actualiza el contador de activos y refresca el mapa.
const onOrderCreated = (newOrder) => {
  orders.value = [newOrder, ...orders.value]
  stats.value.activeOrders = countActive()
  updateMapMarkers(mapCtx())
}

// Ejecutado cuando el usuario hace clic en un pedido del OrdersSidebar.
// Delega la lógica en el composable `useOrders.selectOrder`, que se encarga de
// llamar a la Directions API y dibujar la ruta en el mapa.
const handleSelectOrder = async (order) => {
  await selectOrder(order, { drivers: drivers.value, createDriverMapIcon: undefined, redrawDrivers: () => updateMapMarkers(mapCtx()) })
}

// Ejecutado cuando el usuario confirma la cancelación desde el OrderDetailPanel.
// Delega toda la lógica al composable (llamada API, toast de confirmación, etc.).
const handleCancelOrder = async () => { await cancelOrder({ showToast, clearSelection, fetchDashboardData }) }

// Ejecutado cuando el usuario hace clic en un conductor en el FleetSidebar.
// Centra y hace zoom en la posición del conductor en el mapa.
const handleFocusDriver = (driver) => { focusDriver(driver, { orders: orders.value, drivers: drivers.value, isDriverEnRoute: d => isDriverEnRoute(d, orders.value) }) }

// ─────────────────────────────────────────────────────────────────────────────
// Watch: Al volver al mapa, reinicializar completamente
// ─────────────────────────────────────────────────────────────────────────────
watch(showFeed, (newVal) => {
  if (!newVal) {
    // showFeed pasó a false → estamos volviendo al mapa
    // El contenedor #map-root fue recreado por v-if, por lo que
    // debemos reinicializar el mapa con todos los datos actuales.
    // Usamos flush:'post' para garantizar que el DOM ya se actualizó
    // y el <div id="map-root"> existe antes de inicializar el mapa.
    nextTick(() => {
      initDashboardMap({
        orders: orders.value,
        drivers: drivers.value,
        isDriverEnRoute: d => isDriverEnRoute(d, orders.value)
      })
    })
  }
}, { flush: 'post' })

// ─────────────────────────────────────────────────────────────────────────────
// CICLO DE VIDA: onMounted
// Se ejecuta UNA VEZ, justo después de que el componente está en el DOM.
// Aquí iniciamos todas las estrategias de carga y sincronización de datos.
// ─────────────────────────────────────────────────────────────────────────────
onMounted(async () => {
  // Paso 1: Carga inicial de todos los datos (pedidos, conductores, stats, zonas)
  await fetchDashboardData()

  // Paso 2: Arranca el polling periódico como mecanismo de respaldo.
  // Si Pusher falla o no hay conexión WebSocket, el polling garantiza
  // que los datos se actualicen igual (cada X segundos).
  startPolling({ role, orders, drivers, stats, showToast, updateMapMarkers: () => updateMapMarkers(mapCtx()) })

  // Paso 3: Conectar a Pusher para recibir eventos del servidor en tiempo real.
  // Solo el rol 'client_admin' tiene canales de Pusher propios.
  if (role.value === 'client_admin') {
    // Obtenemos el ID del cliente al que pertenece este operador.
    // Se intenta primero con la relación anidada `user.client.id`, y como
    // fallback con `user.client_id` (campo plano en el objeto usuario).
    const clientId = authStore.user?.client?.id ?? authStore.user?.client_id
    if (clientId) {
      setupRealtimeListeners({
        clientId,

        // Evento: un pedido pendiente fue publicado y ya está disponible para conductores.
        // Optimización: si el pedido ya estaba en memoria, actualizamos solo su status
        // sin hacer un refetch completo (menos carga de red).
        // Si no estaba (pedido creado desde otro dispositivo), sí hacemos refetch.
        onNewTrip: async (data) => {
          if (data?.trip_id) {
            const idx = orders.value.findIndex(o => String(o.id) === String(data.trip_id))
            if (idx !== -1) {
              orders.value[idx] = { ...orders.value[idx], status: 'publicado' }
            } else {
              const res = await api.get('/orders')
              if (res.data.status) orders.value = res.data.data
            }
            stats.value.activeOrders = countActive()
          }
          updateMapMarkers(mapCtx())
        },

        // Evento: un conductor aceptó un pedido.
        // El payload incluye driver_id — se aplica directamente sin refetch.
        onTripTaken: (data) => {
          if (data?.trip_id) {
            const idx = orders.value.findIndex(o => String(o.id) === String(data.trip_id))
            if (idx !== -1) {
              orders.value[idx] = {
                ...orders.value[idx],
                status: 'tomado',
                ...(data.driver_id && { driver_id: data.driver_id }),
              }
              stats.value.activeOrders = countActive()
            }
          }
          updateMapMarkers(mapCtx())
        },

        // Evento: el status de un pedido cambió durante el viaje.
        // El payload incluye trip_id y status — se aplica directamente sin refetch.
        onTripUpdated: (data) => {
          if (data?.trip_id && data?.status) {
            const idx = orders.value.findIndex(o => String(o.id) === String(data.trip_id))
            if (idx !== -1) {
              orders.value[idx] = { ...orders.value[idx], status: data.status }
              stats.value.activeOrders = countActive()
              showToast(`Pedido #${data.trip_id} → ${data.status}`, 'info')
            }
          }
          updateMapMarkers(mapCtx())
        },

        // Evento: un pedido fue cancelado.
        // Mutación local: se elimina del array y el saldo se actualiza desde el payload.
        onOrderCancelled: ({ order_id, new_balance }) => {
          if (selectedOrder.value?.id === order_id) clearSelection()
          orders.value = orders.value.filter(o => String(o.id) !== String(order_id))
          stats.value.activeOrders = countActive()
          if (new_balance !== undefined) stats.value.balance = new_balance
          updateMapMarkers(mapCtx())
        },

        // Evento: el conductor envió su ubicación GPS desde la app.
        // Actualización quirúrgica: solo modificamos `current_lat` y `current_lng`
        // del conductor específico, sin refetch completo. Esto permite la animación
        // suave del marcador en el mapa sin saturar el servidor.
        // `_pusherTs` registra el timestamp para el sistema de animación del marcador.
        onDriverLocation: ({ driver_id, lat, lng }) => {
          const driver = drivers.value.find(d => String(d.id) === String(driver_id))
          if (driver) {
            driver.current_lat = lat
            driver.current_lng = lng
            driver._pusherTs = Date.now()
            // Solo actualizamos el mapa si está visible; si el feed está abierto,
            // no hay marcadores que actualizar (el mapa no existe en el DOM).
            if (!showFeed.value) updateMapMarkers(mapCtx())
          }
        }
      })
    }
  }
})

// ─────────────────────────────────────────────────────────────────────────────
// CICLO DE VIDA: onUnmounted
// Se ejecuta cuando el usuario navega a otra ruta y Vue destruye este componente.
// CRÍTICO: limpiar recursos para evitar memory leaks y callbacks huérfanos.
// ─────────────────────────────────────────────────────────────────────────────
onUnmounted(() => {
  stopPolling() // Cancela el setInterval del polling para que no siga ejecutándose en segundo plano
  destroyMap()  // Destruye la instancia de Google Maps y desregistra sus event listeners
})
</script>


<template>
  <!-- Contenedor raíz del dashboard. Ocupa todo el alto disponible (definido en CSS). -->
  <div class="dashboard">

    <!--
      Header de bienvenida: solo visible para el superadmin o cuando se está en vista de stats.
      El client_admin en vista de mapa no lo muestra para maximizar el espacio del mapa.
    -->
    <header class="dashboard-header" v-if="role === 'superadmin' || viewMode === 'stats'">
      <div class="welcome">
        <h1>¡Bienvenido de nuevo, {{ userName }}! 👋</h1>
        <p>Esto es lo que está pasando en tu plataforma hoy.</p>
      </div>
    </header>

    <!--
      StatsGrid: fila de tarjetas con KPIs.
      Misma condición que el header: solo visible para superadmin o en vista stats.
      Recibe `stats` (los valores numéricos) y `role` (para saber qué tarjetas mostrar).
    -->
    <StatsGrid
      v-if="role === 'superadmin' || viewMode === 'stats'"
      :stats="stats"
      :role="role"
    />

    <!--
      ════════════════════════════════════════════════════════════════
      VISTA OPERATIVA (client_admin + viewMode='map')
      Esta sección solo existe para el operador de flota (client_admin).
      Contiene la barra de comando, el mapa/feed y los paneles laterales.
      ════════════════════════════════════════════════════════════════
    -->
    <div v-if="role === 'client_admin' && viewMode === 'map'" class="dashboard-map-view">

      <!-- Franja de color decorativa en la parte superior (gradiente violeta-rosa) -->
      <div class="map-ambient-strip" aria-hidden="true"></div>

      <!--
        BARRA DE COMANDO: zona de control en la parte superior de la vista operativa.
        Muestra el nombre del operador, el toggle de vista y los chips de métricas.
      -->
      <div class="map-command-bar">
        <div class="map-command-bar__user">
          <span class="map-command-bar__greeting">Panel operativo</span>
          <span class="map-command-bar__name">{{ userName }}</span>

          <!--
            Toggle switch para alternar entre el Mapa y el ActivityFeed.
            - `v-model="showFeed"` enlaza el checkbox a la variable reactiva.
            - El emoji cambia dinámicamente según el estado:
              🗺️ cuando el feed está activo (indica que al pulsar verás el mapa)
              📋 cuando el mapa está activo (indica que al pulsar verás el feed)
            - El input está visualmente oculto (`display:none` en CSS); el
              aspecto visual del toggle se logra con `.view-toggle__slider`.
          -->
          <label class="view-toggle" title="Cambiar vista">
            <span class="view-toggle__icon">{{ showFeed ? '🗺️' : '📋' }}</span>
            <input type="checkbox" v-model="showFeed" class="view-toggle__input" />
            <span class="view-toggle__slider"></span>
          </label>
        </div>

        <!--
          Chips de métricas en tiempo real: muestran un resumen operativo rápido.
          Los valores vienen del objeto reactivo `stats` que se actualiza con cada fetch.
        -->
        <div class="map-command-bar__chips">
          <span class="stat-chip stat-chip--queue"><span class="stat-chip__dot"></span>{{ stats.activeOrders }} en cola</span>
          <span class="stat-chip stat-chip--fleet">{{ stats.totalDrivers }} conductores</span>
          <span class="stat-chip stat-chip--balance">Saldo ${{ stats.balance.toFixed(2) }}</span>
        </div>
      </div>

      <!--
        ÁREA PRINCIPAL: contenedor flex-row con todos los paneles del dashboard.
        De izquierda a derecha: OrdersSidebar | Mapa o Feed | OrderDetailPanel | FleetSidebar
      -->
      <div class="dashboard-map-container dashboard-map-container--row">

        <!--
          Panel lateral IZQUIERDO con la lista de pedidos agrupados por estado.
          Recibe los pedidos ya filtrados por estado (computed properties).
          Emite '@select-order' cuando el usuario hace clic en un pedido.
        -->
        <OrdersSidebar
          :pending-orders="pendingOrders"
          :scheduled-orders="scheduledOrders"
          :active-orders-list="activeOrdersList"
          :selected-order="selectedOrder"
          :drivers="drivers"
          @select-order="handleSelectOrder"
        />

        <!--
          ÁREA CENTRAL — se alterna entre mapa y feed mediante `v-if/v-else`.
          v-if destruye/recrea el componente del DOM cuando cambia showFeed.
          Eso es INTENCIONAL: cuando DashboardMap desaparece, Google Maps también
          se destruye. Cuando vuelve a aparecer, el watcher lo reinicializa.
        -->
        <DashboardMap v-if="!showFeed" />

        <!--
          ActivityFeed: vista alternativa al mapa. Muestra actividad reciente,
          stats detalladas y botones de acción rápida para crear pedidos.
          Emite '@create-order' y '@create-order-manual' para abrir los modales.
        -->
        <div v-else class="activity-feed-container">
          <ActivityFeed
            :orders="orders"
            :drivers="drivers"
            :stats="stats"
            :has-zones="hasZones"
            @create-order="showCreateOrder = true"
            @create-order-manual="showCreateOrderManual = true"
          />
        </div>


        <!--
          Panel deslizable de DETALLE DEL PEDIDO.
          Solo visible cuando hay un `selectedOrder`. Muestra la info completa del
          pedido seleccionado: conductor asignado, ruta, tiempo estimado, etc.
          Emite '@close' para desseleccionar y '@cancel' para cancelar el pedido.
        -->
        <OrderDetailPanel
          :selected-order="selectedOrder"
          :route-info="routeInfo"
          @close="clearSelection"
          @cancel="handleCancelOrder"
        />

        <!--
          Panel lateral DERECHO con la flota de conductores activos.
          Muestra los conductores con GPS reciente y su estado de disponibilidad.
          Emite '@focus-driver' cuando el operador hace clic en un conductor
          para centrar el mapa en su posición.
        -->
        <FleetSidebar
          :active-drivers="activeDrivers"
          :is-driver-en-route="(driver) => isDriverEnRoute(driver, orders)"
          @focus-driver="handleFocusDriver"
        />

      </div>
    </div>


    <!--
      ToastContainer: contenedor de notificaciones flotantes (toasts).
      Siempre presente en el DOM (fuera de los v-if de vista) para poder
      mostrar notificaciones independientemente de qué vista esté activa.
    -->
    <ToastContainer :toasts="toasts" />

    <!--
      Modales de creación de pedidos.
      `v-if` los monta en el DOM solo cuando son necesarios (mejor rendimiento).
      Cuando se cierran, `v-if=false` los desmonta completamente, reseteando su estado interno.
      '@created' lleva el nuevo pedido al handler `onOrderCreated` para actualizar la lista.
    -->
    <CreateOrderModal
      v-if="showCreateOrder"
      @close="showCreateOrder = false"
      @created="onOrderCreated"
    />

    <CreateOrderManualModal
      v-if="showCreateOrderManual"
      @close="showCreateOrderManual = false"
      @created="onOrderCreated"
    />
  </div>
</template>

<style scoped>
/*
  `scoped` hace que estos estilos SOLO afecten a los elementos de este componente.
  Vue añade un atributo data-v-xxxxxxxx único a cada elemento del template y
  a cada selector CSS para garantizar el aislamiento.
*/

/* El dashboard ocupa toda la altura disponible debajo del topbar (variable CSS global) */
.dashboard { display: flex; flex-direction: column; height: calc(100vh - var(--topbar-height)); }

/* Header y stats no deben crecer ni encogerse — ocupan su tamaño natural */
.dashboard-header, .stats-grid { flex-shrink: 0; }
.dashboard-header { padding: 1.5rem 1.5rem 0; }
.dashboard-header .welcome h1 { font-size: 1.35rem; font-weight: 800; margin: 0 0 0.25rem; color: #0f172a; }
.dashboard-header .welcome p { margin: 0; color: #64748b; font-size: 0.9rem; }

/* La vista del mapa ocupa el espacio restante después del header y stats (flex: 1) */
.dashboard-map-view { flex: 1; display: flex; flex-direction: column; min-height: 0; position: relative; }

/* Franja decorativa con gradiente, posicionada sobre el borde superior de la vista */
.map-ambient-strip { position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg,#6366F1,#8B5CF6,#EC4899); z-index: 10; }

/* Barra de comando: layout flex horizontal con espacio entre izquierda y derecha */
.map-command-bar { display: flex; align-items: center; justify-content: space-between; padding: 0.65rem 1.25rem; background: #fff; border-bottom: 1px solid #e2e8f0; flex-shrink: 0; }
.map-command-bar__user { display: flex; align-items: center; gap: 0.5rem; }
.map-command-bar__greeting { font-size: 0.8rem; color: #64748b; font-weight: 500; }
.map-command-bar__name { font-size: 0.9rem; font-weight: 700; color: #0f172a; }
.map-command-bar__chips { display: flex; gap: 0.5rem; }

/* Chips de métricas: píldoras de colores con texto compacto */
.stat-chip { padding: 0.3rem 0.75rem; border-radius: 999px; font-size: 0.75rem; font-weight: 700; display: flex; align-items: center; gap: 0.35rem; }
.stat-chip--queue { background: #FEF3C7; color: #92400E; }    /* Amarillo — pedidos en cola */
.stat-chip--fleet { background: #DBEAFE; color: #1E40AF; }    /* Azul — conductores */
.stat-chip--balance { background: #D1FAE5; color: #065F46; }  /* Verde — saldo */

/* Punto pulsante animado en el chip de pedidos en cola: indica actividad en tiempo real */
.stat-chip__dot { width: 6px; height: 6px; border-radius: 50%; background: #F59E0B; animation: pulse-dot 2s infinite; }
@keyframes pulse-dot { 0%,100% { opacity: 1; } 50% { opacity: 0.3; } }

/*
  Contenedor del área principal (sidebar + mapa/feed + paneles).
  `flex: 1` hace que ocupe todo el espacio vertical disponible.
  `min-height: 0` es necesario en items flex anidados para que el scroll interno funcione.
*/
.dashboard-map-container--row { flex: 1; display: flex; min-height: 0; position: relative; }

/* ActivityFeed container — ocupa el mismo espacio que el mapa */
.activity-feed-container {
  flex: 1;
  position: relative;
  height: 100%;
  min-width: 0;
  overflow-y: auto;
}

/* View Toggle Switch */
.view-toggle {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  cursor: pointer;
  margin-left: 0.5rem;
  padding: 0.2rem 0.4rem;
  border-radius: 999px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  transition: background 0.2s;
}
/* :has() comprueba si el label CONTIENE un checkbox marcado — cambio de estilo cuando está activo */
.view-toggle:has(input:checked) {
  background: #e0e7ff;
  border-color: #6366F1;
}
.view-toggle__icon { font-size: 0.85rem; line-height: 1; }
.view-toggle__input { display: none; } /* El input real está oculto; el aspecto visual lo da el slider */

/* Pista del toggle switch */
.view-toggle__slider {
  width: 28px;
  height: 16px;
  background: #cbd5e1;
  border-radius: 999px;
  position: relative;
  transition: background 0.2s;
}
/* El círculo blanco del toggle (perilla) */
.view-toggle__slider::after {
  content: '';
  position: absolute;
  top: 2px;
  left: 2px;
  width: 12px;
  height: 12px;
  background: #fff;
  border-radius: 50%;
  transition: transform 0.2s;
  box-shadow: 0 1px 2px rgba(0,0,0,0.15);
}
/* Cuando el checkbox está marcado: pista azul y perilla desplazada 12px a la derecha */
.view-toggle:has(input:checked) .view-toggle__slider { background: #6366F1; }
.view-toggle:has(input:checked) .view-toggle__slider::after { transform: translateX(12px); }
</style>


