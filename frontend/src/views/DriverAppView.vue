<script setup>
import { ref, onMounted, computed, watch, onUnmounted } from 'vue'
import MapService from '../services/maps/MapService'
import api from '../api'

// --- State ---
const availableOrders = ref([])
const activeOrder = ref(null)
const isLoading = ref(false)
const mapLoading = ref(true)

// Simulation & Real Mode State
const isSimulatorMode = ref(false) // <--- MODE TOGGLE
const tripPhase = ref('to_pickup') // 'to_pickup' | 'to_dropoff'
const isSimulating = ref(false)
const isSidebarCollapsed = ref(false)
const currentPos = ref(null)
const speedKmh = ref(60) // Default 60km/h
const routePoints = ref([])
const currentIndex = ref(0)
const progress = ref(0)
let simInterval = null
let pollInterval = null
let locationWatchId = null
let lastSyncTime = 0

// --- Computed ---
const canStart = computed(() => activeOrder.value && routePoints.value.length > 0 && !isSimulating.value)
const canPause = computed(() => isSimulating.value)

// --- Methods ---

const loadAvailableOrders = async () => {
    isLoading.value = true
    try {
        const res = await api.get('/driver/trips/available')
        availableOrders.value = res.data.data
    } catch (e) {
        console.error('Error loading available orders:', e)
    } finally {
        isLoading.value = false
    }
}

const acceptOrder = async (order) => {
    if (activeOrder.value) return
    try {
        const res = await api.post(`/driver/trips/${order.id}/accept`)
        if (res.data.status) {
            activeOrder.value = res.data.data
            progress.value = 0
            loadRoute()
        }
    } catch (e) {
        alert(e.response?.data?.message || 'Error al aceptar el viaje')
    }
}

const updateStatus = async (status) => {
    if (!activeOrder.value) return
    try {
        const res = await api.post(`/driver/trips/${activeOrder.value.id}/status`, { status })
        if (res.data.status) {
            activeOrder.value.status = status
            if (status === 'en_camino') {
                progress.value = 0
                currentIndex.value = 0
                loadRoute() // Load Leg 2
            } else if (status === 'entregado') {
                stopSimulation()
                activeOrder.value = null
                routePoints.value = []
                currentIndex.value = 0
                progress.value = 0
                tripPhase.value = 'to_pickup'
                loadAvailableOrders()
                MapService.clearRoutes()
                MapService.clearMarkers()
            }
        }
    } catch (e) {
        console.error('Error updating status:', e)
    }
}

// --- Map & Route ---

