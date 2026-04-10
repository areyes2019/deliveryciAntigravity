<script setup>
import { ref, onMounted, computed, watch, onUnmounted } from 'vue'
import MapService from '../services/maps/MapService'
import api from '../api'

// --- State ---
const availableOrders = ref([])
const activeOrder = ref(null)
const isLoading = ref(false)
const mapLoading = ref(true)
const showNavModal = ref(false)
const navPreference = ref(localStorage.getItem('nav_preference') || null)
const tripCompletedMessage = ref('')

// --- Navigation Helper ---
const openNavigation = (provider = null) => {
    if (!activeOrder.value) return;
    
    // If provider is explicitly passed, save it as preference
    if (provider) {
        navPreference.value = provider;
        localStorage.setItem('nav_preference', provider);
        showNavModal.value = false;
    }

    // Use saved preference if available, otherwise show modal
    const selectedProvider = navPreference.value;
    
    if (!selectedProvider) {
        showNavModal.value = true;
        return;
    }

    // If driver chooses to navigate on their own, don't open external navigation
    if (selectedProvider === 'self-navigate') {
        return;
    }

    // Determine target coordinates based on current status
    // "tomado" or "arribado" means we need to go to PICKUP
    // "en_camino" means we need to go to DROP-OFF
    const isToPickup = activeOrder.value.status === 'tomado' || activeOrder.value.status === 'arribado';
    const lat = isToPickup ? activeOrder.value.pickup_lat : activeOrder.value.drop_lat;
    const lng = isToPickup ? activeOrder.value.pickup_lng : activeOrder.value.drop_lng;

    let url = '';
    if (selectedProvider === 'google') {
        url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
    } else if (selectedProvider === 'waze') {
        url = `https://waze.com/ul?ll=${lat},${lng}&navigate=yes`;
    }

    if (url) {
        window.open(url, '_blank');
    }
}

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
const isAvailable = ref(true) // Availability/Connection status
const showMenu = ref(false) // Settings menu toggle
let simInterval = null
let pollInterval = null
let locationWatchId = null
let locationSyncInterval = null
let simSyncInterval = null
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

const syncCurrentTrip = async () => {
    // Try to load from localStorage first for immediate UI
    const cached = localStorage.getItem('active_trip')
    if (cached && !activeOrder.value) {
        activeOrder.value = JSON.parse(cached)
        loadRoute()
    }

    try {
        const res = await api.get('/driver/trips/current')
        if (res.data.status && res.data.data) {
            activeOrder.value = res.data.data
            localStorage.setItem('active_trip', JSON.stringify(activeOrder.value))
            loadRoute()
        } else {
            // No active trip found in backend, clear local state
            activeOrder.value = null
            localStorage.removeItem('active_trip')
        }
    } catch (e) {
        console.error('Error syncing current trip:', e)
    }
}

