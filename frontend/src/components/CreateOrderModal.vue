<!-- ==================================================================
  🤖 CreateOrderModal – MODAL IA CONVERSACIONAL
  ==================================================================
  Modal exclusivo para generar pedidos mediante IA conversacional.
  Conserva 100% de la lógica IA existente:
  - textarea + análisis IA
  - reconocimiento de voz
  - resumen inteligente
  - geocoding con Google Places
  - detección automática de datos
  - flujo conversacional con contexto
  ================================================================== -->
<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'

const emit = defineEmits(['close', 'created'])

const submitting = ref(false)

// ─── IA Integration ────────────────────────────────────────────────────────────
const aiMessage = ref('')          // WhatsApp message from dispatcher
const aiLoading = ref(false)       // Loading state for IA processing
const aiError = ref('')            // Error message if IA fails
const aiSuccess = ref(false)       // Whether IA successfully filled the form

// ─── Voice Input (SpeechRecognition) ──────────────────────────────────────────
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
  recognitionInstance.lang = 'es-MX'
  recognitionInstance.continuous = false
  recognitionInstance.interimResults = true
  recognitionInstance.maxAlternatives = 1

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
      console.log('[Voz] Error network. El navegador no pudo conectar con el servicio de voz de Google.')
      voiceError.value = 'El servicio de voz de Google no está disponible en este momento. Escribe el mensaje manualmente o intenta más tarde.'
    } else if (event.error === 'aborted') {
      voiceError.value = 'Grabación cancelada. Intenta de nuevo.'
    } else if (event.error === 'language-not-supported') {
      voiceError.value = 'Idioma no soportado. Intenta en español.'
    } else if (event.error === 'service-not-allowed') {
      voiceError.value = 'Servicio de voz no disponible. Intenta más tarde.'
    } else {
      voiceError.value = 'Error al grabar: ' + event.error + '. Verifica tu conexión a Internet y vuelve a intentar.'
    }
    setTimeout(() => { voiceState.value = 'idle'; voiceError.value = '' }, 5000)
  }

  recognitionInstance.onend = () => {
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
    } catch (e) {}
    recognitionInstance = null
  }
  voiceState.value = 'stopped'
  setTimeout(() => { voiceState.value = 'idle' }, 2000)
}

// ─── IA Conversacional ─────────────────────────────────────────────────────────
const aiContext = ref([])
const aiSummary = ref(null)

const aiResolvedPickup = ref('')
const aiResolvedDrop = ref('')
const aiPickupVerified = ref(false)
const aiDropVerified = ref(false)

// ─── Form state (needed for saveOrder and summary) ────────────────────────────
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

// ─── IA: Procesar mensaje WhatsApp (FLUJO CONVERSACIONAL) ────────────────────
const processWithIA = async () => {
  if (!aiMessage.value.trim()) return

  aiLoading.value = true
  aiError.value = ''
  aiSuccess.value = false

  try {
    aiContext.value.push({ role: 'user', content: aiMessage.value })

    const response = await api.post('../ia/procesar-mensaje', {
      mensaje: aiMessage.value,
      contexto: aiContext.value,
      datos_actuales: aiSummary.value
    })

    const apiResponse = response.data
    const data = apiResponse.data

    console.log('[IA Debug] response.data:', apiResponse)
    console.log('[IA Debug] data extraida:', data)

    aiContext.value.push({ role: 'assistant', content: JSON.stringify(data) })

    if (!aiSummary.value) {
      aiSummary.value = {}
    }

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

    // Rellenar el formulario
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

    // Resolver scheduled_at
    resolveScheduledAt(data)

    // Limpiar textarea
    aiMessage.value = ''

    // Geocodificar direcciones
    await geocodeAddresses()

    // Construir resumen visual
    buildAiSummary()

    // Marcar exito
    aiSuccess.value = true

  } catch (error) {
    console.error('Error procesando con IA:', error)
    aiError.value = error.response?.data?.message || 'Error al procesar el mensaje con IA. Intenta de nuevo.'
  } finally {
    aiLoading.value = false
  }
}

