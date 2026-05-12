<!-- ==================================================================
  🚀 CreateOrderModal – FORM ORIGINAL + IA INTEGRATION
  ================================================================== -->
<script setup>
import { ref, computed, onMounted, watch, reactive } from 'vue'
import api from '../api'
import MapService from '../services/maps/MapService'

const emit = defineEmits(['close', 'created'])

const userBalance = ref(0)
const calculatedPrice = ref(0)
const routeDistance = ref('')
const routeTime = ref('')
const outOfZoneError = ref('')
const submitting = ref(false)
const clientZones = ref([])

// ─── IA Integration ────────────────────────────────────────────────────────────
const aiMessage = ref('')          // WhatsApp message from dispatcher
const aiLoading = ref(false)       // Loading state for IA processing
const aiError = ref('')            // Error message if IA fails
const aiSuccess = ref(false)       // Whether IA successfully filled the form

// ─── Voice Input (SpeechRecognition) ──────────────────────────────────────────
// Permite al despachador dictar el mensaje en lugar de escribirlo.
// Usa la Web Speech API nativa del navegador (SpeechRecognition).
// Compatible con Chrome Android, Edge, navegadores modernos.
const voiceState = ref('idle')     // 'idle' | 'listening' | 'processing' | 'error' | 'stopped'
const voiceError = ref('')         // Mensaje de error si SpeechRecognition falla
const voiceSupported = ref(false)  // Detecta si el navegador soporta SpeechRecognition
let recognitionInstance = null     // Instancia de SpeechRecognition

// Detecta soporte de SpeechRecognition al montar el componente
onMounted(() => {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
  voiceSupported.value = !!SpeechRecognition
})

// Inicia la grabación de voz
const startVoiceRecognition = () => {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition
  if (!SpeechRecognition) {
    voiceError.value = 'Tu navegador no soporta dictado por voz'
    voiceState.value = 'error'
    setTimeout(() => { voiceState.value = 'idle'; voiceError.value = '' }, 3000)
    return
  }

  // Si ya está escuchando, detener
  if (voiceState.value === 'listening') {
    stopVoiceRecognition()
    return
  }

  voiceState.value = 'listening'
  voiceError.value = ''

  recognitionInstance = new SpeechRecognition()
  recognitionInstance.lang = 'es-MX'       // Español México
  recognitionInstance.continuous = false    // Detener al terminar de hablar
  recognitionInstance.interimResults = true // Mostrar resultados parciales
  recognitionInstance.maxAlternatives = 1

  // Variable para acumular el texto final
  let finalTranscript = ''

  recognitionInstance.onresult = (event) => {
    let interimTranscript = ''
    for (let i = event.resultIndex; i < event.results.length; i++) {
      const transcript = event.results[i][0].transcript
      if (event.results[i].isFinal) {
        finalTranscript += transcript
      } else {
        interimTranscript += transcript
      }
    }
    // Mostrar el texto en el textarea mientras se dicta
    // Si hay texto existente, concatenar sin borrar
    if (finalTranscript) {
      const existingText = aiMessage.value.trim()
      if (existingText && !existingText.endsWith(' ') && !existingText.endsWith('\n')) {
        aiMessage.value = existingText + ' ' + finalTranscript
      } else {
        aiMessage.value = existingText + finalTranscript
      }
      finalTranscript = ''
    }
    if (interimTranscript) {
      // Mostrar feedback visual del texto parcial (opcional)
      console.log('[Voz] Parcial:', interimTranscript)
    }
  }

  recognitionInstance.onerror = (event) => {
    console.error('[Voz] Error:', event.error, '| Mensaje:', event.message || '')
    voiceState.value = 'error'
    if (event.error === 'not-allowed') {
      voiceError.value = 'Permiso de micrófono denegado. Actívalo en la configuración del navegador.'
    } else if (event.error === 'no-speech') {
      voiceError.value = 'No se detectó voz. Intenta de nuevo.'
    } else if (event.error === 'audio-capture') {
      voiceError.value = 'No se encontró micrófono. Conecta uno e intenta de nuevo.'
    } else if (event.error === 'network') {
      voiceError.value = 'Error de conexión. El reconocimiento de voz necesita Internet. Verifica tu conexión y vuelve a intentar.'
    } else if (event.error === 'aborted') {
      voiceError.value = 'Grabación cancelada. Intenta de nuevo.'
    } else if (event.error === 'language-not-supported') {
      voiceError.value = 'Idioma no soportado. Intenta en español.'
    } else if (event.error === 'service-not-allowed') {
      voiceError.value = 'Servicio de voz no disponible. Intenta más tarde.'
    } else {
      voiceError.value = 'Error al grabar: ' + event.error + '. Verifica tu conexión a Internet y vuelve a intentar.'
    }
    // Mantener el error visible por más tiempo para que el usuario pueda leerlo
    setTimeout(() => { voiceState.value = 'idle'; voiceError.value = '' }, 5000)
  }

  recognitionInstance.onend = () => {
    // Solo cambiar a stopped si no hubo error
    if (voiceState.value === 'listening') {
      voiceState.value = 'stopped'
      setTimeout(() => { voiceState.value = 'idle' }, 2000)
    }
  }

  recognitionInstance.start()
}

// Detiene la grabación de voz manualmente
const stopVoiceRecognition = () => {
  if (recognitionInstance) {
    try {
      recognitionInstance.stop()
    } catch (e) {
      // Ignorar errores al detener
    }
    recognitionInstance = null
  }
  voiceState.value = 'stopped'
  setTimeout(() => { voiceState.value = 'idle' }, 2000)
}

// ─── IA Conversacional ─────────────────────────────────────────────────────────
// Almacena el contexto de la conversacion para que la IA pueda
// actualizar datos incrementalmente sin perder informacion previa.
const aiContext = ref([])          // Historial de mensajes [{role, content}]
const aiSummary = ref(null)        // Datos estructurados del resumen actual

// Datos del resumen inteligente que se muestra en la vista previa
const aiResolvedPickup = ref('')   // Direccion de recogida verificada por Google
const aiResolvedDrop = ref('')     // Direccion de entrega verificada por Google
const aiPickupVerified = ref(false) // Si la direccion de recogida fue verificada
const aiDropVerified = ref(false)   // Si la direccion de entrega fue verificada

// ─── Scheduling ───────────────────────────────────────────────────────────────
const scheduledDate = ref(null)   // 'YYYY-MM-DD' | null
const scheduledHour = ref('12')   // '01'–'12'
const scheduledMinute = ref('00') // '00','15','30','45'
const scheduledAmpm = ref('PM')

const tomorrowDate = computed(() => {
  const d = new Date(); d.setDate(d.getDate() + 1)
  return d.toISOString().split('T')[0]
})
const afterTomorrowDate = computed(() => {
  const d = new Date(); d.setDate(d.getDate() + 2)
  return d.toISOString().split('T')[0]
})
const formattedScheduleDate = computed(() => {
  if (!scheduledDate.value) return ''
  const [y, m, day] = scheduledDate.value.split('-')
  const names = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']
  return `${day} ${names[parseInt(m) - 1]}`
})

const setScheduleDate = (which) => {
  scheduledDate.value = which === 'tomorrow' ? tomorrowDate.value : afterTomorrowDate.value
}
const clearSchedule = () => { scheduledDate.value = null }

let mapInstance = null
let pickupMarker = null
let dropMarker = null
let directionsService = null
let directionsRenderer = null

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

// ─── Template refs for the two address inputs ───────────────────────────────
const pickupInput = ref(null)
const dropInput = ref(null)

const canAffordOrder = computed(() => userBalance.value >= 1)

// Derived total the driver will collect at delivery
const totalToCollect = computed(() => {
  if (form.value.payment_type === 'prepaid') return 0
  if (form.value.payment_type === 'cash_on_delivery') return calculatedPrice.value
  if (form.value.payment_type === 'cash_full') {
    return calculatedPrice.value + (parseFloat(form.value.product_amount) || 0)
  }
  return 0
})