const acceptOrder = async (order) => {
    if (activeOrder.value) return
    try {
        const res = await api.post(`/driver/trips/${order.id}/accept`)
        if (res.data.status) {
            activeOrder.value = res.data.data
            localStorage.setItem('active_trip', JSON.stringify(activeOrder.value))
            progress.value = 0
            loadRoute()
            showNavModal.value = true
            
            // Ensure tracking is active after accepting order
            if (!isSimulatorMode.value && !locationSyncInterval) {
                startRealTracking()
            }
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
            localStorage.setItem('active_trip', JSON.stringify(activeOrder.value))
            
            // Force immediate location sync when status changes
            syncLocation()
            
            // Ensure tracking is active after status change
            if (!isSimulatorMode.value && !locationSyncInterval) {
                startRealTracking()
            }
            
            if (status === 'en_camino') {
                progress.value = 0
                currentIndex.value = 0
                loadRoute() // Load Leg 2
                // Trigger auto-navigation to destination
                openNavigation();
            } else if (status === 'entregado') {
                stopSimulation()
                activeOrder.value = null
                localStorage.removeItem('active_trip');
                
                // Clear any navigation-related session state if needed
                // But keep navPreference for future trips
                
                routePoints.value = []
                currentIndex.value = 0
                progress.value = 0
                tripPhase.value = 'to_pickup'
                loadAvailableOrders()
                MapService.clearRoutes()
                MapService.clearMarkers()

                // Show completion message
                tripCompletedMessage.value = "Viaje completado. Ya puedes cerrar tu aplicación de navegación."
                setTimeout(() => { tripCompletedMessage.value = '' }, 5000)
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

    if (activeOrder.value.status === 'tomado' || activeOrder.value.status === 'arribado') {
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

    // Watch position for real-time location updates (visual rendering)
    locationWatchId = navigator.geolocation.watchPosition(
        (position) => {
            const newPos = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };
            currentPos.value = newPos;
            
            // Render driver position immediately
            MapService.updateMarker('app-driver', currentPos.value, { icon: '🏍️' });
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

    // Sync location to backend every 3 seconds (as requested)
    // This is independent of position changes, ensuring consistent updates
    locationSyncInterval = setInterval(() => {
        syncLocation();
    }, 3000);
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
        
        progress.value = Math.round((currentIndex.value / (routePoints.value.length - 1)) * 100)
    }

    const intervalMs = Math.max(100, 1000 - (speedKmh.value * 8))
    simInterval = setInterval(moveStep, intervalMs)

    // Sync location to backend every 3 seconds (consistent with real tracking)
    simSyncInterval = setInterval(() => {
        syncLocation();
    }, 3000);
}

const pauseSimulation = () => {
    isSimulating.value = false
    if (simInterval) clearInterval(simInterval)
}

const stopSimulation = () => {
    pauseSimulation()
    if (simSyncInterval) {
        clearInterval(simSyncInterval);
        simSyncInterval = null;
    }
}

const stopRealTracking = () => {
    if (locationWatchId) {
        navigator.geolocation.clearWatch(locationWatchId);
        locationWatchId = null;
    }
    if (locationSyncInterval) {
        clearInterval(locationSyncInterval);
        locationSyncInterval = null;
    }
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

const toggleAvailability = async () => {
    const confirmToggle = confirm(
        isAvailable.value 
            ? '¿Desconectarte de la plataforma? No recibirás nuevos viajes.'
            : '¿Conectarte a la plataforma? Comenzarás a recibir nuevos viajes.'
    )
    
    if (!confirmToggle) return
    
    try {
        const response = await api.post('/driver/toggle-availability')
        if (response.data.status) {
            isAvailable.value = !isAvailable.value
            // Save availability status to localStorage
            localStorage.setItem('driver_available', JSON.stringify(isAvailable.value))
            const message = isAvailable.value 
                ? '📍 ¡Conectado! Ya recibes viajes.'
                : '🔴 Desconectado. No recibirás nuevos viajes.'
            alert(message)
        }
    } catch (error) {
        alert(error.response?.data?.message || 'Error al cambiar disponibilidad')
        console.error('Error toggling availability:', error)
    }
}

// --- Visibility Change Handler ---
const handleVisibilityChange = () => {
    // When app comes back from background (Waze/Maps navigation)
    if (!document.hidden && activeOrder.value && !isSimulatorMode.value) {
        // Ensure tracking is active
        if (!locationSyncInterval) {
            console.log('App returned from background, restarting tracking...')
            startRealTracking()
        }
        // Force immediate sync
        syncLocation()
    }
}

// --- Init ---
onMounted(async () => {
    // Load driver availability status from localStorage
    const savedAvailability = localStorage.getItem('driver_available')
    if (savedAvailability !== null) {
        isAvailable.value = JSON.parse(savedAvailability)
    }
    
    await syncCurrentTrip()
    loadAvailableOrders()
    
    // Background polling for new trips (every 10s)
    pollInterval = setInterval(() => {
        if (!activeOrder.value) {
            loadAvailableOrders()
        }
    }, 10000)

    window.addEventListener('online', syncCurrentTrip)
    document.addEventListener('visibilitychange', handleVisibilityChange)
    
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
    if (locationSyncInterval) clearInterval(locationSyncInterval)
    if (simSyncInterval) clearInterval(simSyncInterval)
    if (locationWatchId) navigator.geolocation.clearWatch(locationWatchId)
    window.removeEventListener('online', syncCurrentTrip)
    document.removeEventListener('visibilitychange', handleVisibilityChange)
})

const toggleSimulatorMode = () => {
    isSimulatorMode.value = !isSimulatorMode.value;
    if (isSimulatorMode.value) {
        stopRealTracking();
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
        <div class="header-title-section">
          <h1>Modo Conductor</h1>
          <div class="online-indicator" :class="{ 'online': isAvailable, 'offline': !isAvailable }">
            <span class="status-dot"></span>
            <span class="status-text">{{ isAvailable ? 'On line' : 'Off line' }}</span>
          </div>
        </div>
        <div class="settings-menu-container">
          <button class="settings-btn" @click="showMenu = !showMenu" title="Ajustes">
            ⚙️
          </button>
          <div v-if="showMenu" class="settings-dropdown">
            <button class="menu-item" @click="toggleSimulatorMode(); showMenu = false">
              <span v-if="isSimulatorMode">📍 Cambiar a VIVO</span>
              <span v-else>🎮 Probar SIMULADOR</span>
            </button>
            <button 
              class="menu-item" 
              :class="{ 'available': isAvailable, 'unavailable': !isAvailable }"
              @click="toggleAvailability(); showMenu = false"
            >
              <span v-if="isAvailable">🟢 Desconectarse</span>
              <span v-else>🔴 Conectarse</span>
            </button>
          </div>
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

            <!-- Simulator Info -->
            <div v-if="isSimulatorMode" class="simulator-info-box">
              <h3>🎮 Modo Simulador Activo</h3>
              <p>Puedes aceptar un viaje de la lista anterior y probarlo sin salir de tu oficina.</p>
              <p>El rastreo funcionará igual que en modo EN VIVO (cada 3 segundos).</p>
              <button class="btn-switch-live" @click="toggleSimulatorMode">Cambiar a Modo EN VIVO</button>
            </div>
          </div>
        </div>

        <!-- Tab 2: Workflow (If trip accepted) -->
        <div v-else class="active-trip-view">
           <div class="active-header">
               <div class="status-badge" :class="activeOrder.status">
                  {{ 
                    activeOrder.status === 'tomado' ? 'ACEPTADO' : 
                    activeOrder.status === 'arribado' ? 'ESPERANDO' : 
                    activeOrder.status === 'en_camino' ? 'EN CAMINO' : 
                    activeOrder.status.toUpperCase() 
                  }}
               </div>
               <h3>Viaje en Progreso</h3>
               <div class="nav-btn-group">
                  <button @click="openNavigation()" class="btn-mini-nav">🧭 {{ navPreference ? 'Continuar' : 'Navegar' }}</button>
                  <button v-if="navPreference" @click="showNavModal = true" class="btn-mini-nav settings">⚙️</button>
               </div>
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
                  @click="updateStatus('arribado')" 
                  class="btn-action-mobile arrived"
                 >
                  Llegué al punto de recogida
                 </button>
              </template>

              <template v-if="activeOrder.status === 'arribado'">
                 <button 
                  v-if="!isSimulatorMode || progress >= 100"
                  @click="updateStatus('en_camino')" 
                  class="btn-action-mobile pickup"
                 >
                  Iniciar viaje (Hacia el destino)
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
              <div v-if="isSimulatorMode && activeOrder" class="simulator-control-panel">
                <div class="sim-header">
                  <span class="sim-title">🎮 CONTROL DE SIMULADOR</span>
                  <span class="sim-status" :class="{ 'active': isSimulating }">
                    {{ isSimulating ? '▶️ ACTIVO' : '⏸️ PAUSADO' }}
                  </span>
                </div>

                <div class="sim-progress">
                  <div class="progress-bar">
                    <div class="progress-fill" :style="{ width: progress + '%' }"></div>
                  </div>
                  <span class="progress-text">{{ progress }}% recorrido</span>
                </div>

                <div class="sim-speed-control">
                  <label>Velocidad Simulada</label>
                  <div class="speed-display">
                    <span class="speed-value">{{ speedKmh }} km/h</span>
                  </div>
                  <input 
                    type="range" 
                    min="10" 
                    max="120" 
                    v-model="speedKmh" 
                    class="speed-slider"
                  />
                  <div class="speed-hints">
                    <span class="hint">🚶 Lento (10 km/h)</span>
                    <span class="hint">🚗 Normal (60 km/h)</span>
                    <span class="hint">🏎️ Rápido (120 km/h)</span>
                  </div>
                </div>

                <div class="sim-info">
                  <p>💡 Usa el simulador para probar sin salir a la calle</p>
                  <p>📍 El rastreo funciona igual que en modo EN VIVO (cada 3 segundos)</p>
                </div>
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

    <!-- Navigation Selection Modal -->
    <div v-if="showNavModal" class="nav-modal-overlay" @click.self="showNavModal = false">
       <div class="nav-modal-content">
          <h3>Iniciar Navegación</h3>
          <p>Selecciona cómo deseas viajar:</p>
          
          <div class="nav-options">
             <button @click="openNavigation('google')" class="nav-opt-btn google">
                <span class="icon">🗺️</span>
                Google Maps
             </button>
             <button @click="openNavigation('waze')" class="nav-opt-btn waze">
                <span class="icon">🚙</span>
                Waze
             </button>
             <button @click="openNavigation('self-navigate')" class="nav-opt-btn self-nav">
                <span class="icon">🛣️</span>
                Viajar por mi cuenta
             </button>
          </div>

          <p class="tracking-notice">⚠️ Serás rastreado en todo momento para tu seguridad</p>
          
          <button @click="showNavModal = false" class="btn-close-modal">Cancelar</button>
       </div>
    </div>

    <!-- Background Map (Hidden but functional for tracking) -->
    <div id="app-map-root" style="width:0; height:0; visibility:hidden; position: absolute;"></div>
    
    <!-- Completion Message Toast -->
    <div v-if="tripCompletedMessage" class="completion-toast">{{ tripCompletedMessage }}</div>

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
  padding: 1rem 1.5rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: linear-gradient(135deg, #1a1a1a 0%, #242424 100%);
  gap: 1rem;
}
.app-header h1 { font-size: 1rem; color: #fff; font-weight: 600; margin: 0; }

.header-title-section {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
}

.online-indicator {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 0.25rem 0.6rem;
  border-radius: 12px;
  width: fit-content;
}

.online-indicator.online {
  background: rgba(16, 185, 129, 0.15);
  color: #10B981;
}

.online-indicator.offline {
  background: rgba(239, 68, 68, 0.15);
  color: #EF4444;
}

.status-dot {
  display: inline-block;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  animation: pulse-status 2s ease-in-out infinite;
}

.online-indicator.online .status-dot {
  background: #10B981;
}

.online-indicator.offline .status-dot {
  background: #EF4444;
}

@keyframes pulse-status {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.back-btn { background: #2a2a2a; border: none; color: white; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: background 0.2s; }
.back-btn:hover { background: #333; }

.settings-menu-container {
  position: relative;
}

.settings-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  padding: 0.25rem;
  transition: transform 0.2s ease;
}

.settings-btn:hover {
  transform: rotate(90deg);
}

.settings-btn:active {
  transform: rotate(90deg) scale(0.9);
}

.settings-dropdown {
  position: absolute;
  top: 100%;
  right: 0;
  margin-top: 0.5rem;
  background: #2a2a2a;
  border: 1px solid #444;
  border-radius: 12px;
  min-width: 200px;
  z-index: 1000;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  overflow: hidden;
}

.menu-item {
  display: block;
  width: 100%;
  padding: 0.75rem 1rem;
  background: none;
  border: none;
  color: white;
  text-align: left;
  cursor: pointer;
  font-size: 0.9rem;
  transition: background 0.2s ease;
  border-bottom: 1px solid #444;
}

.menu-item:last-child {
  border-bottom: none;
}

.menu-item:hover {
  background: #333;
}

.menu-item.available {
  color: #10B981;
}

.menu-item.unavailable {
  color: #EF4444;
}

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
.status-badge.arribado { background: #3b82f6; color: #fff; }
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
.btn-action-mobile.arrived { background: #3b82f6; color: #fff; box-shadow: 0 8px 20px rgba(59, 130, 246, 0.3); }
.btn-action-mobile.pickup { background: #FFB800; color: #000; box-shadow: 0 8px 20px rgba(255, 184, 0, 0.3); }
.btn-action-mobile.deliver { background: #10b981; color: #fff; box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3); }

.speed-box { padding: 1.2rem; background: #000; border-radius: 22px; }
.speed-box label { font-size: 0.85rem; color: #888; display: block; margin-bottom: 0.8rem; }
.speed-box input { width: 100%; accent-color: #FFB800; }

/* Simulator Control Panel */
.simulator-control-panel {
  background: linear-gradient(135deg, #2d2d2d 0%, #1a1a1a 100%);
  border: 2px solid #8B5CF6;
  border-radius: 20px;
  padding: 1.5rem;
  margin-top: 1.5rem;
  box-shadow: 0 8px 24px rgba(139, 92, 246, 0.2);
}

.sim-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.2rem;
  padding-bottom: 1rem;
  border-bottom: 2px solid #8B5CF6;
}

.sim-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #8B5CF6;
}

.sim-status {
  font-size: 0.85rem;
  font-weight: 600;
  padding: 0.4rem 0.8rem;
  background: rgba(139, 92, 246, 0.2);
  border-radius: 12px;
  color: #A78BFA;
}

.sim-status.active {
  background: rgba(16, 185, 129, 0.2);
  color: #10B981;
}

.sim-progress {
  margin-bottom: 1.5rem;
}

.progress-bar {
  width: 100%;
  height: 10px;
  background: #333;
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: 0.5rem;
  border: 1px solid #8B5CF6;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #8B5CF6 0%, #A78BFA 100%);
  transition: width 0.3s ease;
}

.progress-text {
  font-size: 0.8rem;
  color: #888;
  display: block;
}

.sim-speed-control {
  margin-bottom: 1.5rem;
}

.sim-speed-control label {
  display: block;
  font-size: 0.85rem;
  color: #aaa;
  font-weight: 600;
  margin-bottom: 0.8rem;
}

.speed-display {
  background: #1a1a1a;
  border-radius: 12px;
  padding: 0.8rem;
  margin-bottom: 0.8rem;
  text-align: center;
}

.speed-value {
  font-size: 1.4rem;
  font-weight: 700;
  color: #8B5CF6;
}

.speed-slider {
  width: 100%;
  height: 8px;
  border-radius: 8px;
  background: #333;
  outline: none;
  -webkit-appearance: none;
  appearance: none;
  margin-bottom: 0.8rem;
}

.speed-slider::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #8B5CF6;
  cursor: pointer;
  box-shadow: 0 0 8px rgba(139, 92, 246, 0.6);
}

.speed-slider::-moz-range-thumb {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #8B5CF6;
  cursor: pointer;
  border: none;
  box-shadow: 0 0 8px rgba(139, 92, 246, 0.6);
}

.speed-hints {
  display: flex;
  justify-content: space-between;
  font-size: 0.75rem;
  color: #666;
}

.hint {
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

.sim-info {
  background: rgba(139, 92, 246, 0.1);
  border-left: 3px solid #8B5CF6;
  padding: 0.8rem 1rem;
  border-radius: 8px;
  font-size: 0.8rem;
  color: #A78BFA;
  line-height: 1.5;
}

.sim-info p {
  margin: 0.4rem 0;
}

/* Simulator Info Box */
.simulator-info-box {
  background: linear-gradient(135deg, rgba(139, 92, 246, 0.15) 0%, rgba(139, 92, 246, 0.05) 100%);
  border: 2px solid #8B5CF6;
  border-radius: 16px;
  padding: 1.5rem;
  margin-top: 2rem;
  text-align: center;
}

.simulator-info-box h3 {
  color: #8B5CF6;
  margin: 0 0 0.8rem 0;
  font-size: 1.1rem;
}

.simulator-info-box p {
  color: #A78BFA;
  font-size: 0.85rem;
  margin: 0.6rem 0;
  line-height: 1.5;
}

.btn-switch-live {
  background: linear-gradient(135deg, #8B5CF6 0%, #A78BFA 100%);
  color: white;
  border: none;
  padding: 0.8rem 1.5rem;
  border-radius: 20px;
  font-weight: 600;
  cursor: pointer;
  margin-top: 1rem;
  transition: all 0.2s ease;
}

.btn-switch-live:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
}

.btn-switch-live:active {
  transform: translateY(0);
}

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

/* Navigation Modal Styles */
.btn-mini-nav { background: #333; border: 1px solid #444; color: #FFB800; padding: 0.5rem 1rem; border-radius: 12px; font-weight: 600; font-size: 0.9rem; margin-top: 0.5rem; cursor: pointer; }

.nav-modal-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); z-index: 200; display: flex; align-items: center; justify-content: center; padding: 2rem; }
.nav-modal-content { background: #242424; width: 100%; max-width: 320px; border-radius: 28px; padding: 2rem; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.4); border: 1px solid #333; }
.nav-modal-content h3 { color: #fff; margin: 0 0 0.5rem 0; font-size: 1.4rem; }
.nav-modal-content p { color: #888; margin-bottom: 2rem; font-size: 0.95rem; }

.nav-options { display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem; }
.nav-opt-btn { display: flex; align-items: center; gap: 1rem; padding: 1.2rem; border-radius: 18px; border: none; color: #fff; font-weight: 700; font-size: 1.1rem; cursor: pointer; transition: transform 0.1s; }
.nav-opt-btn:active { transform: scale(0.97); }
.nav-opt-btn.google { background: #4285F4; }
.nav-opt-btn.waze { background: #33CCFF; color: #000; }
.nav-opt-btn.self-nav { background: #9C27B0; }
.nav-opt-btn .icon { font-size: 1.4rem; }

.tracking-notice { color: #FFB800; font-size: 0.85rem; margin: 1rem 0; padding: 0.75rem 1rem; background: rgba(255, 184, 0, 0.1); border-radius: 8px; border-left: 3px solid #FFB800; }

.btn-close-modal { background: none; border: none; color: #666; font-weight: 600; cursor: pointer; padding: 0.5rem; font-size: 0.9rem; }

.nav-btn-group { display: flex; gap: 0.5rem; justify-content: center; align-items: center; }
.btn-mini-nav.settings { color: #888; border-color: #333; }

.completion-toast {
   position: fixed;
   bottom: 100px;
   left: 50%;
   transform: translateX(-50%);
   background: #10b981;
   color: #fff;
   padding: 1rem 2rem;
   border-radius: 30px;
   font-weight: 700;
   box-shadow: 0 10px 30px rgba(0,0,0,0.5);
   z-index: 1000;
   animation: slideUp 0.3s ease;
   text-align: center;
   width: 80%;
   max-width: 300px;
}
@keyframes slideUp { from { transform: translate(-50%, 20px); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
</style>