// ─── IA: Resolver scheduled_at automaticamente ──────────────────────────────
const resolveScheduledAt = (data) => {
  const now = new Date()

  if (data.scheduled_time) {
    const [hours, minutes] = data.scheduled_time.split(':')
    const fullText = aiContext.value.map(m => m.content).join(' ').toLowerCase()
    let targetDate = new Date(now)

    if (fullText.includes('pasado mañana') || fullText.includes('pasado manana') || fullText.includes('pasado')) {
      targetDate.setDate(targetDate.getDate() + 2)
    } else if (fullText.includes('mañana') || fullText.includes('manana')) {
      targetDate.setDate(targetDate.getDate() + 1)
    } else if (fullText.includes('lunes')) {
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

    targetDate.setHours(parseInt(hours), parseInt(minutes), 0)
    form.value.scheduled_at = formatDateToMySQL(targetDate)
  } else {
    form.value.scheduled_at = formatDateToMySQL(now)
  }

  console.log('[IA Debug] scheduled_at resuelto:', form.value.scheduled_at)
}

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
const buildAiSummary = () => {
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
const geocodeAddresses = () => {
  return new Promise((resolve) => {
    if (!window.google?.maps?.places) {
      console.warn('Google Places API no disponible, usando Geocoder fallback.')
      resolve()
      return
    }

    const tempDiv = document.createElement('div')
    tempDiv.style.display = 'none'
    document.body.appendChild(tempDiv)

    const placesService = new google.maps.places.PlacesService(tempDiv)
    const promises = []

    if (form.value.pickup_address && !form.value.pickup_lat) {
      promises.push(new Promise((res) => {
        const request = {
          query: form.value.pickup_address,
          fields: ['formatted_address', 'geometry', 'place_id', 'name', 'types']
        }
        placesService.findPlaceFromQuery(request, (results, status) => {
          if (status === google.maps.places.PlacesServiceStatus.OK && results && results[0]) {
            const place = results[0]
            form.value.pickup_address = place.formatted_address
            form.value.pickup_lat = place.geometry.location.lat()
            form.value.pickup_lng = place.geometry.location.lng()
            console.log('✅ IA pickup resuelto con Places:', place.formatted_address, '| coords:', form.value.pickup_lat, form.value.pickup_lng)
          } else {
            console.warn('⚠️ IA Places pickup fallo (' + status + '), usando Geocoder fallback...')
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

    if (form.value.drop_address && !form.value.drop_lat) {
      promises.push(new Promise((res) => {
        const request = {
          query: form.value.drop_address,
          fields: ['formatted_address', 'geometry', 'place_id', 'name', 'types']
        }
        placesService.findPlaceFromQuery(request, (results, status) => {
          if (status === google.maps.places.PlacesServiceStatus.OK && results && results[0]) {
            const place = results[0]
            form.value.drop_address = place.formatted_address
            form.value.drop_lat = place.geometry.location.lat()
            form.value.drop_lng = place.geometry.location.lng()
            console.log('✅ IA drop resuelto con Places:', place.formatted_address, '| coords:', form.value.drop_lat, form.value.drop_lng)
          } else {
            console.warn('⚠️ IA Places drop fallo (' + status + '), usando Geocoder fallback...')
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

    Promise.all(promises).then(() => {
      document.body.removeChild(tempDiv)
      console.log('[IA Debug] Resolucion completada. pickup:', form.value.pickup_address, '| drop:', form.value.drop_address)
      console.log('[IA Debug] Coords - pickup_lat:', form.value.pickup_lat, 'pickup_lng:', form.value.pickup_lng, '| drop_lat:', form.value.drop_lat, 'drop_lng:', form.value.drop_lng)
      resolve()
    })

    if (promises.length === 0) {
      document.body.removeChild(tempDiv)
      resolve()
    }
  })
}

// ─── Save order (IA flow) ─────────────────────────────────────────────────────
const saveOrder = async () => {
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
</script>

<template>
  <Teleport to="body">
    <div class="modal-overlay" @click.self="$emit('close')">
      <div class="modal-content">

        <div class="modal-header">
          <div class="modal-title-group">
            <span class="modal-icon">🤖</span>
            <div>
              <h2>Generar con IA</h2>
              <p>Pega el mensaje de WhatsApp o dicta por voz. La IA extrae los datos automaticamente.</p>
            </div>
          </div>
          <button @click="$emit('close')" class="close-btn">&times;</button>
        </div>

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
            <transition name="fade-down">
              <div v-if="voiceError" class="voice-error">
                <span>🎤 {{ voiceError }}</span>
              </div>
            </transition>
            <transition name="fade-down">
              <div v-if="aiError" class="ai-error">
                <span>⚠️ {{ aiError }}</span>
                <button @click="aiError = ''" class="ai-error-close">&times;</button>
              </div>
            </transition>
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
                    <template v-if="form.payment_type === 'prepaid'">Pago con saldo prepagado (Remitente)</template>
                    <template v-else-if="form.payment_type === 'cash_on_delivery'">El receptor paga solamente el envio al recibir</template>
                    <template v-else-if="form.payment_type === 'cash_full'">El receptor paga el producto + envio al recibir</template>
                    <template v-else>Pago con saldo prepagado (Remitente)</template>
                  </div>
                </div>

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

                <div class="ai-summary-field" v-if="aiSummary?.notes">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">📝</span>
                    <span class="field-label">Observaciones</span>
                    <span class="field-badge detected">Detectadas</span>
                  </div>
                  <div class="field-value notes-text">{{ aiSummary.notes }}</div>
                </div>

                <div class="ai-summary-field" v-if="aiSummary?.reference_code">
                  <div class="ai-summary-field-header">
                    <span class="field-icon">📍</span>
                    <span class="field-label">Referencia / Codigo</span>
                    <span class="field-badge detected">Detectado</span>
                  </div>
                  <div class="field-value">{{ aiSummary.reference_code }}</div>
                </div>
              </div>

              <div class="ai-summary-actions">
                <button
                  type="button"
                  class="btn-ai-publish"
                  :disabled="submitting"
                  @click="saveOrder"
                >
                  <span v-if="submitting">Publicando...</span>
                  <span v-else>🚀 Publicar Viaje</span>
                </button>
                <button
                  type="button"
                  class="btn-ai-refine"
                  @click="aiSuccess = false; aiMessage.value = ''"
                >
                  ✏️ Corregir datos
                </button>
              </div>
            </div>
          </transition>

        </div>

        <div class="modal-footer">
          <button type="button" @click="$emit('close')" class="btn-cancel">Cancelar</button>
          <button
            type="button"
            class="btn-publish"
            :disabled="!aiSuccess || submitting"
            @click="saveOrder"
            v-if="aiSuccess"
          >
            <span v-if="submitting">Publicando...</span>
            <span v-else>🚀 Publicar Viaje</span>
          </button>
        </div>

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

.modal-body { padding: 1.75rem 2rem; display: flex; flex-direction: column; gap: 1.1rem; overflow-y: auto; flex: 1; }

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

/* ── AI Section ─────────────────────────────────────────────────── */
.ai-section {
  background: #F5F7FF; border: 1.5px solid #C7D2FE;
  border-radius: 14px; padding: 1.25rem;
  display: flex; flex-direction: column; gap: 0.85rem;
}
.ai-header { display: flex; align-items: flex-start; gap: 0.75rem; }
.ai-header-icon { font-size: 1.5rem; }
.ai-header-text { display: flex; flex-direction: column; gap: 0.15rem; }
.ai-title { font-weight: 700; font-size: 0.95rem; color: #1F2937; }
.ai-subtitle { font-size: 0.78rem; color: #6B7280; }

.ai-textarea-wrapper { display: flex; flex-direction: column; gap: 0.6rem; }
.ai-textarea {
  width: 100%; padding: 0.85rem 1rem;
  border: 1.5px solid #C7D2FE; border-radius: 10px;
  font-size: 0.9rem; outline: none; transition: all 0.2s;
  font-family: inherit; resize: vertical; min-height: 80px;
  line-height: 1.5;
}
.ai-textarea:focus { border-color: #6366F1; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
.ai-textarea.voice-listening {
  border-color: #EF4444;
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15);
  background: #FFF5F5;
}

.ai-voice-actions { display: flex; gap: 0.6rem; align-items: center; }

/* ── Voice Button ────────────────────────────────────────────────── */
.btn-voice {
  display: flex; align-items: center; gap: 0.4rem;
  padding: 0.5rem 1rem; border-radius: 10px;
  border: 1.5px solid #E5E7EB; background: white;
  font-weight: 600; cursor: pointer; transition: all 0.2s;
  font-family: inherit; font-size: 0.82rem;
}
.btn-voice:hover:not(:disabled) { border-color: #A5B4FC; background: #F5F7FF; }
.btn-voice:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-voice.voice-listening {
  border-color: #EF4444; background: #FEF2F2;
  animation: voicePulse 1.5s ease-in-out infinite;
}
.btn-voice.voice-stopped { border-color: #86EFAC; background: #F0FDF4; }
.btn-voice.voice-error { border-color: #FCA5A5; background: #FFF5F5; }

@keyframes voicePulse {
  0%, 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); }
  50% { box-shadow: 0 0 0 6px rgba(239, 68, 68, 0); }
}
.voice-icon { font-size: 1.1rem; }
.voice-label { font-size: 0.78rem; color: #6B7280; }
.voice-label-active { color: #DC2626; font-weight: 700; }
.voice-pulse {
  width: 10px; height: 10px; border-radius: 50%;
  background: #EF4444; display: inline-block;
  animation: micPulse 1s ease-in-out infinite;
}
@keyframes micPulse {
  0%, 100% { transform: scale(1); opacity: 1; }
  50% { transform: scale(1.3); opacity: 0.6; }
}

/* ── AI Analyze Button ───────────────────────────────────────────── */
.btn-ai-analyze {
  display: flex; align-items: center; gap: 0.4rem;
  padding: 0.5rem 1.25rem; border-radius: 10px;
  background: linear-gradient(135deg, #6366F1, #8B5CF6);
  color: white; border: none; font-weight: 700; cursor: pointer;
  transition: all 0.2s; font-family: inherit; font-size: 0.82rem;
  box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
}
.btn-ai-analyze:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(99, 102, 241, 0.4); }
.btn-ai-analyze:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

.ai-spinner {
  width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.3);
  border-top-color: white; border-radius: 50%;
  animation: spin 0.6s linear infinite; display: inline-block;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── AI Messages ─────────────────────────────────────────────────── */
.voice-error, .ai-error, .ai-success {
  padding: 0.65rem 1rem; border-radius: 10px;
  font-size: 0.82rem; display: flex; align-items: center; gap: 0.5rem;
}
.voice-error { background: #FFF5F5; border: 1px solid #FECACA; color: #991B1B; }
.ai-error { background: #FFF7ED; border: 1px solid #FED7AA; color: #9A3412; }
.ai-success { background: #F0FDF4; border: 1px solid #86EFAC; color: #166534; }
.ai-error-close {
  margin-left: auto; background: none; border: none;
  font-size: 1.2rem; cursor: pointer; color: #9A3412; padding: 0 0.25rem;
}

/* ── AI Summary Section ──────────────────────────────────────────── */
.ai-summary-section {
  background: white; border: 1.5px solid #C7D2FE;
  border-radius: 14px; overflow: hidden;
}
.ai-summary-header {
  display: flex; align-items: flex-start; gap: 0.75rem;
  padding: 1rem 1.25rem;
  background: linear-gradient(135deg, #EEF2FF, #E0E7FF);
  border-bottom: 1px solid #C7D2FE;
}
.ai-summary-icon { font-size: 1.5rem; }
.ai-summary-header-text { display: flex; flex-direction: column; gap: 0.1rem; }
.ai-summary-title { font-weight: 700; font-size: 0.9rem; color: #4338CA; }
.ai-summary-subtitle { font-size: 0.75rem; color: #6B7280; }

.ai-summary-body { padding: 0.75rem 1.25rem; display: flex; flex-direction: column; gap: 0.6rem; }
.ai-summary-field {
  padding: 0.6rem 0.75rem; border-radius: 10px;
  background: #F9FAFB; border: 1px solid #F3F4F6;
  transition: all 0.2s;
}
.ai-summary-field.verified { background: #F0FDF4; border-color: #86EFAC; }
.ai-summary-field-header {
  display: flex; align-items: center; gap: 0.4rem;
  margin-bottom: 0.25rem; flex-wrap: wrap;
}
.field-icon { font-size: 0.9rem; }
.field-label { font-weight: 600; font-size: 0.78rem; color: #374151; }
.field-badge {
  font-size: 0.68rem; font-weight: 700; padding: 0.1rem 0.45rem;
  border-radius: 6px; margin-left: auto;
}
.field-badge.verified { background: #DCFCE7; color: #166534; }
.field-badge.pending { background: #FEF3C7; color: #92400E; }
.field-badge.detected { background: #DBEAFE; color: #1E40AF; }
.field-badge.missing { background: #F3F4F6; color: #6B7280; }
.field-badge.info { background: #E0E7FF; color: #4338CA; }
.field-value {
  font-size: 0.85rem; color: #1F2937; padding-left: 1.3rem;
  word-break: break-word;
}
.field-value.verified-address { font-weight: 600; color: #065F46; }
.field-value.notes-text { font-style: italic; color: #6B7280; }

.ai-summary-actions {
  display: flex; gap: 0.75rem; padding: 1rem 1.25rem;
  border-top: 1px solid #E5E7EB;
}
.btn-ai-publish {
  flex: 1; padding: 0.7rem 1.5rem; border-radius: 10px;
  background: linear-gradient(135deg, #059669, #10B981);
  color: white; border: none; font-weight: 700; cursor: pointer;
  transition: all 0.2s; font-family: inherit;
  box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-ai-publish:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.4); }
.btn-ai-publish:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }

.btn-ai-refine {
  padding: 0.7rem 1.25rem; border-radius: 10px;
  border: 1.5px solid #E5E7EB; background: white;
  font-weight: 600; cursor: pointer; transition: all 0.2s; font-family: inherit;
}
.btn-ai-refine:hover { background: #F9FAFB; border-color: #A5B4FC; }

/* ── Transitions ─────────────────────────────────────────────────── */
.fade-down-enter-active, .fade-down-leave-active { transition: all 0.25s ease; }
.fade-down-enter-from, .fade-down-leave-to { opacity: 0; transform: translateY(-8px); }

@media (max-width: 800px) {
  .modal-content { margin: 1rem; border-radius: 16px; }
  .modal-body { padding: 1.25rem; }
  .ai-voice-actions { flex-wrap: wrap; }
}
</style>