// ─── IA: Procesar mensaje WhatsApp (FLUJO CONVERSACIONAL) ────────────────────
// Recibe el texto pegado por el despachador, lo envia a la IA junto con el
// contexto de mensajes anteriores, y genera un resumen inteligente.
//
// La IA NO llena el formulario final inmediatamente.
// PRIMERO muestra una vista previa en texto plano para que el despachador
// revise, corrija o complete datos con otro prompt.
//
// Flujo conversacional:
//   1. Primer mensaje: "Envio de Lago 56 a Hidalgo 94"
//      → IA genera resumen parcial
//   2. Segundo mensaje: "El receptor se llama Juan y su telefono es 4611234567"
//      → IA conserva contexto anterior, actualiza solo campos faltantes
//      → Mantiene direcciones ya verificadas
//      → Actualiza resumen inteligente
const processWithIA = async () => {
  if (!aiMessage.value.trim()) return

  aiLoading.value = true
  aiError.value = ''
  aiSuccess.value = false

  try {
    // ─── Construir payload con contexto conversacional ────────────────
    // Agregar el mensaje actual al contexto
    aiContext.value.push({ role: 'user', content: aiMessage.value })

    // NOTA: El endpoint IA esta FUERA del grupo api/v1 (ver Routes.php linea 84)
    // Usamos ../ para subir de /api/v1 a /api y llegar a /api/ia/procesar-mensaje
    const response = await api.post('../ia/procesar-mensaje', {
      mensaje: aiMessage.value,
      contexto: aiContext.value,       // Enviar historial completo
      datos_actuales: aiSummary.value  // Enviar datos ya extraidos previamente
    })

    // La API devuelve: { status, message, data: { pickup_address, delivery_address, ... } }
    // response.data = el body HTTP completo
    // response.data.data = los datos reales del viaje
    const apiResponse = response.data
    const data = apiResponse.data

    console.log('[IA Debug] response.data:', apiResponse)
    console.log('[IA Debug] data extraida:', data)

    // ─── Guardar respuesta IA en el contexto ──────────────────────────
    aiContext.value.push({ role: 'assistant', content: JSON.stringify(data) })

    // ─── Fusionar datos nuevos con los existentes (actualizacion incremental) ──
    // Si ya teniamos datos previos, solo sobreescribimos los campos que la IA
    // devuelve con valor. Los campos que ya estaban y no se mencionan se conservan.
    if (!aiSummary.value) {
      aiSummary.value = {}
    }

    // Fusionar campo por campo: solo actualizar si el nuevo valor NO es null/vacio
    const mergeField = (field, value) => {
      if (value !== null && value !== undefined && value !== '') {
        aiSummary.value[field] = value
      }
    }

    mergeField('pickup_address', data.pickup_address)
    mergeField('delivery_address', data.delivery_address)
    mergeField('receiver_name', data.receiver_name)
    mergeField('receiver_phone', data.receiver_phone)
    mergeField('reference_code', data.reference_code)
    mergeField('description', data.description)
    mergeField('scheduled_time', data.scheduled_time)
    mergeField('notes', data.notes)
    mergeField('payment_type', data.payment_type)
    mergeField('product_amount', data.product_amount)

    // ─── Rellenar el formulario ORIGINAL con los datos fusionados ─────
    // Incluyendo payment_type y product_amount para que el flujo existente
    // de totalToCollect, pricing, saveOrder funcione correctamente.
    if (aiSummary.value.payment_type) {
      form.value.payment_type = aiSummary.value.payment_type
    }
    if (aiSummary.value.product_amount !== null && aiSummary.value.product_amount !== undefined) {
      form.value.product_amount = aiSummary.value.product_amount
    }
    if (aiSummary.value.pickup_address) {
      form.value.pickup_address = aiSummary.value.pickup_address
      form.value.pickup_lat = null
      form.value.pickup_lng = null
    }
    if (aiSummary.value.delivery_address) {
      form.value.drop_address = aiSummary.value.delivery_address
      form.value.drop_lat = null
      form.value.drop_lng = null
    }
    if (aiSummary.value.receiver_name) {
      form.value.receiver_name = aiSummary.value.receiver_name
    }
    if (aiSummary.value.receiver_phone) {
      form.value.receiver_phone = aiSummary.value.receiver_phone
    }
    if (aiSummary.value.reference_code) {
      if (aiSummary.value.description) {
        form.value.description = aiSummary.value.description + ' | Ref: ' + aiSummary.value.reference_code
      } else {
        form.value.description = 'Ref: ' + aiSummary.value.reference_code
      }
    }
    if (aiSummary.value.description && !aiSummary.value.reference_code) {
      form.value.description = aiSummary.value.description
    }

    // ─── Resolver scheduled_at automaticamente ────────────────────────
    resolveScheduledAt(data)

    // ─── Limpiar textarea ─────────────────────────────────────────────
    aiMessage.value = ''

    // ─── Geocodificar direcciones con Google Places ───────────────────
    await geocodeAddresses()

    // ─── Actualizar resumen visual con direcciones verificadas ────────
    buildAiSummary()

    // ─── Marcar exito ─────────────────────────────────────────────────
    aiSuccess.value = true

  } catch (error) {
    console.error('Error procesando con IA:', error)
    aiError.value = error.response?.data?.message || 'Error al procesar el mensaje con IA. Intenta de nuevo.'
  } finally {
    aiLoading.value = false
  }
}

// ─── IA: Resolver scheduled_at automaticamente ──────────────────────────────
// Si el cliente dice "a las 5", "a las 7 pm", "pasen en una hora":
//   → Usar FECHA ACTUAL + hora detectada
// Si el cliente dice "mañana", "pasado mañana", "el viernes", "el lunes":
//   → Usar esa fecha futura + hora detectada
// Si NO hay horario:
//   → Usar FECHA Y HORA ACTUAL
const resolveScheduledAt = (data) => {
  const now = new Date()

  if (data.scheduled_time) {
    // Hay hora detectada: HH:MM:SS
    const [hours, minutes] = data.scheduled_time.split(':')

    // Detectar si menciono "manana" o "pasado manana" en el contexto
    const fullText = aiContext.value.map(m => m.content).join(' ').toLowerCase()
    let targetDate = new Date(now)

    if (fullText.includes('pasado mañana') || fullText.includes('pasado manana') || fullText.includes('pasado')) {
      targetDate.setDate(targetDate.getDate() + 2)
    } else if (fullText.includes('mañana') || fullText.includes('manana')) {
      targetDate.setDate(targetDate.getDate() + 1)
    } else if (fullText.includes('lunes')) {
      // Proximo lunes
      const daysUntilMonday = (8 - targetDate.getDay()) % 7 || 7
      targetDate.setDate(targetDate.getDate() + daysUntilMonday)
    } else if (fullText.includes('martes')) {
      const daysUntilTuesday = (9 - targetDate.getDay()) % 7 || 7
      targetDate.setDate(targetDate.getDate() + daysUntilTuesday)
    } else if (fullText.includes('miercoles') || fullText.includes('miércoles')) {
      const daysUntilWednesday = (10 - targetDate.getDay()) % 7 || 7
      targetDate.setDate(targetDate.getDate() + daysUntilWednesday)
    } else if (fullText.includes('jueves')) {
      const daysUntilThursday = (11 - targetDate.getDay()) % 7 || 7
      targetDate.setDate(targetDate.getDate() + daysUntilThursday)
    } else if (fullText.includes('viernes')) {
      const daysUntilFriday = (12 - targetDate.getDay()) % 7 || 7
      targetDate.setDate(targetDate.getDate() + daysUntilFriday)
    } else if (fullText.includes('sabado') || fullText.includes('sábado')) {
      const daysUntilSaturday = (13 - targetDate.getDay()) % 7 || 7
      targetDate.setDate(targetDate.getDate() + daysUntilSaturday)
    } else if (fullText.includes('domingo')) {
      const daysUntilSunday = (14 - targetDate.getDay()) % 7 || 7
      targetDate.setDate(targetDate.getDate() + daysUntilSunday)
    }
    // Si no menciona dia, usar fecha actual

    targetDate.setHours(parseInt(hours), parseInt(minutes), 0)
    form.value.scheduled_at = formatDateToMySQL(targetDate)
  } else {
    // No hay hora: usar fecha y hora actual
    form.value.scheduled_at = formatDateToMySQL(now)
  }

  console.log('[IA Debug] scheduled_at resuelto:', form.value.scheduled_at)
}

