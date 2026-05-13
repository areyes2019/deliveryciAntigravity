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
import { ref, computed, onMounted, watch } from 'vue'
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

// ─── Helper: obtener fecha local YYYY-MM-DD ──────────────────────────────────
const getLocalDateStr = (date) => {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

// ─── Scheduling ───────────────────────────────────────────────────────────────
// Modo: 'asap' (Lo antes posible) | 'scheduled' (Programar envío)
const scheduleMode = ref('asap')

const scheduledDate = ref(null)
const scheduledHour = ref('12')
const scheduledMinute = ref('00')
const scheduledAmpm = ref('PM')

const todayDate = computed(() => {
  return getLocalDateStr(new Date())
})
const tomorrowDate = computed(() => {
  const d = new Date(); d.setDate(d.getDate() + 1)
  return getLocalDateStr(d)
})
const afterTomorrowDate = computed(() => {
  const d = new Date(); d.setDate(d.getDate() + 2)
  return getLocalDateStr(d)
})

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

const formattedScheduleDate = computed(() => {
  if (!scheduledDate.value) return ''
  const [y, m, day] = scheduledDate.value.split('-')
  const names = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']
  return `${day} ${names[parseInt(m) - 1]}`
})

// Validación: si es HOY, la hora NO debe haber pasado
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

const canSchedule = computed(() => {
  if (scheduleMode.value === 'asap') return true
  return scheduledDate.value && !scheduleTimeError.value
})

const setScheduleDate = (which) => {
  if (which === 'today') {
    scheduledDate.value = todayDate.value
  } else if (which === 'tomorrow') {
    scheduledDate.value = tomorrowDate.value
  } else if (which === 'after_tomorrow') {
    scheduledDate.value = afterTomorrowDate.value
  }
}

const enableScheduling = () => {
  scheduleMode.value = 'scheduled'
  // Por defecto seleccionar "Mañana" para evitar conflicto con hora actual
  scheduledDate.value = tomorrowDate.value
}

const clearSchedule = () => {
  scheduleMode.value = 'asap'
  scheduledDate.value = null
  scheduledHour.value = '12'
  scheduledMinute.value = '00'
  scheduledAmpm.value = 'PM'
}

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
      form.value[latField] = place.geometry.location.lat()
      form.value[lngField] = place.geometry.location.lng()
      console.log(`✅ ${addressField} con coords:`, form.value[latField], form.value[lngField])
    } else {
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
    // "HOY MISMO, TAN PRONTO COMO SEA POSIBLE" — NUNCA NULL
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
        calculatedPrice.value = 0
        routeDistance.value = ''
        routeTime.value = ''
        form.value.distance_km = null

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
    if (pickupInput.value) {
      attachAutocomplete(pickupInput.value, 'pickup_address', 'pickup_lat', 'pickup_lng')
    }
    if (dropInput.value) {
      attachAutocomplete(dropInput.value, 'drop_address', 'drop_lat', 'drop_lng')
    }

    if (window.google?.maps) {
        mapInstance = new google.maps.Map(document.getElementById('modal-map-manual'), {
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
      <div class="modal-content modal-content--manual">

        <div class="modal-header">
          <div class="modal-title-group">
            <span class="modal-icon" aria-hidden="true">📝</span>
            <div>
              <p class="modal-kicker">Nuevo envío</p>
              <h2>Formulario manual</h2>
              <p class="modal-subtitle">Completa los datos para publicar un envío en tu zona.</p>
            </div>
          </div>
          <button type="button" @click="$emit('close')" class="close-btn" aria-label="Cerrar">&times;</button>
        </div>

        <form @submit.prevent="saveOrder" class="modal-form">
          <div class="modal-body">

          <div class="balance-pill" :class="{ 'insufficient': !canAffordOrder }">
            <span class="pill-label">{{ canAffordOrder ? 'Saldo disponible' : 'Saldo insuficiente' }}</span>
            <span class="pill-value">{{ userBalance }} viajes prepagados</span>
          </div>

          <section class="manual-section" aria-labelledby="manual-address-heading">
            <h3 id="manual-address-heading" class="manual-section__title">Origen, destino y receptor</h3>
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

          <div class="form-group map-preview-wrapper">
            <div id="modal-map-manual" class="modal-map-preview"></div>
          </div>
          </section>

          <section class="manual-section" aria-labelledby="manual-package-heading">
            <h3 id="manual-package-heading" class="manual-section__title">Detalle del paquete</h3>
          <div class="form-group">
            <label>📝 Descripcion del Paquete</label>
            <textarea
              v-model="form.description"
              placeholder="Ej. 2 cajas de pizza, fragil. Manejar con cuidado."
            ></textarea>
          </div>
          </section>

          <section class="manual-section" aria-labelledby="manual-schedule-heading">
            <h3 id="manual-schedule-heading" class="manual-section__title">Programación</h3>
          <div class="form-group">
            <label>📅 Programar envio</label>
            
            <!-- Radio toggle: Lo antes posible vs Programar -->
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

            <!-- Scheduling UI (only visible when "Programar envío" is selected) -->
            <transition name="fade-down">
              <div v-if="scheduleMode === 'scheduled'" class="schedule-panel">
                <!-- Day selector -->
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

                <!-- Time picker (only visible when a day is selected) -->
                <div v-if="scheduledDate" class="time-picker-row">
                  <span class="schedule-date-label">{{ formattedScheduleDate }}</span>
                  <div class="time-selects">
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
                    <div class="ampm-toggle">
                      <button type="button" :class="{ active: scheduledAmpm === 'AM' }" @click="scheduledAmpm = 'AM'">AM</button>
                      <button type="button" :class="{ active: scheduledAmpm === 'PM' }" @click="scheduledAmpm = 'PM'">PM</button>
                    </div>
                  </div>
                  <!-- Error message if time already passed -->
                  <div v-if="scheduleTimeError" class="schedule-error">
                    ⚠️ {{ scheduleTimeError }}
                  </div>
                </div>

                <!-- Quitar button -->
                <button
                  type="button"
                  class="btn-quitar"
                  @click="clearSchedule"
                >✕ Quitar</button>
              </div>
            </transition>
          </div>
          </section>

          <section class="manual-section" aria-labelledby="manual-payment-heading">
            <h3 id="manual-payment-heading" class="manual-section__title">Pago y resumen</h3>
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
                <span class="coord-badge coord-badge--currency">MXN</span>
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
          </section>

          </div>

          <div class="modal-footer">
            <button type="button" @click="$emit('close')" class="btn-cancel">Cancelar</button>
            <button type="submit" class="btn-publish" :disabled="!canAffordOrder || submitting || !!outOfZoneError">
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

.modal-content--manual {
  border: 1px solid rgba(226, 232, 240, 0.95);
  box-shadow:
    0 0 0 1px rgba(255, 255, 255, 0.6) inset,
    0 28px 55px -18px rgba(15, 23, 42, 0.35);
}
@keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.modal-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  padding: 1.35rem 1.75rem 1.25rem;
  background: linear-gradient(135deg, #0f766e 0%, #059669 42%, #10b981 100%);
  color: white;
  position: relative;
  overflow: hidden;
}

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

.modal-form { display: flex; flex-direction: column; flex: 1; min-height: 0; }
.modal-body { padding: 1.5rem 1.75rem; display: flex; flex-direction: column; gap: 1.25rem; overflow-y: auto; flex: 1; background: linear-gradient(180deg, #f8fafc 0%, #fff 120px); }

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

.modal-map-preview {
  width: 100%;
  height: 260px;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  overflow: hidden;
  box-shadow: 0 8px 24px -16px rgba(15, 23, 42, 0.25);
}

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
.ampm-toggle { display: flex; border-radius: 8px; overflow: hidden; border: 1.5px solid #C7D2FE; flex-shrink: 0; }
.ampm-toggle button {
  padding: 0.35rem 0.65rem; border: none; background: white;
  font-size: 0.78rem; font-weight: 700; cursor: pointer;
  color: #6B7280; font-family: inherit; transition: all 0.15s;
}
.ampm-toggle button.active { background: #6366F1; color: white; }
.ampm-toggle button:not(.active):hover { background: #EEF2FF; }

.modal-footer { 
  display: flex; justify-content: flex-end; gap: 0.75rem; 
  padding: 1.1rem 1.75rem 1.25rem; 
  border-top: 1px solid #e8ecf4;
  background: linear-gradient(180deg, #fafbfc 0%, #fff 100%);
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
  background: linear-gradient(135deg, #059669, #10B981);
  color: white; border: none; font-weight: 700; cursor: pointer;
  transition: all 0.2s; font-family: inherit;
  box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
}
.btn-publish:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5, 150, 105, 0.4); }
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

@media (max-width: 800px) {
  .form-row { grid-template-columns: 1fr; }
  .modal-content { margin: 1rem; border-radius: 16px; }
  .modal-body { padding: 1.25rem; }
}
</style>
