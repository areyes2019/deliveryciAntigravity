<!-- ==================================================================
  📝 CreateOrderManualModal – FORMULARIO MANUAL TRADICIONAL
  ==================================================================
  Modal independiente con el formulario manual original.
  Conserva 100% de la lógica existente:
  - Google Maps + autocomplete
  - pricing + geofences
  - cálculo de rutas + watchers
  - saveOrder original
  - payment methods + scheduling
  - eventos emit
  ================================================================== -->
<script setup>
/**
 * Importaciones de Vue 3 Composition API:
 * - ref()       → crea una variable reactiva simple (el valor vive en .value)
 * - computed()  → propiedad derivada; se recalcula sola cuando sus dependencias cambian
 * - onMounted() → hook que se ejecuta UNA vez, después de que el componente está en el DOM
 * - watch()     → observa uno o varios valores reactivos y ejecuta lógica al detectar cambios
 */
import { ref, computed, onMounted, watch } from 'vue'

// Instancia de Axios preconfigurada con baseURL de la API y token Bearer en los headers
import api from '../api'

// Servicio propio que envuelve la carga del SDK de Google Maps.
// ensureSDKLoaded() devuelve una Promise que se resuelve cuando el script de Maps ya está disponible.
import MapService from '../services/maps/MapService'

/**
 * defineEmits declara los eventos que este componente puede lanzar al padre.
 * El padre los escucha con: <CreateOrderManualModal @close="..." @created="..." />
 *
 * - 'close'   → el padre debe ocultar este modal (no recibe argumentos)
 * - 'created' → el pedido fue creado; se pasa el objeto del pedido para que el padre actualice su lista
 */
const emit = defineEmits(['close', 'created'])

// ─── Estado global del componente ────────────────────────────────────────────

// Saldo expresado en "viajes prepagados" (no en dinero directo).
// Se calcula como: Math.floor(dinero_en_cuenta / costo_por_viaje)
const userBalance = ref(0)

// Precio del envío calculado dinámicamente según la ruta y las reglas de pricing del backend.
// Se resetea a 0 cada vez que cambian las coordenadas y se vuelve a calcular.
const calculatedPrice = ref(0)

// Texto legible de la distancia de la ruta, ej. "4.2 km".
// Lo proporciona la API de Google Directions; vacío si la ruta aún no fue calculada.
const routeDistance = ref('')

// Texto legible del tiempo estimado de viaje, ej. "12 min".
// Lo proporciona la API de Google Directions; vacío si la ruta aún no fue calculada.
const routeTime = ref('')

// Mensaje de error cuando algún punto (pickup o drop) cae fuera de la zona de operación.
// Si tiene contenido, el botón "Publicar viaje" queda deshabilitado.
const outOfZoneError = ref('')

// Bandera para evitar doble submit: true mientras la petición POST /orders está en vuelo.
const submitting = ref(false)

// Lista de polígonos de geofence del cliente (zonas de servicio).
// Se usa para dos cosas: 1) restringir el autocomplete de Places a esa área,
// 2) el backend los valida en /validate-geofence.
const clientZones = ref([])

// ─── Helper: obtener fecha local YYYY-MM-DD ──────────────────────────────────
/**
 * Formatea un objeto Date como "YYYY-MM-DD" usando la hora LOCAL del dispositivo,
 * evitando el problema de UTC que tiene .toISOString() (puede dar el día anterior
 * si el usuario está en una zona horaria detrás de UTC).
 */