// ─── Helper: formatear Date a MySQL datetime ────────────────────────────────
const formatDateToMySQL = (date) => {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  const h = String(date.getHours()).padStart(2, '0')
  const min = String(date.getMinutes()).padStart(2, '0')
  const s = String(date.getSeconds()).padStart(2, '0')
  return `${y}-${m}-${d} ${h}:${min}:${s}`
}

// ─── IA: Construir resumen visual inteligente ──────────────────────────────
// Toma los datos actuales del formulario y las direcciones verificadas
// por Google Places y construye el objeto de resumen que se muestra
// en la vista previa.
const buildAiSummary = () => {
  // Las direcciones verificadas ya fueron asignadas por geocodeAddresses()
  aiResolvedPickup.value = form.value.pickup_address
  aiResolvedDrop.value = form.value.drop_address
  aiPickupVerified.value = !!form.value.pickup_lat
  aiDropVerified.value = !!form.value.drop_lat

  console.log('[IA Debug] Resumen construido:', {
    pickup: aiResolvedPickup.value,
    drop: aiResolvedDrop.value,
    pickupVerified: aiPickupVerified.value,
    dropVerified: aiDropVerified.value,
    summary: aiSummary.value
  })
}

// ─── IA: Resolver direcciones con Google Places ──────────────────────────
// Toma las direcciones aproximadas que la IA relleno y las resuelve usando
// Google PlacesService.findPlaceFromQuery() para obtener la direccion
// OFICIAL de Google Places (formatted_address + coordenadas).
//
// Esto replica EXACTAMENTE lo que ocurre cuando el usuario selecciona una
// sugerencia del autocomplete: se actualiza pickup_address/drop_address
// con la direccion real de Google, se asignan coordenadas, y se disparan
// los watchers → rutas → pricing → markers → badges.
//
// PlacesService necesita un elemento DOM para inicializarse. Como el mapa
// aun no existe cuando se ejecuta processWithIA(), creamos un div temporal
// oculto para usarlo como contenedor.
//
// Retorna una Promise que se resuelve cuando ambas direcciones se resolvieron.
const geocodeAddresses = () => {
  return new Promise((resolve) => {
    if (!window.google?.maps?.places) {
      console.warn('Google Places API no disponible, usando Geocoder fallback.')
      resolve()
      return
    }

    // Crear un div temporal oculto para PlacesService (no necesita mapa real)
    const tempDiv = document.createElement('div')
    tempDiv.style.display = 'none'
    document.body.appendChild(tempDiv)

    const placesService = new google.maps.places.PlacesService(tempDiv)
    const promises = []

    // ─── Resolver pickup_address con PlacesService ─────────────────────
    if (form.value.pickup_address && !form.value.pickup_lat) {
      promises.push(new Promise((res) => {
        const request = {
          query: form.value.pickup_address,
          fields: ['formatted_address', 'geometry', 'place_id', 'name', 'types']
        }
        placesService.findPlaceFromQuery(request, (results, status) => {
          if (status === google.maps.places.PlacesServiceStatus.OK && results && results[0]) {
            const place = results[0]
            // Actualizar con la direccion OFICIAL de Google Places
            form.value.pickup_address = place.formatted_address
            form.value.pickup_lat = place.geometry.location.lat()
            form.value.pickup_lng = place.geometry.location.lng()
            console.log('✅ IA pickup resuelto con Places:', place.formatted_address, '| coords:', form.value.pickup_lat, form.value.pickup_lng)
          } else {
            console.warn('⚠️ IA Places pickup fallo (' + status + '), usando Geocoder fallback...')
            // Fallback a Geocoder
            const geocoder = new google.maps.Geocoder()
            geocoder.geocode({ address: form.value.pickup_address }, (geoResults, geoStatus) => {
              if (geoStatus === 'OK' && geoResults && geoResults[0]) {
                form.value.pickup_address = geoResults[0].formatted_address
                form.value.pickup_lat = geoResults[0].geometry.location.lat()
                form.value.pickup_lng = geoResults[0].geometry.location.lng()
                console.log('✅ IA pickup geocoder fallback:', geoResults[0].formatted_address, form.value.pickup_lat, form.value.pickup_lng)
              } else {
                console.warn('⚠️ IA Geocoder pickup fallback tambien fallo:', geoStatus)
              }
              res()
            })
            return
          }
          res()
        })
      }))
    }

    // ─── Resolver drop_address con PlacesService ───────────────────────
    if (form.value.drop_address && !form.value.drop_lat) {
      promises.push(new Promise((res) => {
        const request = {
          query: form.value.drop_address,
          fields: ['formatted_address', 'geometry', 'place_id', 'name', 'types']
        }
        placesService.findPlaceFromQuery(request, (results, status) => {
          if (status === google.maps.places.PlacesServiceStatus.OK && results && results[0]) {
            const place = results[0]
            // Actualizar con la direccion OFICIAL de Google Places
            form.value.drop_address = place.formatted_address
            form.value.drop_lat = place.geometry.location.lat()
            form.value.drop_lng = place.geometry.location.lng()
            console.log('✅ IA drop resuelto con Places:', place.formatted_address, '| coords:', form.value.drop_lat, form.value.drop_lng)
          } else {
            console.warn('⚠️ IA Places drop fallo (' + status + '), usando Geocoder fallback...')
            // Fallback a Geocoder
            const geocoder = new google.maps.Geocoder()
            geocoder.geocode({ address: form.value.drop_address }, (geoResults, geoStatus) => {
              if (geoStatus === 'OK' && geoResults && geoResults[0]) {
                form.value.drop_address = geoResults[0].formatted_address
                form.value.drop_lat = geoResults[0].geometry.location.lat()
                form.value.drop_lng = geoResults[0].geometry.location.lng()
                console.log('✅ IA drop geocoder fallback:', geoResults[0].formatted_address, form.value.drop_lat, form.value.drop_lng)
              } else {
                console.warn('⚠️ IA Geocoder drop fallback tambien fallo:', geoStatus)
              }
              res()
            })
            return
          }
          res()
        })
      }))
    }

    // Esperar a que todas las resoluciones terminen
    Promise.all(promises).then(() => {
      // Limpiar el div temporal
      document.body.removeChild(tempDiv)
      console.log('[IA Debug] Resolucion completada. pickup:', form.value.pickup_address, '| drop:', form.value.drop_address)
      console.log('[IA Debug] Coords - pickup_lat:', form.value.pickup_lat, 'pickup_lng:', form.value.pickup_lng, '| drop_lat:', form.value.drop_lat, 'drop_lng:', form.value.drop_lng)
      resolve()
    })

    // Si no hay nada que resolver, resolver inmediatamente
    if (promises.length === 0) {
      document.body.removeChild(tempDiv)
      resolve()
    }
  })
}


// ─── Load balance ─────────────────────────────────────────────────────────────
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

