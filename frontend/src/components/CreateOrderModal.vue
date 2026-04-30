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
  distance_km: null
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
    console.warn('Places API no disponible aún.')
    return
  }

  // Bounds dinámicos si hay zonas; fallback a Celaya
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

    // Si es un negocio, usar el nombre + dirección formateada
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
      // Modo degradado: geocodificar la dirección para obtener coordenadas
      console.warn('⚠️ Geometry no disponible, usando Geocoder como fallback...')
      const geocoder = new google.maps.Geocoder()
      geocoder.geocode({ address }, (results, status) => {
        if (status === 'OK' && results[0]) {
          form.value[latField] = results[0].geometry.location.lat()
          form.value[lngField] = results[0].geometry.location.lng()
          console.log(`✅ ${addressField} geocodificado:`, form.value[latField], form.value[lngField])
        } else {
          console.error('❌ Geocoder falló:', status)
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

        // Función que valida geofence y calcula precio con la distancia final
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
                outOfZoneError.value = err.response?.data?.message || 'La zona de destino o recogida está fuera de la zona de operaciones.'
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
                console.warn('Directions falló (' + status + '), usando haversine.');
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

  // Carga geofences y SDK en paralelo para no bloquear la inicialización del mapa
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

        <!-- Header -->
        <div class="modal-header">
          <div class="modal-title-group">
            <span class="modal-icon">🚀</span>
            <div>
              <h2>Generar Viaje</h2>
              <p>Completa los datos para publicar un nuevo envío.</p>
            </div>
          </div>
          <button @click="$emit('close')" class="close-btn">&times;</button>
        </div>

        <!-- Body -->
        <form @submit.prevent="saveOrder" class="modal-form">
          <div class="modal-body">

          <!-- Balance pill -->
          <div class="balance-pill" :class="{ 'insufficient': !canAffordOrder }">
            <span class="pill-label">{{ canAffordOrder ? '✅ Saldo disponible' : '⚠️ Saldo insuficiente' }}</span>
            <span class="pill-value">{{ userBalance }} viajes prepagados disponibles</span>
          </div>

          <!-- Address Row -->
          <div class="form-row">
            <div class="form-group">
              <label>📍 Dirección de Recogida</label>
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
              <label>🏁 Dirección de Entrega</label>
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

          <!-- Receiver Info Row -->
          <div class="form-row">
            <div class="form-group">
              <label>👤 Nombre de quien recibe</label>
              <div class="input-wrapper">
                <input
                  v-model="form.receiver_name"
                  type="text"
                  placeholder="Ej. Juan Pérez"
                  required
                />
              </div>
            </div>
            <div class="form-group">
              <label>📞 Teléfono de quien recibe</label>
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

          <!-- Map Preview -->
          <div class="form-group map-preview-wrapper" style="margin-top: 0.5rem; margin-bottom: 0.5rem;">
            <div id="modal-map" style="width: 100%; height: 260px; border-radius: 12px; border: 1px solid #E5E7EB; overflow: hidden;"></div>
          </div>

          <!-- Description -->
          <div class="form-group">
            <label>📝 Descripción del Paquete</label>
            <textarea
              v-model="form.description"
              placeholder="Ej. 2 cajas de pizza, frágil. Manejar con cuidado."
            ></textarea>
          </div>

          <!-- Payment type -->
          <div class="form-group">
            <label>💳 ¿Quién paga el envío?</label>
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
                <span class="pcard-sub">Paga solo envío</span>
              </label>
              <label class="payment-card" :class="{ active: form.payment_type === 'cash_full' }">
                <input type="radio" v-model="form.payment_type" value="cash_full" />
                <span class="pcard-icon">💰</span>
                <span class="pcard-title">Receptor</span>
                <span class="pcard-sub">Paga envío + producto</span>
              </label>
            </div>
          </div>

          <!-- Product amount (only for cash_full) -->
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

          <!-- Summary -->
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
                <span>Costo del Envío</span>
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
                <span>Créditos a descontar</span>
                <span :class="canAffordOrder ? 'text-green' : 'text-red'">1 Viaje</span>
              </div>
            </template>
          </div>
          </div>

          <!-- Footer -->
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

/* Header */
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

/* Body */
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

/* Input wrapper for badge */
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

/* Summary */
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

/* Payment cards */
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

/* Fade-down transition for product amount field */
.fade-down-enter-active, .fade-down-leave-active { transition: all 0.25s ease; }
.fade-down-enter-from, .fade-down-leave-to { opacity: 0; transform: translateY(-8px); }

/* Footer */
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

/* Google Autocomplete dropdown style override (global needed) */
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