const loadRoute = async () => {
    if (!activeOrder.value) return

    let origin, destination;
    
    // Origin fallback if driver GPS isn't set
    const FALLBACK_LAT = 20.5222;
    const FALLBACK_LNG = -100.8122;

    if (activeOrder.value.status === 'tomado') {
        tripPhase.value = 'to_pickup';
        origin = currentPos.value || { lat: FALLBACK_LAT, lng: FALLBACK_LNG };
        destination = { lat: parseFloat(activeOrder.value.pickup_lat), lng: parseFloat(activeOrder.value.pickup_lng) };
    } else {
        tripPhase.value = 'to_dropoff';
        origin = { lat: parseFloat(activeOrder.value.pickup_lat), lng: parseFloat(activeOrder.value.pickup_lng) };
        destination = { lat: parseFloat(activeOrder.value.drop_lat), lng: parseFloat(activeOrder.value.drop_lng) };
    }

    const directionsService = new google.maps.DirectionsService()
    
    directionsService.route({
        origin: origin,
        destination: destination,
        travelMode: google.maps.TravelMode.DRIVING
    }, (result, status) => {
        if (status === 'OK') {
            const path = result.routes[0].overview_path
            routePoints.value = path.map(p => ({ lat: p.lat(), lng: p.lng() }))
        } else {
            console.warn('Google Directions failed, using straight line fallback.');
            routePoints.value = [origin, destination];
        }

        if (isSimulatorMode.value) {
            currentIndex.value = 0
            currentPos.value = routePoints.value[0]
        }

        // Draw the route
        MapService.drawRoute('app-route', routePoints.value, { fitBounds: false })
        
        // Draw static targets
        const pickupCoords = { lat: parseFloat(activeOrder.value.pickup_lat), lng: parseFloat(activeOrder.value.pickup_lng) };
        const dropoffCoords = { lat: parseFloat(activeOrder.value.drop_lat), lng: parseFloat(activeOrder.value.drop_lng) };
        
        // Important Map Bug Fix: Ensure drop_lat and drop_lng exist and are valid numbers before placing marker
        if (!isNaN(pickupCoords.lat) && !isNaN(pickupCoords.lng)) {
            MapService.updateMarker('app-pickup', pickupCoords, { icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png', popup: `<b>Origen:</b><br>${activeOrder.value.pickup_address}` });
        }
        
        if (!isNaN(dropoffCoords.lat) && !isNaN(dropoffCoords.lng)) {
            console.log("Setting Dropoff Marker at", dropoffCoords);
            // Delay slightly to ensure directionsRenderer doesn't overwrite it contextually
            setTimeout(() => {
                MapService.updateMarker('app-dropoff', dropoffCoords, { icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png', popup: `<b>Destino:</b><br>${activeOrder.value.drop_address}` });
            }, 100);
        }

        if (currentPos.value) {
            MapService.updateMarker('app-driver', currentPos.value, { icon: '🏍️' })
            MapService.fitToPoints([currentPos.value, pickupCoords, dropoffCoords].filter(p => !isNaN(p.lat)))
        }

        syncLocation()

        if (isSimulatorMode.value) {
            startSimulation()
        }
    })
}

// --- Real GPS Tracking ---

const startRealTracking = () => {
    if (!('geolocation' in navigator)) {
        console.warn('Geolocation is not supported by this browser.')
        return
    }

    locationWatchId = navigator.geolocation.watchPosition(
        (position) => {
            const newPos = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };
            currentPos.value = newPos;
            
            // Render driver position
            MapService.updateMarker('app-driver', currentPos.value, { icon: '🏍️' });
            
            // Sync to backend periodically or on every change
            const now = Date.now();
            if (now - lastSyncTime > 2000) { // Throttle sync to every 2 seconds
                syncLocation();
                lastSyncTime = now;
            }
        },
        (error) => {
            console.error('Error watching location:', error);
        },
        {
            enableHighAccuracy: true,
            maximumAge: 0,
            timeout: 5000
        }
    );
};

// --- Simulator Tracking ---

const startSimulation = () => {
    if (!isSimulatorMode.value || isSimulating.value) return
    isSimulating.value = true
    
    // Smooth interpolation logic
    const moveStep = () => {
        if (currentIndex.value >= routePoints.value.length - 1) {
            stopSimulation()
            // Auto transition to completed or show UI
            return
        }

        currentIndex.value++
        currentPos.value = routePoints.value[currentIndex.value]
        
        // Update Marker
        MapService.updateMarker('app-driver', currentPos.value, { icon: '🏍️' })
        
        // Polling sync to backend (throttle to avoid flooding)
        const now = Date.now()
        if (now - lastSyncTime > 1500) { // Every 1.5s
            syncLocation()
            lastSyncTime = now
        }
        
        progress.value = Math.round((currentIndex.value / (routePoints.value.length - 1)) * 100)
    }

    const intervalMs = Math.max(100, 1000 - (speedKmh.value * 8))
    simInterval = setInterval(moveStep, intervalMs)
}

const pauseSimulation = () => {
    isSimulating.value = false
    if (simInterval) clearInterval(simInterval)
}

const stopSimulation = () => {
    pauseSimulation()
}

const syncLocation = async () => {
    if (!currentPos.value) return
    try {
        await api.post('/driver/location', {
            lat: currentPos.value.lat,
            lng: currentPos.value.lng
        })
    } catch (e) {
        console.warn('Sync failed:', e)
    }
}

// --- Init ---
onMounted(async () => {
    loadAvailableOrders()
    
    // Background polling for new trips (every 10s)
    pollInterval = setInterval(() => {
        if (!activeOrder.value) {
            loadAvailableOrders()
        }
    }, 10000)
    
    // Init Map
    await MapService.ensureSDKLoaded()
    MapService.initialize('app-map-root', {
        center: [20.5222, -100.8122],
        zoom: 13
    }).then(() => {
        mapLoading.value = false
        // Start watching real position immediately if not simulating
        if (!isSimulatorMode.value) {
            startRealTracking()
        }
    }).catch(() => {
        mapLoading.value = false
    })

    setTimeout(() => { mapLoading.value = false }, 5000)
})

onUnmounted(() => {
    if (simInterval) clearInterval(simInterval)
    if (pollInterval) clearInterval(pollInterval)
    if (locationWatchId) navigator.geolocation.clearWatch(locationWatchId)
})

const toggleSimulatorMode = () => {
    isSimulatorMode.value = !isSimulatorMode.value;
    if (isSimulatorMode.value) {
        if (locationWatchId) {
            navigator.geolocation.clearWatch(locationWatchId);
            locationWatchId = null;
        }
        if (activeOrder.value) startSimulation();
    } else {
        stopSimulation();
        startRealTracking();
    }
}
</script>

<template>
  <div class="mobile-app-theme">
    <!-- Main App Container (Simulating a Phone / PWA Standalone container) -->
    <div class="app-frame">
      
      <!-- Top Bar -->
      <header class="app-header">
        <button class="back-btn">←</button>
        <h1>Modo Conductor</h1>
        <div class="notif-bell" @click="toggleSimulatorMode" title="Toggle Simulator Mode">
          <span v-if="isSimulatorMode">🎮</span>
          <span v-else>📍</span>
        </div>
      </header>

      <!-- Content Area -->
      <main class="app-content">
        
        <div class="content-header">
           <h2>Viajes Disponibles</h2>
           <button class="see-all">Ver Todos</button>
        </div>

        <!-- Tab 1: Available Trips -->
        <div v-if="!activeOrder" class="trip-scroller">
          <div v-if="availableOrders.length > 0">
            <div class="trip-card-mobile" v-for="order in availableOrders" :key="order.id">
              <div class="card-body">
                <div class="trip-header">
                  <div class="trip-icon">🚚</div>
                  <div class="trip-meta">
                    <h3>ID-{{ order.id.toString().padStart(5, '0') }}</h3>
                    <span class="fare">Tarifa - ${{ order.cost }}</span>
                  </div>
                  <button class="btn-tracking">Rastreo</button>
                </div>

                <div class="route-info">
                  <div class="loc">
                    <span class="city">{{ order.pickup_address.split(',')[0] }}</span>
                  </div>
                  <div class="dist-pill">Nuevo</div>
                  <div class="loc">
                    <span class="city">{{ order.drop_address.split(',')[0] }}</span>
                  </div>
                </div>

                <div class="card-actions">
                  <button class="btn-reject">Rechazar</button>
                  <button @click="acceptOrder(order)" class="btn-accept-mobile">Aceptar Viaje</button>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Empty State -->
          <div class="empty-mobile" v-else>
            <div class="radar-static">📦</div>
            <p>No hay viajes disponibles hoy</p>
            <button @click="loadAvailableOrders" class="btn-refresh-mobile">Buscar Nuevamente</button>
          </div>
        </div>

        <!-- Tab 2: Workflow (If trip accepted) -->
        <div v-else class="active-trip-view">
           <div class="active-header">
              <div class="status-badge" :class="activeOrder.status">
                 {{ activeOrder.status === 'tomado' ? 'ACEPTADO' : (activeOrder.status === 'en_camino' ? 'EN CAMINO' : activeOrder.status.toUpperCase()) }}
              </div>
              <h3>Viaje en Progreso</h3>
           </div>

           <div class="active-card">
              <div class="detail-row">
                 <span class="lbl">Recolección:</span>
                 <span class="val">{{ activeOrder.pickup_address }}</span>
              </div>
              <div class="detail-row">
                 <span class="lbl">Entrega:</span>
                 <span class="val">{{ activeOrder.drop_address }}</span>
              </div>
              
              <div class="progress-section" v-if="isSimulatorMode">
                 <div class="bar"><div class="fill" :style="{ width: progress + '%' }"></div></div>
                 <span class="pct">{{ progress }}% completado - Simulación</span>
              </div>
           </div>

           <div class="control-grid">
              
              <!-- Real mode or Simulator mode UI for statuses -->
              <template v-if="activeOrder.status === 'tomado'">
                 <button 
                  v-if="!isSimulatorMode || progress >= 100"
                  @click="updateStatus('en_camino')" 
                  class="btn-action-mobile pickup"
                 >
                  Llegué al punto de recogida / Iniciar viaje
                 </button>
              </template>

              <template v-if="activeOrder.status === 'en_camino'">
                 <button 
                  v-if="!isSimulatorMode || progress >= 100"
                  @click="updateStatus('entregado')" 
                  class="btn-action-mobile deliver"
                 >
                  Completar Entrega
                 </button>
              </template>

              <!-- Simulator Controls -->
              <div v-if="isSimulatorMode" class="speed-box">
                <label>Velocidad de Simulación: {{ speedKmh }} km/h</label>
                <input type="range" min="30" max="120" v-model="speedKmh" />
              </div>
           </div>
        </div>

      </main>

      <!-- Footer Menu -->
      <footer class="app-nav">
         <div class="nav-item active">🏠</div>
         <div class="nav-item">📈</div>
         <div class="nav-item">👤</div>
      </footer>

    </div>

    <!-- Background Map (Hidden but functional for tracking) -->
    <div id="app-map-root" style="width:0; height:0; visibility:hidden; position: absolute;"></div>
    
    <!-- Initializing Overlay (Mobile style) -->
    <div v-if="mapLoading" class="overlay-mobile">
       <div class="spinner"></div>
       <p>Conectando con la Flota...</p>
    </div>
  </div>
</template>

<style scoped>
.mobile-app-theme {
  display: flex;
  justify-content: center;
  align-items: center;
  /* Occupy full space for PWA feeling */
  min-height: 100dvh;
  background: #121212;
  font-family: 'Outfit', sans-serif;
  overflow-x: hidden;
}

.app-frame {
  width: 100%;
  max-width: 100%;
  height: 100vh;
  background: #1a1a1a;
  display: flex;
  flex-direction: column;
  position: relative;
}

@media (min-width: 600px) {
    .app-frame {
        max-width: 420px;
        height: 90vh;
        border-radius: 40px;
        overflow: hidden;
        box-shadow: 0 50px 100px rgba(0,0,0,0.5);
        border: 8px solid #222;
    }
}

/* App Header */
.app-header {
  padding: 2.5rem 1.5rem 1rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #1a1a1a;
}
.app-header h1 { font-size: 1.2rem; color: #fff; font-weight: 600; margin: 0; flex: 1; text-align: center; }
.back-btn { background: #2a2a2a; border: none; color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.notif-bell { background: #2a2a2a; color: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; cursor: pointer; }

/* App Content */
.app-content { flex: 1; overflow-y: auto; padding: 1.5rem; background: #1a1a1a; }
.content-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
.content-header h2 { font-size: 1.35rem; color: #fff; margin: 0; }
.see-all { color: #888; background: none; border: none; font-size: 0.9rem; }

/* Trip Cards */
.trip-card-mobile {
  background: #242424;
  border-radius: 24px;
  padding: 1.5rem;
  margin-bottom: 1.2rem;
  transition: transform 0.2s;
}
.trip-card-mobile:first-child { background: #FFB800; }
.trip-card-mobile:first-child h3, .trip-card-mobile:first-child .fare, .trip-card-mobile:first-child .city { color: #000; }
.trip-card-mobile:first-child .trip-icon { background: #000; color: #fff; }
.trip-card-mobile:first-child .btn-tracking { background: #d99c00; color: #fff; }
.trip-card-mobile:first-child .btn-accept-mobile { background: #000; color: #fff; }

.trip-header { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.25rem; }
.trip-icon { width: 50px; height: 50px; background: #333; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
.trip-meta { flex: 1; }
.trip-meta h3 { margin: 0; font-size: 1.1rem; color: #fff; }
.fare { font-size: 0.9rem; color: #FFB800; font-weight: 600; }
.btn-tracking { padding: 0.5rem 1rem; border-radius: 20px; border: none; background: #333; color: #fff; font-size: 0.8rem; }

.route-info { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem; }
.city { font-size: 0.95rem; color: #fff; font-weight: 500; }
.dist-pill { background: rgba(0,0,0,0.1); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; color: #777; border: 1px solid rgba(255,255,255,0.1); }

.card-actions { display: flex; gap: 1rem; }
.btn-reject { flex: 1; padding: 1rem; border-radius: 18px; border: none; background: rgba(255,255,255,0.05); color: #888; font-weight: 600; font-size: 1rem; }
.btn-accept-mobile { flex: 1; padding: 1rem; border-radius: 18px; border: none; background: #FFB800; color: #000; font-weight: 700; cursor: pointer; font-size: 1rem; }

/* Active Trip View */
.active-trip-view { animation: slideIn 0.3s ease; }
@keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

.active-header { text-align: center; margin-bottom: 2rem; }
.status-badge { display: inline-block; padding: 0.4rem 1rem; border-radius: 20px; font-size: 0.8rem; font-weight: 800; margin-bottom: 0.5rem; }
.status-badge.tomado { background: #FFB800; color: #000; }
.status-badge.en_camino { background: #10b981; color: #fff; }

.active-card { background: #242424; border-radius: 24px; padding: 1.5rem; margin-bottom: 2.5rem; }
.detail-row { margin-bottom: 1.2rem; }
.detail-row .lbl { display: block; font-size: 0.8rem; color: #888; text-transform: uppercase; margin-bottom: 0.4rem; }
.detail-row .val { color: #fff; font-size: 1rem; line-height: 1.5; font-weight: 500;}

.progress-section { margin-top: 2rem; }
.bar { height: 10px; background: #333; border-radius: 5px; overflow: hidden; margin-bottom: 0.8rem; }
.fill { height: 100%; background: #FFB800; transition: width 0.3s; }
.pct { font-size: 0.85rem; color: #FFB800; font-weight: 600; }

.control-grid { display: flex; flex-direction: column; gap: 1.25rem; }
.btn-action-mobile { padding: 1.5rem; border-radius: 22px; border: none; font-weight: 800; font-size: 1.15rem; cursor: pointer; transition: transform 0.1s; }
.btn-action-mobile:active { transform: scale(0.98); }
.btn-action-mobile.pickup { background: #FFB800; color: #000; box-shadow: 0 8px 20px rgba(255, 184, 0, 0.3); }
.btn-action-mobile.deliver { background: #10b981; color: #fff; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3); }

.speed-box { padding: 1.2rem; background: #000; border-radius: 22px; }
.speed-box label { font-size: 0.85rem; color: #888; display: block; margin-bottom: 0.8rem; }
.speed-box input { width: 100%; accent-color: #FFB800; }

/* Footer Nav */
.app-nav { height: 80px; background: #1a1a1a; border-top: 1px solid #333; display: flex; align-items: center; justify-content: space-around; padding-bottom: env(safe-area-inset-bottom, 1rem); }
.nav-item { font-size: 1.6rem; color: #555; }
.nav-item.active { color: #FFB800; }

.radar-static { font-size: 4rem; margin-bottom: 1.5rem; opacity: 0.4; }
.btn-refresh-mobile { background: #FFB800; color: #000; border: none; padding: 1rem 2rem; border-radius: 22px; font-weight: 700; cursor: pointer; margin-top: 1.5rem; font-size: 1rem;}
.empty-mobile { text-align: center; padding: 5rem 1rem; color: #666; font-size: 1.1rem; }

/* Initialization */
.overlay-mobile { position: absolute; inset: 0; background: #1a1a1a; z-index: 100; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.spinner { width: 50px; height: 50px; border: 4px solid #333; border-top-color: #FFB800; border-radius: 50%; animation: orbit 1s linear infinite; margin-bottom: 1.5rem; }

@keyframes orbit { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
</style>