// ─── Attach Google Places Autocomplete to an input ───────────────────────────
const attachAutocomplete = (inputEl, addressField, latField, lngField) => {
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

  const autocomplete = new google.maps.places.Autocomplete(inputEl, {
    componentRestrictions: { country: 'mx' },
    fields: ['formatted_address', 'geometry', 'name', 'place_id', 'types'],
    types: ['geocode', 'establishment'],
    bounds: activeBounds,
    strictBounds: !!dynamicBounds
  })

  autocomplete.addListener('place_changed', () => {
    const place = autocomplete.getPlace()

    // Si es un negocio, usar el nombre + direccion formateada
    const isEstablishment = place.types?.includes('establishment') || 
                            place.types?.includes('point_of_interest') ||
                            place.types?.includes('food')
    
    let address
    if (isEstablishment && place.name && place.formatted_address) {
      address = `${place.name}, ${place.formatted_address}`
    } else {
      address = place.formatted_address || place.name || inputEl.value
    }
    form.value[addressField] = address

    if (place.geometry?.location) {
      // Modo completo: coordenadas directas desde Google
      form.value[latField] = place.geometry.location.lat()
      form.value[lngField] = place.geometry.location.lng()
      console.log(`✅ ${addressField} con coords:`, form.value[latField], form.value[lngField])
    } else {
      // Modo degradado: geocodificar la direccion para obtener coordenadas
      console.warn('⚠️ Geometry no disponible, usando Geocoder como fallback...')
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
  })
}

// ─── Save order ───────────────────────────────────────────────────────────────
const saveOrder = async () => {
  await loadBalance()
  if (!canAffordOrder.value) {
    alert('No te quedan viajes prepagados suficientes. Necesitas recargar saldo.')
    return
  }
  if (outOfZoneError.value) {
    alert(outOfZoneError.value)
    return
  }
  // Build scheduled_at in 'YYYY-MM-DD HH:mm:ss' (24hr) from the 12hr picker
  if (scheduledDate.value) {
    let h = parseInt(scheduledHour.value)
    if (scheduledAmpm.value === 'PM' && h !== 12) h += 12
    if (scheduledAmpm.value === 'AM' && h === 12) h = 0
    form.value.scheduled_at = `${scheduledDate.value} ${String(h).padStart(2, '0')}:${scheduledMinute.value}:00`
  } else {
    form.value.scheduled_at = null
  }

  submitting.value = true
  try {
    const response = await api.post('/orders', form.value)
    if (response.data.status) {
      emit('created', response.data.data)
      emit('close')
    }
  } catch (error) {
    console.error('Error saving order:', error)
    alert(error.response?.data?.message || 'Error al crear el pedido')
  } finally {
    submitting.value = false
  }
}

// ─── Map Updating ─────────────────────────────────────────────────────────────
watch(
  () => [form.value.pickup_lat, form.value.pickup_lng, form.value.drop_lat, form.value.drop_lng],
  () => {
    if (!mapInstance) return;

    const pLat = parseFloat(form.value.pickup_lat);
    const pLng = parseFloat(form.value.pickup_lng);
    const dLat = parseFloat(form.value.drop_lat);
    const dLng = parseFloat(form.value.drop_lng);

    const bounds = new google.maps.LatLngBounds();
    
    if (pickupMarker) pickupMarker.setMap(null);
    if (!isNaN(pLat) && !isNaN(pLng)) {
        const pos = { lat: pLat, lng: pLng };
        pickupMarker = new google.maps.Marker({
            position: pos, map: mapInstance, 
            icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png'
        });
        bounds.extend(pos);
    }

    if (dropMarker) dropMarker.setMap(null);
    if (!isNaN(dLat) && !isNaN(dLng)) {
        const pos = { lat: dLat, lng: dLng };
        dropMarker = new google.maps.Marker({
            position: pos, map: mapInstance, 
            icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png'
        });
        bounds.extend(pos);
    }

    if (!isNaN(pLat) && !isNaN(pLng) && !isNaN(dLat) && !isNaN(dLng)) {
        // Limpiar estado anterior
        calculatedPrice.value = 0
        routeDistance.value = ''
        routeTime.value = ''
        form.value.distance_km = null

        // Funcion que valida geofence y calcula precio con la distancia final
        const validateAndPrice = (distanceKm) => {
            api.post('/validate-geofence', {
                pickup_lat: pLat, pickup_lng: pLng,
                drop_lat: dLat,   drop_lng: dLng,
            }).then(() => {
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
                calculatedPrice.value = 0
                outOfZoneError.value = err.response?.data?.message || 'La zona de destino o recogida esta fuera de la zona de operaciones.'
            })
        }

        // Directions Service: obtener distancia real y luego calcular precio
        if (!directionsService) {
            directionsService = new google.maps.DirectionsService();
            directionsRenderer = new google.maps.DirectionsRenderer({
                map: mapInstance,
                suppressMarkers: true,
                preserveViewport: true,
                polylineOptions: { strokeColor: '#6366F1', strokeWeight: 4 }
            });
        } else {
            directionsRenderer.setDirections({ routes: [] });
        }

        directionsService.route({
            origin: { lat: pLat, lng: pLng },
            destination: { lat: dLat, lng: dLng },
            travelMode: google.maps.TravelMode.DRIVING
        }, (response, status) => {
            if (status === 'OK') {
                directionsRenderer.setDirections(response);
                const leg = response.routes[0].legs[0];
                routeDistance.value = leg.distance.text;
                routeTime.value = leg.duration.text;
                form.value.distance_km = leg.distance.value / 1000;
            } else {
                console.warn('Directions fallo (' + status + '), usando haversine.');
                routeDistance.value = '';
                routeTime.value = '';
                form.value.distance_km = null;
            }
            // Calcular precio con la distancia obtenida (real o null → backend usa haversine)
            validateAndPrice(form.value.distance_km);
        });
    } else {
        calculatedPrice.value = 0;
        outOfZoneError.value = '';
        routeDistance.value = '';
        routeTime.value = '';
        form.value.distance_km = null;
    }

    if (!bounds.isEmpty()) {
        // Delay bound fitting slightly so it handles initial loading gracefully
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
onMounted(async () => {
  loadBalance()

  // Carga geofences y SDK en paralelo para no bloquear la inicializacion del mapa
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

  // Small tick to ensure inputs are in the DOM
  setTimeout(() => {
    if (pickupInput.value) {
      attachAutocomplete(pickupInput.value, 'pickup_address', 'pickup_lat', 'pickup_lng')
    }
    if (dropInput.value) {
      attachAutocomplete(dropInput.value, 'drop_address', 'drop_lat', 'drop_lng')
    }

    // Init modal map
    if (window.google?.maps) {
        mapInstance = new google.maps.Map(document.getElementById('modal-map'), {
            center: { lat: 20.5222, lng: -100.8122 },
            zoom: 13,
            disableDefaultUI: true,
            zoomControl: true,
            styles: [
                { "elementType": "geometry", "stylers": [{ "color": "#f5f5f5" }] },
                { "elementType": "labels.icon", "stylers": [{ "visibility": "off" }] },
                { "featureType": "road", "elementType": "geometry", "stylers": [{ "color": "#ffffff" }] },
                { "featureType": "water", "elementType": "geometry", "stylers": [{ "color": "#c9c9c9" }] }
            ]
        });
    }
  }, 100)
})
</script>

<template>
  <Teleport to="body">
    <div class="modal-overlay" @click.self="$emit('close')">
      <div class="modal-content">

        <div class="modal-header">
          <div class="modal-title-group">
            <span class="modal-icon">🚀</span>
            <div>
              <h2>Generar Viaje</h2>
              <p>Completa los datos para publicar un nuevo envio.</p>
            </div>
          </div>
          <button @click="$emit('close')" class="close-btn">&times;</button>
        </div>

        <form @submit.prevent="saveOrder" class="modal-form">
          <div class="modal-body">

          <!-- ── AI WhatsApp Parser ───────────────────────────────────── -->
          <div class="ai-section">
            <div class="ai-header">
              <span class="ai-header-icon">🤖</span>
              <div class="ai-header-text">
                <span class="ai-title">Parseo Inteligente con IA</span>
                <span class="ai-subtitle">Pega el mensaje de WhatsApp y rellena el formulario automaticamente</span>
              </div>
            </div>
            <div class="ai-textarea-wrapper">
              <textarea
                v-model="aiMessage"
                class="ai-textarea"
                :class="{ 'voice-listening': voiceState === 'listening' }"
                placeholder='Pega el mensaje de WhatsApp o presiona 🎤 para dictar...&#10;&#10;Ej: "Recoger en Av. Benito Juarez 123, entregar en Col. Las Flores #45, a nombre de Juan Perez, tel 4611234567, es una pizza."'
                rows="3"
                :disabled="aiLoading"
              ></textarea>
              <div class="ai-voice-actions">
                <!-- Voice input button -->
                <button
                  type="button"
                  class="btn-voice"
                  :class="{
                    'voice-idle': voiceState === 'idle',
                    'voice-listening': voiceState === 'listening',
                    'voice-stopped': voiceState === 'stopped',
                    'voice-error': voiceState === 'error'
                  }"
                  :disabled="!voiceSupported || aiLoading"
                  @click.prevent="startVoiceRecognition"
                  :title="voiceState === 'listening' ? 'Toca para detener' : 'Dictar por voz'"
                >
                  <template v-if="voiceState === 'idle'">
                    <span class="voice-icon">🎤</span>
                    <span class="voice-label">Voz</span>
                  </template>
                  <template v-else-if="voiceState === 'listening'">
                    <span class="voice-pulse"></span>
                    <span class="voice-label voice-label-active">Escuchando...</span>
                  </template>
                  <template v-else-if="voiceState === 'stopped'">
                    <span class="voice-icon">✅</span>
                    <span class="voice-label">Dictado</span>
                  </template>
                  <template v-else-if="voiceState === 'error'">
                    <span class="voice-icon">⚠️</span>
                    <span class="voice-label">Error</span>
                  </template>
                </button>
                <!-- Analyze button -->
                <button
                  type="button"
                  class="btn-ai-analyze"
                  :disabled="!aiMessage.trim() || aiLoading"
                  @click.prevent="processWithIA"
                >
                  <span v-if="aiLoading" class="ai-spinner"></span>
                  <span v-else>🔍</span>
                  {{ aiLoading ? 'Analizando...' : 'Analizar' }}
                </button>
              </div>
            </div>
            <!-- Voice error message -->
            <transition name="fade-down">
              <div v-if="voiceError" class="voice-error">
                <span>🎤 {{ voiceError }}</span>
              </div>
            </transition>
            <!-- Error message -->
            <transition name="fade-down">
              <div v-if="aiError" class="ai-error">
                <span>⚠️ {{ aiError }}</span>
                <button @click="aiError = ''" class="ai-error-close">&times;</button>
              </div>
            </transition>
            <!-- Success message -->
            <transition name="fade-down">
              <div v-if="aiSuccess" class="ai-success">
                <span>✅ Datos cargados automaticamente. Revisa y corrige si es necesario antes de publicar.</span>
              </div>
            </transition>
          </div>

          <!-- ── Resumen Inteligente del Viaje ───────────────────────── -->
          <transition name="fade-down">
            <div v-if="aiSuccess && aiSummary" class="ai-summary-section">
              <div class="ai-summary-header">
                <span class="ai-summary-icon">🧠</span>
                <div class="ai-summary-header-text">
                  <span class="ai-summary-title">Resumen Inteligente del Viaje</span>
                  <span class="ai-summary-subtitle">Datos extraidos y verificados por IA + Google Places</span>
                </div>
              </div>

              <div class="ai-summary-body">
                <!-- Direccion de Recogida -->
                <div class="ai-summary-field" :class="{ verified: aiPickupVerified }">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">📍</span>
                    <span class="field-label">Direccion de Recogida</span>
                    <span v-if="aiPickupVerified" class="field-badge verified">✅ Verificada</span>
                    <span v-else class="field-badge pending">⏳ Pendiente</span>
                  </div>
                  <div class="field-value verified-address">
                    {{ aiResolvedPickup || '— No detectada —' }}
                  </div>
                </div>

                <!-- Direccion de Entrega -->
                <div class="ai-summary-field" :class="{ verified: aiDropVerified }">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">🏁</span>
                    <span class="field-label">Direccion de Entrega</span>
                    <span v-if="aiDropVerified" class="field-badge verified">✅ Verificada</span>
                    <span v-else class="field-badge pending">⏳ Pendiente</span>
                  </div>
                  <div class="field-value verified-address">
                    {{ aiResolvedDrop || '— No detectada —' }}
                  </div>
                </div>

                <!-- Nombre de quien recibe -->
                <div class="ai-summary-field">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">👤</span>
                    <span class="field-label">Nombre de quien recibe</span>
                    <span v-if="aiSummary?.receiver_name" class="field-badge detected">Detectado</span>
                    <span v-else class="field-badge missing">Faltante</span>
                  </div>
                  <div class="field-value">
                    {{ aiSummary?.receiver_name || '— No detectado —' }}
                  </div>
                </div>

                <!-- Telefono -->
                <div class="ai-summary-field">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">📞</span>
                    <span class="field-label">Telefono de quien recibe</span>
                    <span v-if="aiSummary?.receiver_phone" class="field-badge detected">Detectado</span>
                    <span v-else class="field-badge missing">Faltante</span>
                  </div>
                  <div class="field-value">
                    {{ aiSummary?.receiver_phone || '— No detectado —' }}
                  </div>
                </div>

                <!-- Fecha/Hora programada -->
                <div class="ai-summary-field">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">🕒</span>
                    <span class="field-label">Fecha/Hora programada</span>
                    <span v-if="form.scheduled_at" class="field-badge detected">Detectado</span>
                    <span v-else class="field-badge missing">No especificado</span>
                  </div>
                  <div class="field-value">
                    {{ form.scheduled_at ? form.scheduled_at.replace('T', ' ').substring(0, 16) : '— Ahora / Lo antes posible —' }}
                  </div>
                </div>

                <!-- Metodo de pago detectado -->
                <div class="ai-summary-field">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">💳</span>
                    <span class="field-label">Metodo de pago</span>
                    <span v-if="form.payment_type === 'prepaid'" class="field-badge info">Remitente paga</span>
                    <span v-else-if="form.payment_type === 'cash_on_delivery'" class="field-badge detected">Receptor paga envio</span>
                    <span v-else-if="form.payment_type === 'cash_full'" class="field-badge verified">Receptor paga producto + envio</span>
                    <span v-else class="field-badge info">Prepagado</span>
                  </div>
                  <div class="field-value">
                    <template v-if="form.payment_type === 'prepaid'">
                      Pago con saldo prepagado (Remitente)
                    </template>
                    <template v-else-if="form.payment_type === 'cash_on_delivery'">
                      El receptor paga solamente el envio al recibir
                    </template>
                    <template v-else-if="form.payment_type === 'cash_full'">
                      El receptor paga el producto + envio al recibir
                    </template>
                    <template v-else>
                      Pago con saldo prepagado (Remitente)
                    </template>
                  </div>
                </div>

                <!-- Cobro detectado (solo para cash_full) -->
                <div class="ai-summary-field" v-if="form.payment_type === 'cash_full' && form.product_amount">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">💵</span>
                    <span class="field-label">Cobro detectado</span>
                    <span class="field-badge verified">${{ parseFloat(form.product_amount).toFixed(2) }} MXN</span>
                  </div>
                  <div class="field-value">
                    El receptor pagara <strong>${{ parseFloat(form.product_amount).toFixed(2) }} MXN</strong> de producto + envio al recibir
                  </div>
                </div>

                <!-- Descripcion / Paquete -->
                <div class="ai-summary-field">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">📦</span>
                    <span class="field-label">Descripcion del envio</span>
                    <span v-if="aiSummary?.description" class="field-badge detected">Detectado</span>
                    <span v-else class="field-badge missing">No especificado</span>
                  </div>
                  <div class="field-value">
                    {{ aiSummary?.description || '— No detectado —' }}
                  </div>
                </div>

                <!-- Observaciones detectadas -->
                <div class="ai-summary-field" v-if="aiSummary?.notes">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">📝</span>
                    <span class="field-label">Observaciones</span>
                    <span class="field-badge detected">Detectadas</span>
                  </div>
                  <div class="field-value notes-text">
                    {{ aiSummary.notes }}
                  </div>
                </div>

                <!-- Referencias detectadas -->
                <div class="ai-summary-field" v-if="aiSummary?.reference_code">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">📍</span>
                    <span class="field-label">Referencia / Codigo</span>
                    <span class="field-badge detected">Detectado</span>
                  </div>
                  <div class="field-value">
                    {{ aiSummary.reference_code }}
                  </div>
                </div>
              </div>

              <!-- Acciones del resumen -->
              <div class="ai-summary-actions">
                <button
                  type="button"
                  class="btn-ai-continue"
                  @click="aiMessage.value = ''; aiSuccess.value = false"
                >
                  ✅ Continuar con estos datos
                </button>
                <button
                  type="button"
                  class="btn-ai-refine"
                  @click="aiMessage.value = ''; aiSuccess.value = false"
                >
                  ✏️ Corregir datos
                </button>
              </div>
            </div>
          </transition>

          <div class="ai-form-separator"></div>

          <!-- ⚠️ FORMULARIO COMPLETO COMENTADO TEMPORALMENTE ⚠️ -->
          <!--
          <div class="balance-pill" :class="{ 'insufficient': !canAffordOrder }">
            <span class="pill-label">{{ canAffordOrder ? '✅ Saldo disponible' : '⚠️ Saldo insuficiente' }}</span>
            <span class="pill-value">{{ userBalance }} viajes prepagados disponibles</span>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>📍 Direccion de Recogida</label>
              <div class="input-wrapper">
                <input
                  ref="pickupInput"
                  v-model="form.pickup_address"
                  type="text"
                  placeholder="Escribe para buscar..."
                  autocomplete="off"
                  required
                  @input="form.pickup_lat = null; form.pickup_lng = null; form.distance_km = null"
                />
                <span v-if="form.pickup_lat" class="coord-badge">✓ Ubicado</span>
              </div>
            </div>
            <div class="form-group">
              <label>🏁 Direccion de Entrega</label>
              <div class="input-wrapper">
                <input
                  ref="dropInput"
                  v-model="form.drop_address"
                  type="text"
                  placeholder="Escribe para buscar..."
                  autocomplete="off"
                  required
                  @input="form.drop_lat = null; form.drop_lng = null; form.distance_km = null"
                />
                <span v-if="form.drop_lat" class="coord-badge">✓ Ubicado</span>
              </div>
            </div>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>👤 Nombre de quien recibe</label>
              <div class="input-wrapper">
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
                <input
                  v-model="form.receiver_phone"
                  type="tel"
                  placeholder="Ej. 614 123 4567"
                  required
                />
              </div>
            </div>
          </div>

          <div class="form-group map-preview-wrapper" style="margin-top: 0.5rem; margin-bottom: 0.5rem;">
            <div id="modal-map" style="width: 100%; height: 260px; border-radius: 12px; border: 1px solid #E5E7EB; overflow: hidden;"></div>
          </div>

          <div class="form-group">
            <label>📝 Descripcion del Paquete</label>
            <textarea
              v-model="form.description"
              placeholder="Ej. 2 cajas de pizza, fragil. Manejar con cuidado."
            ></textarea>
          </div>

          <div class="form-group">
            <label>📅 Programar envio <span class="optional-tag">opcional</span></label>
            <div class="schedule-date-row">
              <button
                type="button"
                class="date-chip"
                :class="{ active: scheduledDate === tomorrowDate }"
                @click="setScheduleDate('tomorrow')"
              >Manana</button>
              <button
                type="button"
                class="date-chip"
                :class="{ active: scheduledDate === afterTomorrowDate }"
                @click="setScheduleDate('after_tomorrow')"
              >Pasado manana</button>
              <button
                v-if="scheduledDate"
                type="button"
                class="date-chip clear"
                @click="clearSchedule"
              >✕ Quitar</button>
            </div>

            <transition name="fade-down">
              <div v-if="scheduledDate" class="time-picker-row">
                <span class="schedule-date-label">{{ formattedScheduleDate }}</span>
                <div class="time-selects">
                  <select v-model="scheduledHour" class="time-select">
                    <option v-for="h in 12" :key="h" :value="String(h).padStart(2,'0')">{{ String(h).padStart(2,'0') }}</option>
                  </select>
                  <span class="time-colon">:</span>
                  <select v-model="scheduledMinute" class="time-select">
                    <option value="00">00</option>
                    <option value="15">15</option>
                    <option value="30">30</option>
                    <option value="45">45</option>
                  </select>
                  <div class="ampm-toggle">
                    <button type="button" :class="{ active: scheduledAmpm === 'AM' }" @click="scheduledAmpm = 'AM'">AM</button>
                    <button type="button" :class="{ active: scheduledAmpm === 'PM' }" @click="scheduledAmpm = 'PM'">PM</button>
                  </div>
                </div>
              </div>
            </transition>
          </div>

          <div class="form-group">
            <label>💳 Quien paga el envio?</label>
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
                <span class="coord-badge" style="background:#D1FAE5;color:#065F46">MXN</span>
              </div>
            </div>
          </transition>

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
                <span class="summary-cost">${{ calculatedPrice.toFixed(2) }} MXN</span>
              </div>
              <div class="summary-row" v-if="form.payment_type === 'cash_full' && form.product_amount">
                <span>Valor del Producto</span>
                <span class="summary-cost">${{ parseFloat(form.product_amount || 0).toFixed(2) }} MXN</span>
              </div>
              <div class="summary-row total" v-if="form.payment_type !== 'prepaid'">
                <span><strong>💵 Cobrar al receptor</strong></span>
                <span class="summary-cost highlight">${{ totalToCollect.toFixed(2) }} MXN</span>
              </div>
              <div class="summary-row">
                <span>Creditos a descontar</span>
                <span :class="canAffordOrder ? 'text-green' : 'text-red'">1 Viaje</span>
              </div>
            </template>
          </div>
          -->
          </div>

          <div class="modal-footer">
            <button type="button" @click="$emit('close')" class="btn-cancel">Cancelar</button>
            <button type="submit" class="btn-publish" :disabled="!canAffordOrder || submitting || !!outOfZoneError">
              <span v-if="submitting">Publicando...</span>
              <span v-else>🚀 Publicar Viaje</span>
            </button>
          </div>
        </form>

      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(6px);
  display: flex; align-items: center; justify-content: center;
  z-index: 2000;
  animation: fadeIn 0.2s ease;
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.modal-content {
  background: white;
  width: 100%; max-width: 780px;
  border-radius: 20px;
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
  animation: slideUp 0.25s ease;
  overflow: hidden;
  display: flex; flex-direction: column;
  max-height: 95dvh;
}
@keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.modal-header {
  display: flex; justify-content: space-between; align-items: center;
  padding: 1.5rem 2rem;
  background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
  color: white;
}
.modal-title-group { display: flex; align-items: center; gap: 1rem; }
.modal-icon { font-size: 2rem; }
.modal-header h2 { font-size: 1.2rem; font-weight: 700; margin: 0; }
.modal-header p { font-size: 0.8rem; opacity: 0.8; margin: 0; }

.close-btn {
  background: rgba(255,255,255,0.2); border: none; color: white;
  width: 32px; height: 32px; border-radius: 50%; font-size: 1.2rem;
  cursor: pointer; display: flex; align-items: center; justify-content: center;
  transition: background 0.2s;
}
.close-btn:hover { background: rgba(255,255,255,0.35); }

.modal-form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.modal-body { padding: 1.75rem 2rem; display: flex; flex-direction: column; gap: 1.1rem; overflow-y: auto; flex: 1; }

.balance-pill {
  display: flex; justify-content: space-between; align-items: center;
  padding: 0.75rem 1rem; border-radius: 10px;
  background: #F0FDF4; border: 1px solid #86EFAC;
  font-size: 0.85rem;
}
.balance-pill.insufficient { background: #FFF7ED; border-color: #FED7AA; }
.pill-label { font-weight: 600; }
.pill-value { color: #6B7280; }

.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

.form-group { display: flex; flex-direction: column; gap: 0.4rem; }
.form-group label { font-size: 0.82rem; font-weight: 600; color: #374151; }

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
.coord-badge {
  position: absolute; right: 0.6rem; top: 50%; transform: translateY(-50%);
  background: #DCFCE7; color: #166534;
  font-size: 0.7rem; font-weight: 700;
  padding: 0.2rem 0.5rem; border-radius: 6px; pointer-events: none;
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

.order-summary {
  background: #F9FAFB; border: 1px dashed #D1D5DB;
  border-radius: 10px; padding: 1rem;
  display: flex; flex-direction: column; gap: 0.5rem;
  margin-top: 0.5rem;
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

.payment-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.75rem; }
.payment-card {
  display: flex; flex-direction: column; align-items: center; gap: 0.3rem;
  padding: 0.85rem 0.5rem; border-radius: 12px;
  border: 2px solid #E5E7EB; cursor: pointer;
  transition: all 0.2s; text-align: center; position: relative;
  background: white;
}
.payment-card input[type="radio"] { display: none; }
.payment-card:hover { border-color: #A5B4FC; background: #F5F7FF; }
.payment-card.active { border-color: #6366F1; background: #EEF2FF; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); }
.pcard-icon { font-size: 1.6rem; }
.pcard-title { font-weight: 700; font-size: 0.82rem; color: #111827; }
.pcard-sub { font-size: 0.72rem; color: #6B7280; }
.payment-card.active .pcard-title { color: #4338CA; }

.fade-down-enter-active, .fade-down-leave-active { transition: all 0.25s ease; }
.fade-down-enter-from, .fade-down-leave-to { opacity: 0; transform: translateY(-8px); }

.optional-tag {
  font-weight: 400; font-size: 0.75rem; color: #9CA3AF;
  background: #F3F4F6; border-radius: 4px; padding: 0.1rem 0.4rem; margin-left: 0.3rem;
}
.schedule-date-row { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.date-chip {
  padding: 0.45rem 1rem; border-radius: 20px; border: 1.5px solid #E5E7EB;
  background: white; font-size: 0.82rem; font-weight: 600; cursor: pointer;
  transition: all 0.2s; color: #374151; font-family: inherit;
}
.date-chip:hover { border-color: #A5B4FC; background: #F5F7FF; }
.date-chip.active { border-color: #6366F1; background: #EEF2FF; color: #4338CA; }
.date-chip.clear { border-color: #FCA5A5; color: #DC2626; background: #FFF5F5; }
.date-chip.clear:hover { background: #FEE2E2; }

.time-picker-row {
  display: flex; align-items: center; gap: 1rem; margin-top: 0.6rem;
  padding: 0.6rem 0.9rem; border-radius: 10px;
  background: #F5F7FF; border: 1.5px solid #C7D2FE;
}
.schedule-date-label { font-size: 0.85rem; font-weight: 700; color: #4338CA; min-width: 50px; }
.time-selects { display: flex; align-items: center; gap: 0.4rem; }
.time-select {
  padding: 0.35rem 0.5rem; border-radius: 8px;
  border: 1.5px solid #C7D2FE; background: white;
  font-size: 0.95rem; font-weight: 700; color: #1F2937;
  font-family: inherit; cursor: pointer; outline: none;
}
.time-select:focus { border-color: #6366F1; }
.time-colon { font-weight: 800; font-size: 1.1rem; color: #6366F1; }
.ampm-toggle { display: flex; border-radius: 8px; overflow: hidden; border: 1.5px solid #C7D2FE; }
.ampm-toggle button {
  padding: 0.35rem 0.65rem; border: none; background: white;
  font-size: 0.78rem; font-weight: 700; cursor: pointer;
  color: #6B7280; font-family: inherit; transition: all 0.15s;
}
.ampm-toggle button.active { background: #6366F1; color: white; }
.ampm-toggle button:not(.active):hover { background: #EEF2FF; }

.modal-footer { 
  display: flex; justify-content: flex-end; gap: 0.75rem; 
  padding: 1.25rem 2rem; 
  border-top: 1px solid #F3F4F6;
  background: white;
  flex-shrink: 0;
}
.btn-cancel {
  padding: 0.7rem 1.5rem; border-radius: 10px;
  border: 1.5px solid #E5E7EB; background: white;
  font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: inherit;
}
.btn-cancel:hover { background: #F9FAFB; }

.btn-publish {
  padding: 0.7rem 1.75rem; border-radius: 10px;
  background: linear-gradient(135deg, #6366F1, #8B5CF6);
  color: white; border: none; font-weight: 700; cursor: pointer;
  transition: all 0.2s; font-family: inherit;
  box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}
.btn-publish:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4); }
.btn-publish:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

:deep(.pac-container) {
  border-radius: 10px !important;
  box-shadow: 0 10px 25px rgba(0,0,0,0.12) !important;
  border: 1px solid #E5E7EB !important;
  font-family: inherit !important;
  margin-top: 4px !important;
}
:deep(.pac-item) {
  padding: 0.6rem 1rem !important;
  font-size: 0.85rem !important;
  cursor: pointer !important;
}
:deep(.pac-item:hover) { background: #F5F7FF !important; }
:deep(.pac-item-query) { font-weight: 600 !important; color: #1F2937 !important; }

/* ── IA WhatsApp Parser Section ─────────────────────────── */
.ai-section {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  background: linear-gradient(135deg, #F0F9FF 0%, #E0F2FE 100%);
  border: 1.5px solid #BAE6FD;
  border-radius: 14px;
  padding: 1rem 1.1rem;
  transition: all 0.2s;
}
.ai-header {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
}
.ai-header-icon {
  font-size: 1.5rem;
  line-height: 1;
  flex-shrink: 0;
}
.ai-header-text {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}
.ai-title {
  font-weight: 700;
  font-size: 0.85rem;
  color: #1E3A5F;
}
.ai-subtitle {
  font-size: 0.72rem;
  color: #5B7FA5;
  line-height: 1.3;
}
.ai-textarea-wrapper {
  display: flex;
  gap: 0.6rem;
  align-items: flex-start;
}
.ai-textarea-wrapper textarea {
  flex: 1;
  padding: 0.7rem 0.9rem;
  border: 1.5px solid #BAE6FD;
  border-radius: 10px;
  font-size: 0.85rem;
  font-family: inherit;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
  resize: none;
  line-height: 1.5;
  background: white;
}
.ai-textarea-wrapper textarea:focus {
  border-color: #6366F1;
  box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
}
.ai-textarea-wrapper textarea::placeholder {
  color: #9CA3AF;
  font-size: 0.8rem;
}
.btn-ai-analyze {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.7rem 1rem;
  border-radius: 10px;
  border: none;
  background: linear-gradient(135deg, #6366F1, #8B5CF6);
  color: white;
  font-weight: 700;
  font-size: 0.82rem;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 3px 10px rgba(99, 102, 241, 0.25);
  white-space: nowrap;
  flex-shrink: 0;
}
.btn-ai-analyze:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 5px 14px rgba(99, 102, 241, 0.35);
}
.btn-ai-analyze:active:not(:disabled) {
  transform: translateY(0);
}
.btn-ai-analyze:disabled {
  opacity: 0.5;
  cursor: not-allowed;
  transform: none;
}
.ai-spinner {
  width: 16px;
  height: 16px;
  border: 2px solid rgba(255,255,255,0.3);
  border-top: 2px solid white;
  border-radius: 50%;
  animation: spin 0.6s linear infinite;
  display: inline-block;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}

/* ── Voice Input Button ────────────────────────────────── */
.ai-voice-actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  flex-shrink: 0;
}
.btn-voice {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 0.7rem 0.9rem;
  border-radius: 12px;
  border: 1.5px solid #BAE6FD;
  background: white;
  font-weight: 700;
  font-size: 0.82rem;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
  min-width: 90px;
  position: relative;
  overflow: hidden;
}
.btn-voice:hover:not(:disabled) {
  border-color: #6366F1;
  background: #F5F7FF;
  transform: translateY(-1px);
  box-shadow: 0 3px 10px rgba(99, 102, 241, 0.15);
}
.btn-voice:active:not(:disabled) {
  transform: translateY(0);
}
.btn-voice:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
/* Estado idle */
.btn-voice.voice-idle {
  border-color: #BAE6FD;
  background: white;
}
.btn-voice.voice-idle .voice-icon {
  font-size: 1.2rem;
}
.btn-voice.voice-idle .voice-label {
  color: #1E3A5F;
}
/* Estado listening */
.btn-voice.voice-listening {
  border-color: #EF4444;
  background: linear-gradient(135deg, #FEF2F2, #FEE2E2);
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15), 0 3px 10px rgba(239, 68, 68, 0.2);
  animation: voicePulse 1.2s ease-in-out infinite;
}
@keyframes voicePulse {
  0%, 100% { box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15), 0 3px 10px rgba(239, 68, 68, 0.2); }
  50% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0.08), 0 3px 14px rgba(239, 68, 68, 0.3); }
}
.btn-voice.voice-listening .voice-label-active {
  color: #DC2626;
  font-weight: 800;
}
.voice-pulse {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #EF4444;
  animation: micPulse 0.8s ease-in-out infinite;
  display: inline-block;
}
@keyframes micPulse {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.3); opacity: 0.7; }
}
/* Estado stopped */
.btn-voice.voice-stopped {
  border-color: #86EFAC;
  background: #F0FDF4;
}
.btn-voice.voice-stopped .voice-icon {
  font-size: 1rem;
}
.btn-voice.voice-stopped .voice-label {
  color: #166534;
}
/* Estado error */
.btn-voice.voice-error {
  border-color: #FCA5A5;
  background: #FEF2F2;
}
.btn-voice.voice-error .voice-icon {
  font-size: 1rem;
}
.btn-voice.voice-error .voice-label {
  color: #991B1B;
}
.voice-icon {
  line-height: 1;
  flex-shrink: 0;
}
.voice-label {
  font-size: 0.75rem;
  font-weight: 700;
  color: #1E3A5F;
  line-height: 1;
}
.voice-label-active {
  font-size: 0.72rem;
}
/* Textarea en modo listening */
.ai-textarea.voice-listening {
  border-color: #EF4444 !important;
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
  background: #FFF5F5 !important;
}
/* Voice error message */
.voice-error {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.5rem 0.8rem;
  background: #FFF7ED;
  border: 1px solid #FED7AA;
  border-radius: 10px;
  font-size: 0.8rem;
  color: #9A3412;
}

.ai-error {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.6rem 0.8rem;
  background: #FEF2F2;
  border: 1px solid #FECACA;
  border-radius: 10px;
  font-size: 0.82rem;
  color: #B91C1C;
}
.ai-error-close {
  background: none;
  border: none;
  color: #B91C1C;
  font-size: 1.1rem;
  cursor: pointer;
  padding: 0 0.25rem;
  opacity: 0.7;
  transition: opacity 0.2s;
}
.ai-error-close:hover { opacity: 1; }
.ai-success {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.6rem 0.8rem;
  background: #F0FDF4;
  border: 1px solid #86EFAC;
  border-radius: 10px;
  font-size: 0.82rem;
  color: #166534;
}
.ai-form-separator {
  height: 1px;
  background: linear-gradient(to right, transparent, #E5E7EB, transparent);
}

/* ── IA Resumen Inteligente ─────────────────────────────── */
.ai-summary-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
  border: 1.5px solid #C4B5FD;
  border-radius: 14px;
  padding: 1rem 1.1rem;
  animation: summarySlideIn 0.35s ease;
}
@keyframes summarySlideIn {
  from { opacity: 0; transform: translateY(-10px); }
  to { opacity: 1; transform: translateY(0); }
}
.ai-summary-header {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
}
.ai-summary-icon {
  font-size: 1.5rem;
  line-height: 1;
  flex-shrink: 0;
}
.ai-summary-header-text {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}
.ai-summary-title {
  font-weight: 700;
  font-size: 0.85rem;
  color: #4C1D95;
}
.ai-summary-subtitle {
  font-size: 0.72rem;
  color: #7C3AED;
  line-height: 1.3;
}
.ai-summary-body {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.ai-summary-field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: 0.55rem 0.75rem;
  background: white;
  border-radius: 10px;
  border: 1px solid #E5E7EB;
  transition: all 0.2s;
}
.ai-summary-field.verified {
  border-color: #86EFAC;
  background: #F0FDF4;
}
.ai-summary-field-header {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}
.field-icon {
  font-size: 0.9rem;
  flex-shrink: 0;
}
.field-label {
  font-size: 0.75rem;
  font-weight: 600;
  color: #6B7280;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  flex: 1;
}
.field-badge {
  font-size: 0.65rem;
  font-weight: 700;
  padding: 0.15rem 0.45rem;
  border-radius: 5px;
  white-space: nowrap;
}
.field-badge.verified {
  background: #DCFCE7;
  color: #166534;
}
.field-badge.pending {
  background: #FEF3C7;
  color: #92400E;
}
.field-badge.detected {
  background: #DBEAFE;
  color: #1E40AF;
}
.field-badge.missing {
  background: #FEE2E2;
  color: #991B1B;
}
.field-badge.info {
  background: #E0E7FF;
  color: #3730A3;
}
.field-value {
  font-size: 0.85rem;
  color: #1F2937;
  font-weight: 500;
  line-height: 1.4;
  padding-left: 1.3rem;
}
.field-value.verified-address {
  color: #065F46;
  font-weight: 600;
}
.field-value.notes-text {
  color: #6B7280;
  font-style: italic;
  font-size: 0.82rem;
}
.ai-summary-actions {
  display: flex;
  gap: 0.6rem;
  margin-top: 0.25rem;
}
.btn-ai-continue {
  flex: 1;
  padding: 0.65rem 1rem;
  border-radius: 10px;
  border: none;
  background: linear-gradient(135deg, #059669, #10B981);
  color: white;
  font-weight: 700;
  font-size: 0.82rem;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 3px 10px rgba(5, 150, 105, 0.25);
}
.btn-ai-continue:hover {
  transform: translateY(-1px);
  box-shadow: 0 5px 14px rgba(5, 150, 105, 0.35);
}
.btn-ai-refine {
  flex: 1;
  padding: 0.65rem 1rem;
  border-radius: 10px;
  border: 1.5px solid #C4B5FD;
  background: white;
  color: #4C1D95;
  font-weight: 700;
  font-size: 0.82rem;
  font-family: inherit;
  cursor: pointer;
  transition: all 0.2s;
}
.btn-ai-refine:hover {
  background: #F5F3FF;
  border-color: #7C3AED;
}

@media (max-width: 800px) {
  .form-row { grid-template-columns: 1fr; }
  .modal-content { margin: 1rem; border-radius: 16px; }
  .modal-body { padding: 1.25rem; }
  .ai-textarea-wrapper { flex-direction: column; }
  .ai-voice-actions {
    flex-direction: row;
    width: 100%;
  }
  .btn-voice {
    flex: 1;
    min-width: unset;
    padding: 0.8rem 0.5rem;
    font-size: 0.85rem;
    justify-content: center;
  }
  .btn-ai-analyze {
    flex: 2;
    width: auto;
    justify-content: center;
    padding: 0.8rem 1rem;
    font-size: 0.9rem;
  }
  .ai-textarea-wrapper textarea {
    min-height: 80px;
    font-size: 0.9rem;
  }
  .ai-summary-actions {
    flex-direction: column;
  }
}
</style>