const getLocalDateStr = (date) => {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

// ─── Scheduling ───────────────────────────────────────────────────────────────
/**
 * Máquina de estados simple para la programación del envío.
 * scheduleMode tiene dos estados posibles:
 *   'asap'      → "Lo antes posible" (valor por defecto); el pedido se publica sin fecha futura
 *   'scheduled' → "Programar envío"; muestra el panel de selección de fecha y hora
 */
const scheduleMode = ref('asap')

// Fecha seleccionada en formato "YYYY-MM-DD" (ej. "2026-06-13"). null = no seleccionada.
const scheduledDate = ref(null)

// Hora seleccionada en formato 12hr. Se combina con scheduledAmpm para construir el horario final.
const scheduledHour = ref('12')

// Minutos seleccionados. Las opciones disponibles son: 00, 15, 30, 45 (cuartos de hora).
const scheduledMinute = ref('00')

// Periodo del día: 'AM' o 'PM'. Junto con scheduledHour conforma la hora en formato 12hr.
const scheduledAmpm = ref('PM')

// Computed que devuelve la fecha de HOY como string "YYYY-MM-DD".
// Es computed (no una variable estática) para que siempre refleje el día actual al abrir el modal.
const todayDate = computed(() => {
  return getLocalDateStr(new Date())
})

// Fecha de MAÑANA como string "YYYY-MM-DD".
const tomorrowDate = computed(() => {
  const d = new Date(); d.setDate(d.getDate() + 1)
  return getLocalDateStr(d)
})

// Fecha de PASADO MAÑANA como string "YYYY-MM-DD".
const afterTomorrowDate = computed(() => {
  const d = new Date(); d.setDate(d.getDate() + 2)
  return getLocalDateStr(d)
})

/**
 * Array de opciones para el selector de día de programación.
 * Genera dinámicamente las tres fechas posibles con su etiqueta en español.
 * Se usa en el template con v-for para renderizar los "day chips".
 */
const dayOptions = computed(() => {
  const now = new Date()
  const today = getLocalDateStr(now)
  const tomorrow = new Date(now); tomorrow.setDate(tomorrow.getDate() + 1)
  const afterTomorrow = new Date(now); afterTomorrow.setDate(afterTomorrow.getDate() + 2)
  return [
    { label: 'Hoy', value: today },
    { label: 'Mañana', value: getLocalDateStr(tomorrow) },
    { label: 'Pasado mañana', value: getLocalDateStr(afterTomorrow) }
  ]
})

/**
 * Devuelve la fecha seleccionada en formato legible "DD Mes" (ej. "13 Jun").
 * Solo se muestra en el time-picker, una vez que el usuario ya eligió un día.
 */
const formattedScheduleDate = computed(() => {
  if (!scheduledDate.value) return ''
  const [y, m, day] = scheduledDate.value.split('-')
  const names = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']
  return `${day} ${names[parseInt(m) - 1]}`
})

/**
 * Validación de hora para envíos programados el mismo día.
 * Si el usuario seleccionó "Hoy" y la hora elegida ya pasó, devuelve un mensaje de error.
 * Para días futuros (mañana, pasado mañana) siempre devuelve cadena vacía (sin error).
 *
 * El flujo de conversión 12hr → Date:
 *   1. Parsear la hora del picker (1–12)
 *   2. Ajustar según AM/PM al formato 24hr (0–23)
 *   3. Construir un objeto Date con esa hora en el día de hoy
 *   4. Comparar contra new Date() (ahora mismo)
 */
const scheduleTimeError = computed(() => {
  if (!scheduledDate.value || scheduleMode.value !== 'scheduled') return ''

  const now = new Date()
  const todayStr = getLocalDateStr(now)

  // Solo validar si es HOY
  if (scheduledDate.value !== todayStr) return ''

  // Construir la hora seleccionada
  let h = parseInt(scheduledHour.value)
  if (scheduledAmpm.value === 'PM' && h !== 12) h += 12
  if (scheduledAmpm.value === 'AM' && h === 12) h = 0
  const m = parseInt(scheduledMinute.value)

  const selectedTime = new Date(now.getFullYear(), now.getMonth(), now.getDate(), h, m, 0)

  if (selectedTime <= now) {
    return 'La hora seleccionada ya pasó'
  }
  return ''
})

/**
 * Controla si el formulario puede avanzar a publicar el viaje en lo que respecta al scheduling.
 * - Si el modo es 'asap', siempre es válido (no hay fecha que validar)
 * - Si el modo es 'scheduled', necesita que se haya seleccionado un día Y que la hora sea válida
 */
const canSchedule = computed(() => {
  if (scheduleMode.value === 'asap') return true
  return scheduledDate.value && !scheduleTimeError.value
})

/**
 * Asigna la fecha seleccionada al ref scheduledDate a partir de un identificador de día.
 * @param {'today' | 'tomorrow' | 'after_tomorrow'} which - Qué día seleccionar
 */
const setScheduleDate = (which) => {
  if (which === 'today') {
    scheduledDate.value = todayDate.value
  } else if (which === 'tomorrow') {
    scheduledDate.value = tomorrowDate.value
  } else if (which === 'after_tomorrow') {
    scheduledDate.value = afterTomorrowDate.value
  }
}

/**
 * Activa el modo de programación y preselecciona "mañana" como día por defecto.
 * Se preselecciona mañana (en lugar de hoy) para evitar que el usuario encuentre
 * inmediatamente el error de "hora ya pasó" al abrir el panel.
 */
const enableScheduling = () => {
  scheduleMode.value = 'scheduled'
  // Por defecto seleccionar "Mañana" para evitar conflicto con hora actual
  scheduledDate.value = tomorrowDate.value
}

/**
 * Restablece el scheduling a su estado inicial ("lo antes posible").
 * Se llama al hacer clic en el botón "✕ Quitar".
 */
const clearSchedule = () => {
  scheduleMode.value = 'asap'
  scheduledDate.value = null
  scheduledHour.value = '12'
  scheduledMinute.value = '00'
  scheduledAmpm.value = 'PM'
}

// ─── Variables del mapa (fuera de ref para no hacerlas reactivas) ─────────────
/**
 * IMPORTANTE: Estas variables son instancias de objetos de Google Maps.
 * Se declaran como variables de módulo (let) en lugar de ref() deliberadamente:
 * hacer reactivos objetos de Google Maps causaría que Vue los envuelva en un Proxy,
 * lo que rompe la referencia interna que Maps necesita para funcionar correctamente.
 */
let mapInstance = null       // google.maps.Map – el mapa renderizado en el DOM
let pickupMarker = null      // Marcador verde del punto de recogida
let dropMarker = null        // Marcador rojo del punto de entrega
let directionsService = null // google.maps.DirectionsService – calcula rutas entre dos puntos
let directionsRenderer = null // google.maps.DirectionsRenderer – dibuja la ruta en el mapa

// ─── Modelo del formulario ────────────────────────────────────────────────────
/**
 * Objeto reactivo que contiene todos los campos del pedido.
 * Este objeto se envía directamente al endpoint POST /orders.
 *
 * Campos de dirección: pickup_address y drop_address se llenan desde el autocomplete de Places.
 * Campos de coordenadas: pickup_lat/lng y drop_lat/lng se llenan al seleccionar una dirección.
 *   Si son null, la ruta no puede calcularse y el botón está deshabilitado.
 * payment_type: controla qué tipo de pago se aplicará:
 *   - 'prepaid'          → el remitente paga con saldo prepagado
 *   - 'cash_on_delivery' → el receptor paga solo el costo del envío en efectivo
 *   - 'cash_full'        → el receptor paga envío + valor del producto en efectivo
 * product_amount: solo aplica cuando payment_type es 'cash_full'
 * distance_km: distancia real de la ruta (la calcula Google Directions)
 * scheduled_at: datetime string "YYYY-MM-DD HH:mm:ss" en zona horaria local.
 *   NUNCA se envía null; si es "asap" se usa el momento actual.
 */
const form = ref({
  pickup_address: '',
  drop_address: '',
  receiver_name: '',
  receiver_phone: '',
  description: '',
  payment_type: 'prepaid',
  product_amount: null,
  pickup_lat: null,
  pickup_lng: null,
  drop_lat: null,
  drop_lng: null,
  distance_km: null,
  scheduled_at: null
})

// ─── Template refs para los contenedores de autocomplete ─────────────────────
/**
 * ref() sobre un elemento del template actúa como una referencia al nodo DOM real.
 * Se usa aquí para inyectar el web component <gmp-place-autocomplete> de Google
 * dentro del div correspondiente. Ver: attachAutocomplete().
 */
const pickupContainer = ref(null) // div#pickup que recibirá el PlaceAutocompleteElement
const dropContainer = ref(null)   // div#drop que recibirá el PlaceAutocompleteElement

/**
 * El cliente puede publicar un viaje solo si tiene al menos 1 viaje prepagado disponible.
 * Se recalcula automáticamente cuando userBalance cambia (después de loadBalance).
 */
const canAffordOrder = computed(() => userBalance.value >= 1)

/**
 * Calcula cuánto dinero deberá cobrar el repartidor al entregar el paquete.
 * Depende del tipo de pago seleccionado:
 *   - 'prepaid'          → $0 (el cliente ya pagó con saldo)
 *   - 'cash_on_delivery' → solo el costo del envío (el receptor paga el flete)
 *   - 'cash_full'        → costo del envío + valor del producto (el receptor paga todo)
 */
const totalToCollect = computed(() => { 
  if (form.value.payment_type === 'prepaid') return 0
  if (form.value.payment_type === 'cash_on_delivery') return calculatedPrice.value
  if (form.value.payment_type === 'cash_full') {
    return calculatedPrice.value + (parseFloat(form.value.product_amount) || 0)
  }
  return 0
})

// ─── Load balance ─────────────────────────────────────────────────────────────
/**
 * Obtiene el saldo del cliente desde el endpoint GET /auth/me y lo convierte a "viajes".
 * La lógica de conversión:
 *   viajes_disponibles = Math.floor(saldo_monetario / costo_por_viaje)
 *
 * ¿Por qué mostrar "viajes" y no pesos? Para que el cliente entienda directamente
 * cuántas entregas puede hacer, sin necesitar calcular mentalmente el costo.
 *
 * Se llama al montar el componente Y también al inicio de saveOrder (para tener
 * el saldo más reciente justo antes de crear el pedido).
 */
const loadBalance = async () => {
  try {
    const meRes = await api.get('/auth/me')
    if (meRes.data.status) {
      const totalMoney = parseFloat(meRes.data.data.client_balance) || 0;
      const costPerTrip = parseFloat(meRes.data.data.cost_per_trip) || 1;
      userBalance.value = costPerTrip > 0 ? Math.floor(totalMoney / costPerTrip) : totalMoney;
    }
  } catch (e) {
    console.error('Error cargando balance:', e)
  }
}

// ─── Calcula bounding box dinámico desde las zonas del cliente ───────────────
/**
 * Construye un google.maps.LatLngBounds que engloba todos los polígonos de las
 * zonas de servicio del cliente. Este bounding box se usa en attachAutocomplete()
 * para restringir las sugerencias de Places a esa área geográfica.
 *
 * Los polígonos pueden venir como string JSON o como array ya parseado,
 * por eso se maneja ambos casos.
 *
 * @returns {google.maps.LatLngBounds|null} - null si no hay zonas o Maps no está listo
 */
const computeZoneBounds = () => {
  if (!clientZones.value.length || !window.google?.maps) return null
  const bounds = new google.maps.LatLngBounds()
  let hasPoints = false
  for (const zone of clientZones.value) {
    try {
      const coords = typeof zone.polygon_coordinates === 'string'
        ? JSON.parse(zone.polygon_coordinates)
        : zone.polygon_coordinates
      if (Array.isArray(coords)) {
        coords.forEach(p => { bounds.extend({ lat: p.lat, lng: p.lng }); hasPoints = true })
      }
    } catch {}
  }
  return hasPoints ? bounds : null
}

// ─── Attach PlaceAutocompleteElement to a container div ──────────────────────
/**
 * Crea e inyecta un PlaceAutocompleteElement (nueva API de Google Places) dentro de un div.
 * Esta función abstrae el proceso de agregar autocompletado a cualquier campo de dirección.
 *
 * ¿Por qué PlaceAutocompleteElement en lugar del antiguo Autocomplete widget?
 * La API clásica (google.maps.places.Autocomplete) fue deprecada en 2024.
 * PlaceAutocompleteElement es el web component oficial de reemplazo.
 *
 * Estrategia de restricción geográfica (solo se puede usar UNA a la vez):
 *   - Si hay zonas de servicio del cliente → locationRestriction (fuerza resultados dentro del área)
 *   - Si no hay zonas → locationBias (prefiere resultados en Celaya pero no los limita)
 *
 * @param {HTMLElement} containerEl  - El div donde se inyectará el autocomplete
 * @param {string} addressField      - Nombre del campo en form.value para guardar la dirección (ej. 'pickup_address')
 * @param {string} latField          - Nombre del campo en form.value para la latitud (ej. 'pickup_lat')
 * @param {string} lngField          - Nombre del campo en form.value para la longitud (ej. 'pickup_lng')
 */
const attachAutocomplete = (containerEl, addressField, latField, lngField) => {
  if (!window.google?.maps?.places) {
    console.warn('Places API no disponible aun.')
    return
  }

  // Bounds dinamicos si hay zonas; fallback a Celaya
  const dynamicBounds = computeZoneBounds()
  const celayaBounds  = new google.maps.LatLngBounds(
    new google.maps.LatLng(20.42, -101.05),
    new google.maps.LatLng(20.72, -100.60)
  )
  const activeBounds = dynamicBounds ?? celayaBounds

  /**
   * PlaceAutocompleteElement: web component nativo de Google.
   * - includedRegionCodes: limita sugerencias a México
   * - types: acepta tanto direcciones (geocode) como negocios (establishment)
   * - locationRestriction vs locationBias: mutuamente excluyentes en esta API
   */
  const placeAuto = new google.maps.places.PlaceAutocompleteElement({
    includedRegionCodes: ['mx'],
    types: ['geocode', 'establishment'],
    ...(dynamicBounds
      ? { locationRestriction: dynamicBounds.toJSON() }
      : { locationBias: celayaBounds })
  })
  
  placeAuto.style.width = '100%'
  containerEl.appendChild(placeAuto)

  /**
   * Flag para evitar que el listener de 'input' borre las coordenadas recién guardadas.
   *
   * Problema: cuando el usuario selecciona un place, el web component de Google dispara
   * los eventos en este orden:
   *   1. 'gmp-placeselect' → nuestro handler guarda dirección y coordenadas
   *   2. El componente rellena su <input> interno con el texto → dispara 'input'
   *   3. Nuestro 'input' handler borraba todo lo que acabábamos de guardar
   *
   * Solución: isSelecting = true durante toda la ejecución de gmp-placeselect.
   * El listener de 'input' verifica la flag y se salta el borrado si hay una selección activa.
   */
  let isSelecting = false

  /**
   * Evento 'gmp-placeselect': se dispara cuando el usuario selecciona una sugerencia.
   * Flujo:
   *   1. Marcar isSelecting = true para proteger los datos durante el async
   *   2. fetchFields(): hace una llamada adicional a la API para obtener los datos del lugar
   *   3. Construir la dirección legible: para establecimientos se antepone el nombre del negocio
   *   4. Guardar coordenadas en form.value
   *   5. Si location no está disponible, usar Geocoder como fallback (geocodificación por texto)
   *   6. Marcar isSelecting = false una vez que todo quedó guardado
   */
  placeAuto.addEventListener('gmp-select', async (event) => {
    console.log('detail:',
        event.detail);
    isSelecting = true
    const place = event.placePrediction.toPlace()
    await place.fetchFields({
        fields: [
            'formattedAddress',
            'location',
            'displayName',
            'types',
            'id'
        ]
    });

    // Los establecimientos (restaurantes, tiendas, etc.) tienen tipos específicos.
    // Para ellos, construimos la dirección como "Nombre del negocio, Dirección completa"
    // para que quede más claro en el historial del pedido.
    const isEstablishment = place.types?.includes('establishment') ||
                            place.types?.includes('point_of_interest') ||
                            place.types?.includes('food')

    let address
    if (isEstablishment && place.displayName && place.formattedAddress) {
      address = `${place.displayName}, ${place.formattedAddress}`
    } else {
      address = place.formattedAddress || place.displayName || ''
    }
    form.value[addressField] = address

    if (place.location) {
      // Caso normal: la respuesta ya incluye las coordenadas
      form.value[latField] = place.location.lat()
      form.value[lngField] = place.location.lng()
      console.log(`✅ ${addressField} con coords:`, form.value[latField], form.value[lngField])
    } else {
      // Fallback: si por algún motivo la nueva API no devuelve coordenadas,
      // usamos el Geocoder clásico para obtenerlas a partir del texto de la dirección.
      console.warn('⚠️ Location no disponible, usando Geocoder como fallback...')
      const geocoder = new google.maps.Geocoder()
      geocoder.geocode({ address }, (results, status) => {
        if (status === 'OK' && results[0]) {
          form.value[latField] = results[0].geometry.location.lat()
          form.value[lngField] = results[0].geometry.location.lng()
          console.log(`✅ ${addressField} geocodificado:`, form.value[latField], form.value[lngField])
        } else {
          console.error('❌ Geocoder fallo:', status)
        }
      })
    }

    // Liberar la flag en el siguiente tick para cubrir cualquier 'input' rezagado
    setTimeout(() => { isSelecting = false }, 0)
  })

  /**
   * Cuando el usuario escribe manualmente (borra y vuelve a tipear), se limpian
   * las coordenadas de la selección anterior para evitar enviar datos obsoletos.
   * Se omite el borrado si isSelecting = true (el componente está rellenando su
   * propio input tras una selección del dropdown, no el usuario escribiendo).
   */
  placeAuto.addEventListener('input', () => {
    if (isSelecting) return
    form.value[addressField] = ''
    form.value[latField] = null
    form.value[lngField] = null
    form.value.distance_km = null
  })
}

// ─── Save order ───────────────────────────────────────────────────────────────
/**
 * Publica el pedido en el backend.
 *
 * Flujo completo:
 *   1. Refrescar el saldo (para evitar race conditions si otro tab publicó un viaje)
 *   2. Validar que el cliente tenga saldo suficiente
 *   3. Validar que no haya error de geofence pendiente
 *   4. Construir el campo scheduled_at en formato "YYYY-MM-DD HH:mm:ss":
 *      - Si el usuario programó el envío: convertir la hora 12hr a 24hr y combinarlo con la fecha
 *      - Si es "lo antes posible": usar el momento actual (NUNCA enviar null al backend)
 *   5. Hacer POST /orders con el objeto form
 *   6. Si es exitoso: emitir 'created' (para que el padre actualice su lista) y 'close'
 */
const saveOrder = async () => {
  await loadBalance()
  if (!canAffordOrder.value) {
    alert('No te quedan viajes prepagados suficientes. Necesitas recargar saldo.')
    return
  }
  // Guard: las direcciones son obligatorias y deben venir del autocomplete (con coordenadas).
  // Si el usuario escribió texto a mano sin seleccionar una sugerencia, lat/lng quedan null.
  if (!form.value.pickup_lat || !form.value.drop_lat) {
    alert('Por favor selecciona las direcciones de recogida y entrega desde las sugerencias del mapa.')
    return
  }
  if (outOfZoneError.value) {
    alert(outOfZoneError.value)
    return
  }
  // Build scheduled_at in 'YYYY-MM-DD HH:mm:ss' (24hr) from the 12hr picker
  if (scheduledDate.value) {
    // Conversión de 12hr a 24hr:
    // - PM: sumar 12 a todas las horas excepto las 12 (12 PM = 12:00, no 24:00)
    // - AM: las 12 AM son medianoche (hora 0), no hora 12
    let h = parseInt(scheduledHour.value)
    if (scheduledAmpm.value === 'PM' && h !== 12) h += 12
    if (scheduledAmpm.value === 'AM' && h === 12) h = 0
    form.value.scheduled_at = `${scheduledDate.value} ${String(h).padStart(2, '0')}:${scheduledMinute.value}:00`
  } else {
    // "HOY MISMO, TAN PRONTO COMO SEA POSIBLE" — NUNCA NULL
    // Se usa la hora actual exacta para que el backend pueda ordenar pedidos correctamente
    const now = new Date()
    const y = now.getFullYear()
    const m = String(now.getMonth() + 1).padStart(2, '0')
    const d = String(now.getDate()).padStart(2, '0')
    const hh = String(now.getHours()).padStart(2, '0')
    const mm = String(now.getMinutes()).padStart(2, '0')
    const ss = String(now.getSeconds()).padStart(2, '0')
    form.value.scheduled_at = `${y}-${m}-${d} ${hh}:${mm}:${ss}`
  }

  submitting.value = true
  try {
    const response = await api.post('/orders', form.value)
    if (response.data.status) {
      // El pedido fue creado: notificar al padre para que actualice su listado
      emit('created', response.data.data)
      emit('close')
    }
  } catch (error) {
    console.error('Error saving order:', error)
    alert(error.response?.data?.message || 'Error al crear el pedido')
  } finally {
    // Siempre restablecer el estado de carga, incluso si hubo error
    submitting.value = false
  }
}

// ─── Map Updating ─────────────────────────────────────────────────────────────
/**
 * Watcher que reacciona cada vez que CUALQUIERA de las cuatro coordenadas cambia.
 * Es el cerebro de la funcionalidad de mapa: maneja marcadores, cálculo de ruta y pricing.
 *
 * Se observa un array de cuatro valores. Vue ejecuta el callback si alguno de ellos cambia.
 *
 * Flujo cuando AMBOS puntos tienen coordenadas válidas:
 *   1. Resetear precio y distancia (para mostrar estado "calculando")
 *   2. Calcular la ruta real con DirectionsService (travelMode: DRIVING)
 *   3. Dibujar la ruta en el mapa con DirectionsRenderer
 *   4. Llamar validateAndPrice() con la distancia real:
 *      a. POST /validate-geofence → verifica que pickup y drop estén dentro de la zona
 *      b. POST /calculate-price   → obtiene el precio según la tarifa configurada
 *   5. Si falla la validación → mostrar outOfZoneError y precio en $0
 *
 * Flujo cuando ALGUNO de los puntos es inválido (null o NaN):
 *   → Limpiar precio, error y distancia (estado de "esperando dirección")
 */
watch(
  () => [form.value.pickup_lat, form.value.pickup_lng, form.value.drop_lat, form.value.drop_lng],
  () => {
    if (!mapInstance) return;

    const pLat = parseFloat(form.value.pickup_lat);
    const pLng = parseFloat(form.value.pickup_lng);
    const dLat = parseFloat(form.value.drop_lat);
    const dLng = parseFloat(form.value.drop_lng);

    // Bounds se usa para hacer fitBounds() y que el mapa muestre ambos puntos
    const bounds = new google.maps.LatLngBounds();

    // Eliminar el marcador anterior antes de crear uno nuevo para evitar duplicados
    if (pickupMarker) pickupMarker.map = null;
    if (!isNaN(pLat) && !isNaN(pLng)) {
        const pos = { lat: pLat, lng: pLng };
        // Marcador verde = punto de RECOGIDA
        const pickupImg = document.createElement('img')
        pickupImg.src = 'http://maps.google.com/mapfiles/ms/icons/green-dot.png'
        pickupImg.style.cssText = 'width:32px;height:32px'
        pickupMarker = new google.maps.marker.AdvancedMarkerElement({
            position: pos, map: mapInstance, content: pickupImg
        });
        bounds.extend(pos);
    }

    if (dropMarker) dropMarker.map = null;
    if (!isNaN(dLat) && !isNaN(dLng)) {
        const pos = { lat: dLat, lng: dLng };
        // Marcador rojo = punto de ENTREGA
        const dropImg = document.createElement('img')
        dropImg.src = 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
        dropImg.style.cssText = 'width:32px;height:32px'
        dropMarker = new google.maps.marker.AdvancedMarkerElement({
            position: pos, map: mapInstance, content: dropImg
        });
        bounds.extend(pos);
    }

    if (!isNaN(pLat) && !isNaN(pLng) && !isNaN(dLat) && !isNaN(dLng)) {
        // Ambos puntos tienen coordenadas → calcular ruta y precio
        calculatedPrice.value = 0
        routeDistance.value = ''
        routeTime.value = ''
        form.value.distance_km = null

        /**
         * validateAndPrice(distanceKm):
         * Función interna que encadena dos llamadas a la API del backend:
         *   1. POST /validate-geofence: verifica que ambos puntos estén en zona operativa
         *   2. POST /calculate-price: calcula el precio según la tarifa configurada
         *
         * Si /validate-geofence falla (HTTP 422/400), el .catch() captura el error
         * y establece outOfZoneError, bloqueando el botón de publicar.
         *
         * @param {number|null} distanceKm - Distancia calculada por Directions; puede ser null si Directions falló
         */
        const validateAndPrice = (distanceKm) => {
            api.post('/validate-geofence', {
                pickup_lat: pLat, pickup_lng: pLng,
                drop_lat: dLat,   drop_lng: dLng,
            }).then(() => {
                // Geofence OK → limpiar error y calcular precio
                outOfZoneError.value = ''
                return api.post('/calculate-price', {
                    pickup_lat: pLat, pickup_lng: pLng,
                    drop_lat: dLat, drop_lng: dLng,
                    pickup_address: form.value.pickup_address,
                    drop_address: form.value.drop_address,
                    distance_km: distanceKm
                })
            }).then(res => {
                if (res?.data?.status) {
                    calculatedPrice.value = res.data.data.price
                    console.log('[pricing] breakdown:', res.data.data.breakdown)
                }
            }).catch(err => {
                // Geofence falló o pricing falló → mostrar error al usuario
                calculatedPrice.value = 0
                outOfZoneError.value = err.response?.data?.message || 'La zona de destino o recogida esta fuera de la zona de operaciones.'
            })
        }

        /**
         * DirectionsService y DirectionsRenderer se instancian de forma lazy (solo cuando se necesitan).
         * Si ya existen, se limpia la ruta anterior (setDirections con routes vacío) antes de dibujar la nueva.
         * polylineOptions: color índigo (#6366F1) alineado con el design system de la app.
         * suppressMarkers: true porque usamos nuestros propios marcadores de color verde/rojo.
         */
        if (!directionsService) {
            directionsService = new google.maps.DirectionsService();
            directionsRenderer = new google.maps.DirectionsRenderer({
                map: mapInstance,
                suppressMarkers: true,    // No mostrar los marcadores A/B por defecto de Google
                preserveViewport: true,   // No cambiar el zoom automáticamente al dibujar la ruta
                polylineOptions: { strokeColor: '#6366F1', strokeWeight: 4 }
            });
        } else {
            directionsRenderer.setDirections({ routes: [] });
        }

        /**
         * Solicitar la ruta en automóvil entre los dos puntos.
         * Si Google Directions responde OK:
         *   - Dibuja la polilínea en el mapa
         *   - Extrae distancia (en texto y en metros) y tiempo estimado del primer segmento (leg)
         *   - Convierte distancia de metros a kilómetros para el campo distance_km del formulario
         *
         * Si falla (sin red, dirección no ruteable, etc.):
         *   - Vaciar distancia y tiempo (el backend calculará con haversine como fallback)
         *   - Continuar con validateAndPrice(null) para que el backend use su propia distancia
         */
        directionsService.route({
            origin: { lat: pLat, lng: pLng },
            destination: { lat: dLat, lng: dLng },
            travelMode: google.maps.TravelMode.DRIVING
        }, (response, status) => {
            if (status === 'OK') {
                directionsRenderer.setDirections(response);
                const leg = response.routes[0].legs[0]; // El primer (y único) segmento de la ruta
                routeDistance.value = leg.distance.text;           // Ej. "4.2 km"
                routeTime.value = leg.duration.text;               // Ej. "12 min"
                form.value.distance_km = leg.distance.value / 1000; // Convertir metros → km
            } else {
                console.warn('Directions fallo (' + status + '), usando haversine.');
                routeDistance.value = '';
                routeTime.value = '';
                form.value.distance_km = null;
            }
            // En cualquier caso, proceder a validar y calcular precio
            validateAndPrice(form.value.distance_km);
        });
    } else {
        // Alguno de los puntos no tiene coordenadas → limpiar todo
        calculatedPrice.value = 0;
        outOfZoneError.value = '';
        routeDistance.value = '';
        routeTime.value = '';
        form.value.distance_km = null;
    }

    /**
     * Ajustar el zoom del mapa para mostrar ambos marcadores visibles.
     * Se usa setTimeout(50ms) porque los marcadores pueden no estar en el DOM
     * exactamente en el mismo ciclo de render que el watcher.
     * Se limita el zoom máximo a 15 para evitar quedar demasiado cerca si los puntos son casi idénticos.
     */
    if (!bounds.isEmpty()) {
        setTimeout(() => {
            mapInstance.fitBounds(bounds, { padding: 30 });
            if (mapInstance.getZoom() > 15) {
                mapInstance.setZoom(15);
            }
        }, 50);
    }
  }
)

// ─── Init ─────────────────────────────────────────────────────────────────────
/**
 * onMounted: se ejecuta una vez que el componente ya está insertado en el DOM.
 * Es el lugar correcto para:
 *   - Hacer las primeras peticiones a la API
 *   - Inicializar el mapa de Google (necesita un div existente en el DOM)
 *   - Inyectar los autocompletes de Places en los contenedores
 *
 * Secuencia de inicialización:
 *   1. loadBalance() — sin await para no bloquear la carga visual
 *   2. Promise.allSettled([getGeofences, ensureSDKLoaded]) — ambas en paralelo
 *      Se usa allSettled (no Promise.all) para que si una falla, la otra igual continúe.
 *   3. setTimeout(100ms) — pequeño delay para garantizar que Vue haya actualizado
 *      el DOM y los refs pickupContainer/dropContainer apunten a elementos reales.
 *      Dentro del timeout: inyectar autocompletes e inicializar el mapa.
 */
onMounted(async () => {
  loadBalance()

  const [geofencesResult] = await Promise.allSettled([
    api.get('/geofences'),
    MapService.ensureSDKLoaded()
  ])

  if (geofencesResult.status === 'fulfilled') {
    clientZones.value = geofencesResult.value.data?.data ?? []
    console.log('Geofences cargadas:', clientZones.value)
  } else {
    clientZones.value = []
    console.warn('No se pudieron cargar geofences:', geofencesResult.reason)
  }

  setTimeout(() => {
    // Inyectar el autocomplete en el contenedor de pickup solo si el ref existe en el DOM
    if (pickupContainer.value) {
      attachAutocomplete(pickupContainer.value, 'pickup_address', 'pickup_lat', 'pickup_lng')
    }
    // Inyectar el autocomplete en el contenedor de drop
    if (dropContainer.value) {
      attachAutocomplete(dropContainer.value, 'drop_address', 'drop_lat', 'drop_lng')
    }

    /**
     * Inicializar el mapa de Google centrado en Celaya, Guanajuato.
     * disableDefaultUI: true → oculta todos los controles por defecto (Street View, etc.)
     * zoomControl: true     → reactivar solo el control de zoom
     * mapId requerido por AdvancedMarkerElement; los estilos visuales se
     *        gestionan desde Google Cloud Console (no se pueden pasar inline con mapId).
     * El id 'modal-map-manual' corresponde al div en el template.
     */
    if (window.google?.maps) {
        mapInstance = new google.maps.Map(document.getElementById('modal-map-manual'), {
            center: { lat: 20.5222, lng: -100.8122 }, // Centro de Celaya, Gto.
            zoom: 13,
            mapId: import.meta.env.VITE_GOOGLE_MAPS_MAP_ID || 'DEMO_MAP_ID',
            disableDefaultUI: true,
            zoomControl: true
        });
    }
  }, 100)
})
</script>

<template>
  <!--
    <Teleport to="body">: mueve este modal fuera del árbol DOM del componente padre
    y lo inserta directamente en <body>. Esto evita problemas de z-index y overflow:hidden
    heredados de ancestros, garantizando que el overlay siempre cubra toda la pantalla.
  -->
  <Teleport to="body">
    <!--
      .modal-overlay: fondo oscuro semitransparente que cubre toda la pantalla.
      @click.self: el modificador .self garantiza que el modal solo se cierre si el usuario
      hace clic en el fondo, NO si hace clic dentro del .modal-content.
    -->
    <div class="modal-overlay" @click.self="$emit('close')">
      <div class="modal-content modal-content--manual">

        <!-- ── HEADER ──────────────────────────────────────────── -->
        <div class="modal-header">
          <div class="modal-title-group">
            <span class="modal-icon" aria-hidden="true">📝</span>
            <div>
              <p class="modal-kicker">Nuevo envío</p>
              <h2>Formulario manual</h2>
              <p class="modal-subtitle">Completa los datos para publicar un envío en tu zona.</p>
            </div>
          </div>
          <!-- Botón ✕: emite 'close' directamente con $emit (sin pasar por un método) -->
          <button type="button" @click="$emit('close')" class="close-btn" aria-label="Cerrar">&times;</button>
        </div>

        <!--
          @submit.prevent: previene el comportamiento nativo del navegador (recarga de página)
          y llama a saveOrder() en su lugar.
        -->
        <form @submit.prevent="saveOrder" class="modal-form">
          <div class="modal-body">

          <!--
            Balance Pill: muestra los viajes disponibles del cliente.
            Si canAffordOrder es false, cambia de estilo verde a naranja para alertar visualmente.
            :class con objeto: { 'clase': condición } → aplica la clase solo si la condición es true.
          -->
          <div class="balance-pill" :class="{ 'insufficient': !canAffordOrder }">
            <span class="pill-label">{{ canAffordOrder ? 'Saldo disponible' : 'Saldo insuficiente' }}</span>
            <span class="pill-value">{{ userBalance }} viajes prepagados</span>
          </div>

          <!-- ── SECCIÓN 1: DIRECCIONES ──────────────────────────── -->
          <section class="manual-section" aria-labelledby="manual-address-heading">
            <h3 id="manual-address-heading" class="manual-section__title">Origen, destino y receptor</h3>
          <div class="form-row">
            <div class="form-group">
              <label>📍 Direccion de Recogida</label>
              <!--
                input-wrapper--verified: aplica un borde verde cuando la dirección fue
                seleccionada del autocomplete y tiene coordenadas (form.pickup_lat es truthy).
                Si el usuario escribe a mano sin seleccionar, pickup_lat queda null y el borde vuelve al gris.
              -->
              <div class="input-wrapper" :class="{ 'input-wrapper--verified': form.pickup_lat }">
                <!--
                  ref="pickupContainer": este div vacío es el contenedor donde onMounted()
                  inyectará el PlaceAutocompleteElement de Google via attachAutocomplete().
                  No tiene contenido HTML propio; Google inserta el input dentro de él.
                -->
                <div ref="pickupContainer" class="place-autocomplete-wrapper"></div>
                <!--
                  <transition name="badge-pop">: anima la aparición/desaparición del badge "✔ Verificado".
                  El badge aparece SOLO cuando form.pickup_lat tiene un valor (coordenada válida).
                  La animación 'badge-pop' tiene efecto de rebote (cubic-bezier de spring) definido en el CSS.
                -->
                <transition name="badge-pop">
                  <span v-if="form.pickup_lat" class="coord-badge coord-badge--verified">✔ Verificado</span>
                </transition>
              </div>
            </div>
            <div class="form-group">
              <label>🏁 Direccion de Entrega</label>
              <div class="input-wrapper" :class="{ 'input-wrapper--verified': form.drop_lat }">
                <!-- Mismo patrón que pickupContainer: div vacío que recibe el autocomplete de drop -->
                <div ref="dropContainer" class="place-autocomplete-wrapper"></div>
                <transition name="badge-pop">
                  <span v-if="form.drop_lat" class="coord-badge coord-badge--verified">✔ Verificado</span>
                </transition>
              </div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>👤 Nombre de quien recibe</label>
              <div class="input-wrapper">
                <!-- v-model sincroniza el valor del input con form.receiver_name en tiempo real -->
                <input
                  v-model="form.receiver_name"
                  type="text"
                  placeholder="Ej. Juan Perez"
                  required
                />
              </div>
            </div>
            <div class="form-group">
              <label>📞 Telefono de quien recibe</label>
              <div class="input-wrapper">
                <!-- type="tel" en móvil abre el teclado numérico automáticamente -->
                <input
                  v-model="form.receiver_phone"
                  type="tel"
                  placeholder="Ej. 614 123 4567"
                  required
                />
              </div>
            </div>
          </div>

          <!--
            Contenedor del mapa de previsualización.
            El div con id="modal-map-manual" es el anchor que usa Google Maps para renderizar.
            IMPORTANTE: el id debe ser único en el DOM. Si hubiera dos instancias de este modal
            abiertas al mismo tiempo, habría conflicto. (En la práctica, solo hay una a la vez.)
          -->
          <div class="form-group map-preview-wrapper">
            <div id="modal-map-manual" class="modal-map-preview"></div>
          </div>
          </section>

          <!-- ── SECCIÓN 2: DETALLE DEL PAQUETE ──────────────────── -->
          <section class="manual-section" aria-labelledby="manual-package-heading">
            <h3 id="manual-package-heading" class="manual-section__title">Detalle del paquete</h3>
          <div class="form-group">
            <label>📝 Descripcion del Paquete</label>
            <!-- textarea sin required: la descripción es opcional (el repartidor agradece el contexto) -->
            <textarea
              v-model="form.description"
              placeholder="Ej. 2 cajas de pizza, fragil. Manejar con cuidado."
            ></textarea>
          </div>
          </section>

          <!-- ── SECCIÓN 3: PROGRAMACIÓN ──────────────────────────── -->
          <section class="manual-section" aria-labelledby="manual-schedule-heading">
            <h3 id="manual-schedule-heading" class="manual-section__title">Programación</h3>
          <div class="form-group">
            <label>📅 Programar envio</label>

            <!--
              Toggle de modo de programación: radio buttons estilizados como tarjetas.
              Los <input type="radio"> están ocultos (display: none en CSS).
              La clase .active se aplica según el valor actual de scheduleMode.
              v-model en los radio inputs actualiza scheduleMode automáticamente.
            -->
            <div class="schedule-mode-toggle">
              <label class="mode-radio" :class="{ active: scheduleMode === 'asap' }">
                <input type="radio" v-model="scheduleMode" value="asap" />
                <span class="mode-radio-dot"></span>
                <span class="mode-radio-label">Lo antes posible</span>
              </label>
              <label class="mode-radio" :class="{ active: scheduleMode === 'scheduled' }">
                <input type="radio" v-model="scheduleMode" value="scheduled" />
                <span class="mode-radio-dot"></span>
                <span class="mode-radio-label">Programar envío</span>
              </label>
            </div>

            <!--
              Panel de programación: solo visible cuando el usuario elige "Programar envío".
              <transition name="fade-down">: anima la aparición con un deslizamiento hacia abajo.
              v-if (no v-show): el panel se destruye del DOM cuando no se usa, evitando
              que los campos queden activos y afecten la validación del formulario.
            -->
            <transition name="fade-down">
              <div v-if="scheduleMode === 'scheduled'" class="schedule-panel">
                <!--
                  Selector de día: tres "chips" (Hoy, Mañana, Pasado mañana).
                  Se itera dayOptions con v-for. La clase .active compara el valor del chip
                  con scheduledDate para resaltar el día seleccionado.
                  @click llama a setScheduleDate() con una clave específica para el helper.
                -->
                <div class="schedule-day-row">
                  <button
                    v-for="opt in dayOptions"
                    :key="opt.value"
                    type="button"
                    class="day-chip"
                    :class="{ active: scheduledDate === opt.value }"
                    @click="setScheduleDate(opt.label === 'Hoy' ? 'today' : opt.label === 'Mañana' ? 'tomorrow' : 'after_tomorrow')"
                  >{{ opt.label }}</button>
                </div>

                <!--
                  Selector de hora: solo visible cuando ya se eligió un día (scheduledDate no es null).
                  Muestra la fecha formateada (ej. "13 Jun") y los selects de hora, minutos y AM/PM.
                  Las opciones de minutos son: 00, 15, 30, 45 (cuartos de hora para simplificar UX).
                  El toggle AM/PM son dos botones que actualizan scheduledAmpm directamente.
                -->
                <div v-if="scheduledDate" class="time-picker-row">
                  <span class="schedule-date-label">{{ formattedScheduleDate }}</span>
                  <div class="time-selects">
                    <!-- v-for="h in 12" genera opciones del 1 al 12 con String().padStart para el formato "01", "02"... -->
                    <select v-model="scheduledHour" class="time-select">
                      <option v-for="h in 12" :key="h" :value="String(h).padStart(2,'0')">{{ h }}</option>
                    </select>
                    <span class="time-colon">:</span>
                    <select v-model="scheduledMinute" class="time-select">
                      <option value="00">00</option>
                      <option value="15">15</option>
                      <option value="30">30</option>
                      <option value="45">45</option>
                    </select>
                    <!-- Toggle AM/PM: botones con clase .active según el valor de scheduledAmpm -->
                    <div class="ampm-toggle">
                      <button type="button" :class="{ active: scheduledAmpm === 'AM' }" @click="scheduledAmpm = 'AM'">AM</button>
                      <button type="button" :class="{ active: scheduledAmpm === 'PM' }" @click="scheduledAmpm = 'PM'">PM</button>
                    </div>
                  </div>
                  <!--
                    Mensaje de error si la hora ya pasó (solo aplica cuando el día es HOY).
                    scheduleTimeError es un computed que evalúa la hora seleccionada vs. new Date().
                  -->
                  <div v-if="scheduleTimeError" class="schedule-error">
                    ⚠️ {{ scheduleTimeError }}
                  </div>
                </div>

                <!-- Botón para cancelar la programación y volver al modo "Lo antes posible" -->
                <button
                  type="button"
                  class="btn-quitar"
                  @click="clearSchedule"
                >✕ Quitar</button>
              </div>
            </transition>
          </div>
          </section>

          <!-- ── SECCIÓN 4: PAGO Y RESUMEN ────────────────────────── -->
          <section class="manual-section" aria-labelledby="manual-payment-heading">
            <h3 id="manual-payment-heading" class="manual-section__title">Pago y resumen</h3>
          <div class="form-group">
            <label>💳 Quien paga el envio?</label>
            <!--
              Tarjetas de pago: tres opciones exclusivas implementadas como labels que envuelven
              radio inputs ocultos. Al hacer clic en el label, el radio se activa y v-model
              actualiza form.payment_type. La clase .active se aplica cuando el tipo coincide.

              Tipos disponibles:
              - 'prepaid':          el remitente paga con su saldo prepagado
              - 'cash_on_delivery': el receptor paga el flete en efectivo al recibir
              - 'cash_full':        el receptor paga flete + valor del producto en efectivo
            -->
            <div class="payment-cards">
              <label class="payment-card" :class="{ active: form.payment_type === 'prepaid' }">
                <input type="radio" v-model="form.payment_type" value="prepaid" />
                <span class="pcard-icon">🏢</span>
                <span class="pcard-title">Remitente</span>
                <span class="pcard-sub">Yo pago (saldo)</span>
              </label>
              <label class="payment-card" :class="{ active: form.payment_type === 'cash_on_delivery' }">
                <input type="radio" v-model="form.payment_type" value="cash_on_delivery" />
                <span class="pcard-icon">📦</span>
                <span class="pcard-title">Receptor</span>
                <span class="pcard-sub">Paga solo envio</span>
              </label>
              <label class="payment-card" :class="{ active: form.payment_type === 'cash_full' }">
                <input type="radio" v-model="form.payment_type" value="cash_full" />
                <span class="pcard-icon">💰</span>
                <span class="pcard-title">Receptor</span>
                <span class="pcard-sub">Paga envio + producto</span>
              </label>
            </div>
          </div>

          <!--
            Campo de valor del producto: solo aparece cuando el pago es 'cash_full'.
            <transition name="fade-down">: anima la aparición/desaparición.
            required: solo aplica cuando el campo está visible (v-if lo saca del DOM cuando no aplica).
            min="0.01" y step="0.01": garantizan que sea un valor monetario positivo con centavos.
          -->
          <transition name="fade-down">
            <div class="form-group" v-if="form.payment_type === 'cash_full'">
              <label>💰 Valor del Producto <span class="required">*</span></label>
              <div class="input-wrapper">
                <input
                  v-model="form.product_amount"
                  type="number"
                  min="0.01"
                  step="0.01"
                  placeholder="0.00"
                  required
                />
                <!-- Badge estático "MXN" como indicador de moneda -->
                <span class="coord-badge coord-badge--currency">MXN</span>
              </div>
            </div>
          </transition>

          <!--
            Resumen del pedido: muestra el costo calculado y el total que debe cobrar el repartidor.

            Lógica de visualización:
            - Si outOfZoneError tiene contenido → mostrar el error en rojo (no mostrar precio)
            - Si no hay error:
              * Si hay distancia/tiempo calculados → mostrar info de la ruta
              * Siempre mostrar el costo del envío (calculatedPrice, que es 0 si aún no se calculó)
              * Si payment_type === 'cash_full' y hay product_amount → mostrar el valor del producto
              * Si payment_type !== 'prepaid' → mostrar el total que cobra el repartidor (totalToCollect)
              * Siempre mostrar cuántos créditos se descontarán (siempre 1 viaje)
          -->
          <div class="order-summary">
            <div v-if="outOfZoneError" class="summary-row text-red">
               <span>⚠️ {{ outOfZoneError }}</span>
            </div>
            <template v-else>
              <div class="summary-row" v-if="routeDistance || routeTime">
                <span class="summary-meta">
                  <span v-if="routeDistance">🗺️ {{ routeDistance }}</span>
                  <span v-if="routeDistance && routeTime" class="meta-sep">·</span>
                  <span v-if="routeTime">⏱️ {{ routeTime }}</span>
                </span>
              </div>
              <div class="summary-row">
                <span>Costo del Envio</span>
                <!-- .toFixed(2) garantiza siempre dos decimales, ej. "$45.00 MXN" -->
                <span class="summary-cost">${{ calculatedPrice.toFixed(2) }} MXN</span>
              </div>
              <div class="summary-row" v-if="form.payment_type === 'cash_full' && form.product_amount">
                <span>Valor del Producto</span>
                <span class="summary-cost">${{ parseFloat(form.product_amount || 0).toFixed(2) }} MXN</span>
              </div>
              <!-- Fila de total solo visible si el receptor paga (en efectivo) -->
              <div class="summary-row total" v-if="form.payment_type !== 'prepaid'">
                <span><strong>💵 Cobrar al receptor</strong></span>
                <span class="summary-cost highlight">${{ totalToCollect.toFixed(2) }} MXN</span>
              </div>
              <!-- El crédito siempre es 1 viaje, independientemente de la distancia o precio -->
              <div class="summary-row">
                <span>Creditos a descontar</span>
                <!-- Color dinámico: verde si tiene saldo, rojo si no puede pagar -->
                <span :class="canAffordOrder ? 'text-green' : 'text-red'">1 Viaje</span>
              </div>
            </template>
          </div>
          </section>

          </div>

          <!-- ── FOOTER: BOTONES DE ACCIÓN ───────────────────────── -->
          <div class="modal-footer">
            <button type="button" @click="$emit('close')" class="btn-cancel">Cancelar</button>
            <!--
              Botón de publicar: deshabilitado en tres casos:
              1. !canAffordOrder  → el cliente no tiene saldo suficiente
              2. submitting       → la petición ya está en vuelo (evita doble submit)
              3. !!outOfZoneError → algún punto está fuera de la zona de operación

              !! convierte el string en booleano: '' → false, 'mensaje de error' → true

              v-if en los <span> alterna entre "Publicando..." y "Publicar viaje" según el estado.
            -->
            <button type="submit" class="btn-publish" :disabled="!canAffordOrder || submitting || !!outOfZoneError || !form.pickup_lat || !form.drop_lat">
              <span v-if="submitting">Publicando...</span>
              <span v-else>Publicar viaje</span>
            </button>
          </div>
        </form>

      </div>
    </div>
  </Teleport>
</template>

<style scoped>
/*
  scoped: Vue agrega un atributo único (ej. data-v-xxxxxxx) a cada elemento del componente
  y transforma estos selectores para que solo apliquen a ESTE componente.
  Ventaja: no hay riesgo de colisionar con estilos globales o de otros componentes.
*/

/* Overlay: cubre toda la pantalla con fondo oscuro y blur detrás del modal */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  z-index: 2000;
  animation: fadeIn 0.2s ease;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

/* Contenedor principal del modal */
.modal-content {
  background: white;
  width: 100%; max-width: 780px;
  border-radius: 20px;
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
  animation: slideUp 0.25s ease;
  overflow: hidden;
  display: flex; flex-direction: column;
  /* dvh (dynamic viewport height): equivalente a vh pero en móvil respeta la barra del navegador */
  max-height: 95dvh;
}

.modal-content--manual {
  border: 1px solid rgba(226, 232, 240, 0.95);
  box-shadow:
    0 0 0 1px rgba(255, 255, 255, 0.6) inset,
    0 28px 55px -18px rgba(15, 23, 42, 0.35);
}
@keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

/* Header con gradiente verde institucional de la plataforma */
.modal-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding: 1.35rem 1.75rem 1.25rem;
  background: linear-gradient(135deg, #0f766e 0%, #059669 42%, #10b981 100%);
  color: white;
  position: relative;
  overflow: hidden;
}

/* Brillo decorativo en la esquina superior derecha del header */
.modal-header::after {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(120% 80% at 100% 0%, rgba(255,255,255,0.18) 0%, transparent 55%);
  pointer-events: none;
}

.modal-title-group { display: flex; align-items: flex-start; gap: 1rem; position: relative; z-index: 1; }
.modal-icon { font-size: 2rem; line-height: 1; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15)); }

.modal-kicker {
  margin: 0 0 0.15rem;
  font-size: 0.68rem;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  opacity: 0.88;
}

.modal-header h2 { font-size: 1.35rem; font-weight: 800; margin: 0 0 0.35rem; letter-spacing: -0.02em; }

.modal-subtitle {
  font-size: 0.82rem;
  opacity: 0.88;
  margin: 0;
  max-width: 36ch;
  line-height: 1.45;
}

.close-btn {
  background: rgba(255,255,255,0.18); border: none; color: white;
  width: 36px; height: 36px; border-radius: 12px; font-size: 1.2rem;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: background 0.2s, transform 0.2s;
  position: relative;
  z-index: 1;
  flex-shrink: 0;
}
.close-btn:hover { background: rgba(255,255,255,0.32); transform: scale(1.04); }

/* El formulario ocupa el espacio restante del modal (flex: 1) y permite scroll en el body */
.modal-form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.modal-body { padding: 1.5rem 1.75rem; display: flex; flex-direction: column; gap: 1.25rem; overflow-y: auto; flex: 1; background: linear-gradient(180deg, #f8fafc 0%, #fff 120px); }

/* Sección visual con borde redondeado para agrupar campos relacionados */
.manual-section {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding: 1rem 1rem 1.05rem;
  border-radius: 14px;
  border: 1px solid #e8ecf4;
  background: #fff;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
}

.manual-section__title {
  margin: 0;
  font-size: 0.72rem;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #64748b;
  padding-bottom: 0.35rem;
  border-bottom: 1px solid #f1f5f9;
}

.map-preview-wrapper {
  margin-top: 0.25rem;
  margin-bottom: 0;
}

/* Contenedor del mapa de previsualización de ruta */
.modal-map-preview {
  width: 100%;
  height: 260px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  overflow: hidden;
  box-shadow: 0 8px 24px -16px rgba(15, 23, 42, 0.25);
}

/* Pill de saldo: verde normal, naranja cuando es insuficiente */
.balance-pill {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.8rem 1rem; border-radius: 12px;
  background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
  border: 1px solid #86efac;
  font-size: 0.85rem;
}
.balance-pill.insufficient { background: linear-gradient(135deg, #fff7ed 0%, #fffbeb 100%); border-color: #fed7aa; }
.pill-label { font-weight: 600; }
.pill-value { color: #6B7280; }

/* Grid de dos columnas para pares de campos (pickup/drop, nombre/teléfono) */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

.form-group { display: flex; flex-direction: column; gap: 0.4rem; }
.form-group label { font-size: 0.82rem; font-weight: 600; color: #374151; }

/* position: relative en el wrapper permite posicionar el badge "✔ Verificado" de forma absoluta */
.input-wrapper { position: relative; }
.input-wrapper input {
  width: 100%; padding: 0.7rem 0.9rem;
  border: 1.5px solid #E5E7EB; border-radius: 10px;
  font-size: 0.9rem; outline: none; transition: border-color 0.2s;
  font-family: inherit; box-sizing: border-box;
}
.input-wrapper input:focus {
  border-color: #6366F1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

/* Badge "✔ Verificado": posicionado absolutamente sobre el input cuando hay coordenadas */
.coord-badge {
  position: absolute; right: 0.6rem; top: 50%; transform: translateY(-50%);
  background: #DCFCE7; color: #166534;
  font-size: 0.7rem; font-weight: 700;
  padding: 0.2rem 0.5rem; border-radius: 6px; pointer-events: none;
}

.coord-badge--verified {
  background: #D1FAE5;
  color: #065F46;
  border: 1px solid #6EE7B7;
  box-shadow: 0 1px 4px rgba(16, 185, 129, 0.18);
  letter-spacing: 0.01em;
  font-size: 0.72rem;
}

/*
  :deep() es el piercing selector de Vue scoped styles.
  Permite afectar elementos dentro de un web component o de un componente hijo.
  Aquí se usa para cambiar el color del borde del input interno del PlaceAutocompleteElement
  de Google cuando la dirección está verificada.
*/
/* borde verde del autocomplete verificado se controla desde .place-autocomplete-wrapper */

/* Animación de aparición del badge "✔ Verificado" con efecto spring/rebote */
.badge-pop-enter-active { transition: all 0.22s cubic-bezier(0.34, 1.56, 0.64, 1); }
.badge-pop-leave-active { transition: all 0.15s ease; }
.badge-pop-enter-from { opacity: 0; transform: translateY(-50%) scale(0.7); }
.badge-pop-leave-to  { opacity: 0; transform: translateY(-50%) scale(0.7); }

/* Badge de moneda "MXN" para el campo de valor del producto */
.coord-badge--currency {
  background: #d1fae5;
  color: #065f46;
}

.form-group textarea, .form-group select {
  width: 100%; padding: 0.7rem 0.9rem;
  border: 1.5px solid #E5E7EB; border-radius: 10px;
  font-size: 0.9rem; outline: none; transition: border-color 0.2s;
  font-family: inherit; box-sizing: border-box;
}
.form-group textarea:focus, .form-group select:focus {
  border-color: #6366F1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}
.form-group textarea { height: 80px; resize: none; }

/* Cuadro de resumen del pedido con fondo gris claro */
.order-summary {
  background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  padding: 1rem 1.05rem;
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  margin-top: 0.25rem;
}
.summary-row { display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; }
.summary-meta { display: flex; align-items: center; gap: 0.4rem; font-size: 0.82rem; color: #6B7280; font-weight: 500; }
.meta-sep { color: #D1D5DB; }
.summary-row.total { background: #EFF6FF; border-radius: 8px; padding: 0.5rem 0.75rem; margin-top: 0.25rem; }
.summary-cost { font-weight: 700; color: #6366F1; font-size: 1rem; }
.summary-cost.highlight { color: #059669; font-size: 1.05rem; }
.text-green { color: #059669; font-weight: 700; }
.text-red { color: #DC2626; font-weight: 700; }
.required { color: #EF4444; }

/* Grid de 3 columnas para las tarjetas de pago */
.payment-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.payment-card {
  display: flex; flex-direction: column; align-items: center; gap: 0.3rem;
  padding: 0.85rem 0.5rem; border-radius: 12px;
  border: 2px solid #E5E7EB; cursor: pointer;
  transition: all 0.2s; text-align: center; position: relative;
  background: white;
}
/* El radio input está oculto: el label completo funciona como el control clickeable */
.payment-card input[type="radio"] { display: none; }
.payment-card:hover { border-color: #A5B4FC; background: #F5F7FF; }
.payment-card.active { border-color: #6366F1; background: #EEF2FF; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
.pcard-icon { font-size: 1.6rem; }
.pcard-title { font-weight: 700; font-size: 0.82rem; color: #111827; }
.pcard-sub { font-size: 0.72rem; color: #6B7280; }
.payment-card.active .pcard-title { color: #4338CA; }

/* Animación de entrada/salida usada en el panel de scheduling y el campo de valor del producto */
.fade-down-enter-active, .fade-down-leave-active { transition: all 0.25s ease; }
.fade-down-enter-from, .fade-down-leave-to { opacity: 0; transform: translateY(-8px); }

/* ─── Schedule Mode Toggle (Radio buttons) ─────────────────────────────── */
.schedule-mode-toggle {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}
.mode-radio {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.5rem 1rem;
  border-radius: 10px;
  border: 1.5px solid #E5E7EB;
  background: white;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
  flex: 1;
}
.mode-radio input[type="radio"] {
  display: none;
}
.mode-radio:hover {
  border-color: #A5B4FC;
  background: #F5F7FF;
}
.mode-radio.active {
  border-color: #6366F1;
  background: #EEF2FF;
  box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
/* Indicador visual circular (reemplaza el radio input oculto) */
.mode-radio-dot {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: 2px solid #D1D5DB;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: all 0.2s;
}
/* Cuando está activo: relleno índigo con punto blanco interior (box-shadow inset) */
.mode-radio.active .mode-radio-dot {
  border-color: #6366F1;
  background: #6366F1;
  box-shadow: inset 0 0 0 3px white;
}
.mode-radio-label {
  font-size: 0.85rem;
  font-weight: 600;
  color: #374151;
}
.mode-radio.active .mode-radio-label {
  color: #4338CA;
}

/* ─── Schedule Panel ──────────────────────────────────────────────────── */
.schedule-panel {
  padding: 0.75rem;
  border-radius: 12px;
  background: #FAFAFA;
  border: 1px solid #E5E7EB;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.schedule-day-row {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
/* Chip de día: pastilla clickeable de selección de día */
.day-chip {
  padding: 0.45rem 1rem;
  border-radius: 20px;
  border: 1.5px solid #E5E7EB;
  background: white;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
  color: #374151;
  font-family: inherit;
  flex: 1;
}
.day-chip:hover {
  border-color: #A5B4FC;
  background: #F5F7FF;
}
.day-chip.active {
  border-color: #6366F1;
  background: #EEF2FF;
  color: #4338CA;
}

/* ─── Schedule Error ──────────────────────────────────────────────────── */
/* Alerta roja para cuando la hora seleccionada ya pasó */
.schedule-error {
  padding: 0.5rem 0.75rem;
  border-radius: 8px;
  background: #FEF2F2;
  border: 1px solid #FECACA;
  color: #DC2626;
  font-size: 0.8rem;
  font-weight: 600;
  text-align: center;
}

/* ─── Quitar Button ───────────────────────────────────────────────────── */
/* Botón destructivo secundario (cancelar scheduling): rojo suave */
.btn-quitar {
  padding: 0.4rem 1rem;
  border-radius: 8px;
  border: 1.5px solid #FCA5A5;
  background: #FFF5F5;
  color: #DC2626;
  font-size: 0.8rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  font-family: inherit;
  align-self: flex-start;
}
.btn-quitar:hover {
  background: #FEE2E2;
  border-color: #EF4444;
}

/* ─── Time Picker ─────────────────────────────────────────────────────── */
.time-picker-row {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.6rem 0.9rem;
  border-radius: 10px;
  background: #F5F7FF;
  border: 1.5px solid #C7D2FE;
}
.schedule-date-label { font-size: 0.85rem; font-weight: 700; color: #4338CA; min-width: 50px; }
.time-selects { display: flex; align-items: center; gap: 0.4rem; }
.time-select {
  padding: 0.35rem 0.5rem; border-radius: 8px;
  border: 1.5px solid #C7D2FE; background: white;
  font-size: 0.95rem; font-weight: 700; color: #1F2937;
  font-family: inherit; cursor: pointer; outline: none;
}
.time-select:focus {
  border-color: #6366F1;
}
.time-colon { font-weight: 800; font-size: 1.1rem; color: #6366F1; }
/* Toggle AM/PM: dos botones pegados que actúan como radio group visual */
.ampm-toggle { display: flex; border-radius: 8px; overflow: hidden; border: 1.5px solid #C7D2FE; flex-shrink: 0; }
.ampm-toggle button {
  padding: 0.35rem 0.65rem; border: none; background: white;
  font-size: 0.78rem; font-weight: 700; cursor: pointer;
  color: #6B7280; font-family: inherit; transition: all 0.15s;
}
.ampm-toggle button.active { background: #6366F1; color: white; }
.ampm-toggle button:not(.active):hover { background: #EEF2FF; }

/* Footer sticky con los botones de acción principales */
.modal-footer {
  display: flex; justify-content: flex-end; gap: 0.75rem;
  padding: 1.1rem 1.75rem 1.25rem;
  border-top: 1px solid #e8ecf4;
  background: linear-gradient(180deg, #fafbfc 0%, #fff 100%);
  flex-shrink: 0; /* Evita que el footer se comprima cuando el body tiene scroll */
}
.btn-cancel {
  padding: 0.7rem 1.5rem; border-radius: 10px;
  border: 1.5px solid #E5E7EB; background: white;
  font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: inherit;
}
.btn-cancel:hover { background: #F9FAFB; }

/* Botón CTA principal: gradiente verde, sombra coloreada, efecto lift al hover */
.btn-publish {
  padding: 0.7rem 1.75rem; border-radius: 10px;
  background: linear-gradient(135deg, #059669, #10B981);
  color: white; border: none; font-weight: 700; cursor: pointer;
  transition: all 0.2s; font-family: inherit;
  box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-publish:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.4); }
/* :disabled: cuando el botón está deshabilitado, no se aplica el hover ni el cursor pointer */
.btn-publish:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

/*
  El wrapper del PlaceAutocompleteElement actúa como el "input visual":
  tiene el borde, el radius y el focus-ring, igual que los <input> normales.
  El web component interno tiene su propio borde desactivado para evitar doble borde.
*/
.place-autocomplete-wrapper {
  width: 100%;
  display: block;
  border: 1.5px solid #E5E7EB;
  border-radius: 10px;
  transition: border-color 0.2s, box-shadow 0.2s;
}

/* :focus-within se activa cuando el <input> interno recibe el foco */
.place-autocomplete-wrapper:focus-within {
  border-color: #6366F1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}

/* Cuando la dirección está verificada, el wrapper muestra borde verde */
.input-wrapper--verified .place-autocomplete-wrapper {
  border-color: #6EE7B7;
}

/*
  :deep(gmp-place-autocomplete): se le quita el borde propio de Google
  para que el borde lo controle el wrapper de arriba.
*/
:deep(gmp-place-autocomplete) {
  width: 100%;
  display: block;
  font-family: inherit;
  font-size: 0.9rem;
  --gmp-input-border-color: transparent;
  --gmp-input-border-radius: 0;
  --gmp-input-font-size: 0.9rem;
  --gmp-input-padding: 0.7rem 0.9rem;
}

/* Responsive: en pantallas <= 800px el grid de dos columnas pasa a una columna */
@media (max-width: 800px) {
  .form-row { grid-template-columns: 1fr; }
  .modal-content { margin: 1rem; border-radius: 16px; }
  .modal-body { padding: 1.25rem; }
}
</style>
