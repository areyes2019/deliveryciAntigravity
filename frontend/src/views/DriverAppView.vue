<script setup>
import { ref, onMounted, computed, watch, onUnmounted } from 'vue'
import MapService from '../services/maps/MapService'
import api from '../api'
import { useAuthStore } from '../stores/auth'
import { subscribe, unsubscribe } from '../services/realtime'
import DriverStatusToggle from '../components/DriverStatusToggle.vue'
import ModeToggle from '../components/ModeToggle.vue'

const authStore = useAuthStore()

// --- State ---
const availableOrders = ref([])
const activeOrder = ref(null)
const isLoading = ref(false)
const isAccepting = ref(false)
const mapLoading = ref(true)
const driverClientId = ref(null)   // se puebla desde /auth/me para nombrar el canal Pusher
const driverId = ref(null)         // drivers.id — canal personal driver.{id}
const statusError = ref('')
const showRouteDetail = ref(false)

// Driver online/offline status
const isDriverOnline = ref(false)
const isTogglingStatus = ref(false)

// Today's earnings + guarantee balance + viajes disponibles
const todayEarnings     = ref(0)
const todayTrips        = ref(0)
const guaranteeBalance  = ref(null)   // null = todavía no cargado
const viajesDisponibles = ref(null)   // null = esquema porcentaje o no cargado
let earningsInterval = null

// Coin animation refs
const walletChipRef = ref(null)
const completeButtonRef = ref(null)
const walletBounce = ref(false)

const fetchTodayEarnings = async () => {
    const driverId = authStore.user?.driver?.id
    if (!driverId) return
    try {
        const res = await api.get(`/wallet/today/${driverId}`)
        if (res.data.status) {
            todayEarnings.value     = parseFloat(res.data.data.earnings)          || 0
            todayTrips.value        = parseInt(res.data.data.trips)              || 0
            guaranteeBalance.value  = parseFloat(res.data.data.guarantee_balance) || 0
            viajesDisponibles.value = res.data.data.viajes_disponibles ?? null
        }
    } catch (e) {
        console.warn('No se pudieron cargar las ganancias del día:', e)
    }
}

const toggleDriverStatus = async () => {
    isTogglingStatus.value = true
    try {
        const res = await api.post('/driver/toggle-availability')
        if (res.data.status) {
            isDriverOnline.value = res.data.data.is_active === 1
            if (isDriverOnline.value && !activeOrder.value) {
                loadAvailableOrders()
            }
        }
    } catch (e) {
        console.error('Error toggling driver status:', e)
    } finally {
        isTogglingStatus.value = false
    }
}

// Live / Simulator mode
const modeValue = computed({
    get: () => isSimulatorMode.value ? 'simulator' : 'live',
    set: (val) => { if ((val === 'simulator') !== isSimulatorMode.value) toggleSimulatorMode() }
})

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

// --- Auto-arrival at pickup ---
const arrivedAtPickup = ref(false)
const showArrivalToast = ref(false)
let arrivalToastTimer = null

// --- Cancellation toast ---
const showCancelledToast = ref(false)
let cancelledToastTimer = null

const handleOrderCancelled = ({ order_id }) => {
    if (!activeOrder.value || activeOrder.value.id !== order_id) return
    stopSimulation()
    activeOrder.value = null
    routePoints.value = []
    currentIndex.value = 0
    progress.value = 0
    tripPhase.value = 'to_pickup'
    MapService.clearRoutes()
    MapService.clearMarkers()

    showCancelledToast.value = true
    if (cancelledToastTimer) clearTimeout(cancelledToastTimer)
    cancelledToastTimer = setTimeout(() => { showCancelledToast.value = false }, 5000)

    if (navigator.vibrate) navigator.vibrate([200, 100, 200, 100, 400])
    loadAvailableOrders()
}

// Haversine distance in metres between two {lat, lng} points
const distanceMeters = (a, b) => {
    const R = 6371000
    const dLat = (b.lat - a.lat) * Math.PI / 180
    const dLng = (b.lng - a.lng) * Math.PI / 180
    const x = Math.sin(dLat / 2) ** 2
        + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180)
        * Math.sin(dLng / 2) ** 2
    return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x))
}

const handlePickupArrival = async () => {
    if (arrivedAtPickup.value) return           // fire only once
    arrivedAtPickup.value = true

    // Show toast
    showArrivalToast.value = true
    if (arrivalToastTimer) clearTimeout(arrivalToastTimer)
    arrivalToastTimer = setTimeout(() => { showArrivalToast.value = false }, 4000)

    // Auto-transition backend status
    await updateStatus('arribado')
}

// Simulator: watch progress — trigger arrival when to_pickup route finishes
watch(progress, (val) => {
    if (
        val >= 100 &&
        tripPhase.value === 'to_pickup' &&
        activeOrder.value?.status === 'tomado' &&
        !arrivedAtPickup.value
    ) {
        handlePickupArrival()
    }
})

// --- Computed ---
const canStart = computed(() => activeOrder.value && routePoints.value.length > 0 && !isSimulating.value)
const canPause = computed(() => isSimulating.value)

// null = balance todavía no cargado (no bloquear en ese estado)
const canAcceptTrips = computed(() =>
    guaranteeBalance.value === null || guaranteeBalance.value > 0
)

watch(isDriverOnline, (online) => {
    if (!online) availableOrders.value = []
})


const avatarUrl = computed(() => {
    const name = (authStore.userName || 'Driver').replace(' ', '+')
    return `https://ui-avatars.com/api/?name=${name}&background=3B82F6&color=fff&bold=true`
})

// --- Methods ---

const notifyNewOrder = () => {
    // Vibración en móvil
    if (navigator.vibrate) navigator.vibrate([300, 100, 300])

    // Sonido de alerta
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)()
        const osc = ctx.createOscillator()
        const gain = ctx.createGain()
        osc.connect(gain)
        gain.connect(ctx.destination)
        osc.frequency.setValueAtTime(880, ctx.currentTime)
        gain.gain.setValueAtTime(0.3, ctx.currentTime)
        gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6)
        osc.start(ctx.currentTime)
        osc.stop(ctx.currentTime + 0.6)
    } catch {}

    // Notificación del navegador si tiene permiso
    if (Notification.permission === 'granted') {
        new Notification('¡Nuevo envío disponible!', {
            body: 'Hay un nuevo pedido esperando conductor.',
            icon: '/icons/icon-192x192.png',
        })
    }
}

const loadAvailableOrders = async () => {
    if (!isDriverOnline.value) {
        availableOrders.value = []
        return
    }
    isLoading.value = true
    try {
        const res = await api.get('/driver/trips/available')
        const incoming = res.data.data ?? []
        const prevIds = new Set(availableOrders.value.map(o => o.id))
        const hasNew = incoming.some(o => !prevIds.has(o.id))
        if (hasNew && availableOrders.value.length > 0) notifyNewOrder()
        availableOrders.value = incoming
    } catch (e) {
        if (e.response?.status !== 403) {
            console.error('Error loading available orders:', e)
        }
        availableOrders.value = []
    } finally {
        isLoading.value = false
    }
}

const acceptOrder = async (order) => {
    if (activeOrder.value || isAccepting.value) return
    isAccepting.value = true
    // Optimistic UI: quitar el viaje de la lista inmediatamente para feedback instantáneo
    availableOrders.value = availableOrders.value.filter(o => o.id !== order.id)
    try {
        const res = await api.post(`/driver/trips/${order.id}/accept`)
        if (res.data.status) {
            activeOrder.value = res.data.data
            progress.value = 0
            arrivedAtPickup.value = false
            loadRoute()
        }
    } catch (e) {
        // Rollback optimista: devolver el viaje a la lista y refrescar desde servidor
        availableOrders.value = [order, ...availableOrders.value]
        if (e.response?.status === 409) {
            // Otro driver llegó primero — refrescar lista para quitar el viaje tomado
            await loadAvailableOrders()
            alert('Este viaje ya fue tomado por otro conductor.')
        } else {
            alert(e.response?.data?.message || 'Error al aceptar el viaje')
        }
    } finally {
        isAccepting.value = false
    }
}

const updateStatus = async (status) => {
    if (!activeOrder.value) return
    statusError.value = ''
    try {
        const res = await api.post(`/driver/trips/${activeOrder.value.id}/status`, { status })
        if (res.data.status) {
            activeOrder.value.status = status
            if (status === 'arribado') {
                // Arrived at pickup — stay on current route, stop simulation if running
                stopSimulation()
                progress.value = 0
                currentIndex.value = 0
            } else if (status === 'en_camino') {
                // Start delivery — reload route toward dropoff
                progress.value = 0
                currentIndex.value = 0
                loadRoute()
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
                fetchTodayEarnings()
            }
        }
    } catch (e) {
        statusError.value = e.response?.data?.message || 'Error al actualizar el estado del viaje'
        console.error('Error updating status:', e)
    }
}

// --- Map & Route ---

const loadRoute = async () => {
    if (!activeOrder.value) return

    let origin, destination
    const FALLBACK_LAT = 20.5222
    const FALLBACK_LNG = -100.8122

    if (activeOrder.value.status === 'tomado' || activeOrder.value.status === 'arribado') {
        tripPhase.value = 'to_pickup'
        origin = currentPos.value || { lat: FALLBACK_LAT, lng: FALLBACK_LNG }
        destination = { lat: parseFloat(activeOrder.value.pickup_lat), lng: parseFloat(activeOrder.value.pickup_lng) }
    } else {
        tripPhase.value = 'to_dropoff'
        origin = { lat: parseFloat(activeOrder.value.pickup_lat), lng: parseFloat(activeOrder.value.pickup_lng) }
        destination = { lat: parseFloat(activeOrder.value.drop_lat), lng: parseFloat(activeOrder.value.drop_lng) }
    }

    const directionsService = new google.maps.DirectionsService()
    directionsService.route({ origin, destination, travelMode: google.maps.TravelMode.DRIVING }, (result, status) => {
        if (status === 'OK') {
            const path = result.routes[0].overview_path
            routePoints.value = path.map(p => ({ lat: p.lat(), lng: p.lng() }))
        } else {
            routePoints.value = [origin, destination]
        }

        if (isSimulatorMode.value) {
            currentIndex.value = 0
            currentPos.value = routePoints.value[0]
        }

        MapService.drawRoute('app-route', routePoints.value, { fitBounds: false })

        const pickupCoords = { lat: parseFloat(activeOrder.value.pickup_lat), lng: parseFloat(activeOrder.value.pickup_lng) }
        const dropoffCoords = { lat: parseFloat(activeOrder.value.drop_lat), lng: parseFloat(activeOrder.value.drop_lng) }

        if (!isNaN(pickupCoords.lat) && !isNaN(pickupCoords.lng)) {
            MapService.updateMarker('app-pickup', pickupCoords, { icon: 'http://maps.google.com/mapfiles/ms/icons/green-dot.png', popup: `<b>Origen:</b><br>${activeOrder.value.pickup_address}` })
        }
        if (!isNaN(dropoffCoords.lat) && !isNaN(dropoffCoords.lng)) {
            setTimeout(() => {
                MapService.updateMarker('app-dropoff', dropoffCoords, { icon: 'http://maps.google.com/mapfiles/ms/icons/red-dot.png', popup: `<b>Destino:</b><br>${activeOrder.value.drop_address}` })
            }, 100)
        }
        if (currentPos.value) {
            MapService.updateMarker('app-driver', currentPos.value, { icon: { url: '/public/35859-removebg-preview.png', scaledSize: { width: 60, height: 40 }, anchor: { x: 30, y: 20 } } })
            MapService.fitToPoints([currentPos.value, pickupCoords, dropoffCoords].filter(p => !isNaN(p.lat)))
        }

        syncLocation()
        if (isSimulatorMode.value) startSimulation()
    })
}

// --- Real GPS Tracking ---

const startRealTracking = () => {
    if (!('geolocation' in navigator)) return
    locationWatchId = navigator.geolocation.watchPosition(
        (position) => {
            const newPos = { lat: position.coords.latitude, lng: position.coords.longitude }
            currentPos.value = newPos
            MapService.updateMarker('app-driver', currentPos.value, { icon: { url: '/public/35859-removebg-preview.png', scaledSize: { width: 60, height: 40 }, anchor: { x: 30, y: 20 } } })
            const now = Date.now()
            if (now - lastSyncTime > 2000) { syncLocation(); lastSyncTime = now }

            // Auto-detect arrival at pickup (within 80 m)
            if (activeOrder.value?.status === 'tomado' && !arrivedAtPickup.value) {
                const pickup = {
                    lat: parseFloat(activeOrder.value.pickup_lat),
                    lng: parseFloat(activeOrder.value.pickup_lng)
                }
                if (!isNaN(pickup.lat) && distanceMeters(newPos, pickup) < 80) {
                    handlePickupArrival()
                }
            }
        },
        (error) => { console.error('Error watching location:', error) },
        { enableHighAccuracy: true, maximumAge: 0, timeout: 5000 }
    )
}

// --- Simulator Tracking ---

const startSimulation = () => {
    if (!isSimulatorMode.value || isSimulating.value) return
    isSimulating.value = true
    const moveStep = () => {
        if (currentIndex.value >= routePoints.value.length - 1) { stopSimulation(); return }
        currentIndex.value++
        currentPos.value = routePoints.value[currentIndex.value]
        MapService.updateMarker('app-driver', currentPos.value, { icon: { url: '/public/35859-removebg-preview.png', scaledSize: { width: 60, height: 40 }, anchor: { x: 30, y: 20 } } })
        const now = Date.now()
        if (now - lastSyncTime > 1500) { syncLocation(); lastSyncTime = now }
        progress.value = Math.round((currentIndex.value / (routePoints.value.length - 1)) * 100)
    }
    const intervalMs = Math.max(100, 1000 - (speedKmh.value * 8))
    simInterval = setInterval(moveStep, intervalMs)
}

const pauseSimulation = () => { isSimulating.value = false; if (simInterval) clearInterval(simInterval) }
const stopSimulation = () => { pauseSimulation() }

const syncLocation = async () => {
    if (!currentPos.value) return
    try {
        await api.post('/driver/location', { lat: currentPos.value.lat, lng: currentPos.value.lng })
    } catch (e) { console.warn('Sync failed:', e) }
}

// --- Active trip restore ---
const restoreActiveTrip = async () => {
    if (activeOrder.value) return  // ya hay viaje cargado, no pisar
    try {
        const res = await api.get('/driver/trips/current')
        if (res.data.status && res.data.data?.id) {
            activeOrder.value = res.data.data
        }
    } catch (e) {
        console.warn('No se pudo restaurar el viaje activo:', e)
    }
}

const onVisibilityChange = () => {
    if (document.visibilityState === 'visible') {
        restoreActiveTrip()
    }
}

// --- Init ---
onMounted(async () => {
    // Restore driver online/offline state from backend (survives page reloads)
    try {
        const meRes = await api.get('/auth/me')
        if (meRes.data.status && meRes.data.data?.driver) {
            const driver = meRes.data.data.driver
            isDriverOnline.value = parseInt(driver.is_active) === 1
            driverClientId.value = driver.client_id ?? null
            driverId.value = driver.id ?? null

            // Canal de flota: trips.{client_id} — compartido entre todos los drivers de la empresa
            if (driverClientId.value) {
                const fleetChannel = subscribe(`trips.${driverClientId.value}`)

                fleetChannel.bind('trip-taken', ({ trip_id }) => {
                    availableOrders.value = availableOrders.value.filter(o => o.id !== trip_id)
                })

                fleetChannel.bind('new-trip', () => {
                    if (isDriverOnline.value && !activeOrder.value) loadAvailableOrders()
                })
            }

            // Canal personal: driver.{id} — eventos exclusivos para este conductor
            if (driverId.value) {
                const driverChannel = subscribe(`driver.${driverId.value}`)
                driverChannel.bind('order-cancelled', handleOrderCancelled)
            }
        }
    } catch (e) {
        console.warn('Could not fetch driver status:', e)
    }

    if (Notification.permission === 'default') Notification.requestPermission()

    fetchTodayEarnings()
    earningsInterval = setInterval(fetchTodayEarnings, 30000)

    await restoreActiveTrip()
    if (!activeOrder.value) loadAvailableOrders()
    // Pusher maneja los cambios instantáneos; el polling es solo red de seguridad (fallback).
    pollInterval = setInterval(() => { if (!activeOrder.value && !isAccepting.value) loadAvailableOrders() }, 8000)

    document.addEventListener('visibilitychange', onVisibilityChange)

    await MapService.ensureSDKLoaded()
    MapService.initialize('app-map-root', { center: [20.5222, -100.8122], zoom: 13 })
        .then(() => { mapLoading.value = false; if (!isSimulatorMode.value) startRealTracking() })
        .catch(() => { mapLoading.value = false })
    setTimeout(() => { mapLoading.value = false }, 5000)
})

onUnmounted(() => {
    if (simInterval) clearInterval(simInterval)
    if (pollInterval) clearInterval(pollInterval)
    if (earningsInterval) clearInterval(earningsInterval)
    if (locationWatchId) navigator.geolocation.clearWatch(locationWatchId)
    if (arrivalToastTimer) clearTimeout(arrivalToastTimer)
    if (cancelledToastTimer) clearTimeout(cancelledToastTimer)
    document.removeEventListener('visibilitychange', onVisibilityChange)
    if (driverClientId.value) unsubscribe(`trips.${driverClientId.value}`)
    if (driverId.value) unsubscribe(`driver.${driverId.value}`)
    MapService.destroy()
})

const triggerCoinAnimation = () => {
    if (!walletChipRef.value || !completeButtonRef.value) return

    const btnRect = completeButtonRef.value.getBoundingClientRect()
    const chipRect = walletChipRef.value.getBoundingClientRect()

    const startX = btnRect.left + btnRect.width / 2
    const startY = btnRect.top + btnRect.height / 2
    const endX   = chipRect.left + chipRect.width / 2
    const endY   = chipRect.top  + chipRect.height / 2

    const COIN_COUNT = 9
    const SIZE = 26
    const half = SIZE / 2

    for (let i = 0; i < COIN_COUNT; i++) {
        setTimeout(() => {
            const coin = document.createElement('div')
            coin.textContent = '🪙'
            Object.assign(coin.style, {
                position:      'fixed',
                left:          '0px',
                top:           '0px',
                fontSize:      SIZE + 'px',
                lineHeight:    '1',
                zIndex:        '9999',
                pointerEvents: 'none',
                userSelect:    'none',
                willChange:    'transform, opacity',
            })
            document.body.appendChild(coin)

            const spreadX  = (Math.random() - 0.5) * 130
            const arcPeak  = Math.min(startY, endY) - 70 - Math.random() * 80
            const midX     = (startX + endX) / 2 + spreadX
            const spin1    = 90  + Math.random() * 120
            const spin2    = 270 + Math.random() * 180

            coin.animate([
                {
                    transform: `translate(${startX - half}px, ${startY - half}px) scale(1) rotate(0deg)`,
                    opacity: '1',
                    easing: 'cubic-bezier(0.2, 0, 0.3, 1)'
                },
                {
                    transform: `translate(${midX - half}px, ${arcPeak - half}px) scale(1.3) rotate(${spin1}deg)`,
                    opacity: '1',
                    easing: 'cubic-bezier(0.55, 0, 0.8, 0.8)'
                },
                {
                    transform: `translate(${endX - half}px, ${endY - half}px) scale(0.1) rotate(${spin2}deg)`,
                    opacity: '0',
                }
            ], {
                duration: 520 + Math.random() * 160,
                fill: 'forwards'
            }).onfinish = () => coin.remove()
        }, i * 70)
    }

    // Bounce the wallet chip when the last coin arrives
    setTimeout(() => {
        walletBounce.value = true
        setTimeout(() => { walletBounce.value = false }, 700)
    }, (COIN_COUNT - 1) * 70 + 420)
}

const completeDelivery = () => {
    triggerCoinAnimation()
    updateStatus('entregado')
}

const toggleSimulatorMode = () => {
    isSimulatorMode.value = !isSimulatorMode.value
    if (isSimulatorMode.value) {
        if (locationWatchId) { navigator.geolocation.clearWatch(locationWatchId); locationWatchId = null }
        if (routePoints.value.length > 0) {
            currentIndex.value = 0
            startSimulation()
        }
    } else {
        stopSimulation()
        startRealTracking()
    }
}
</script>

<template>
  <div class="relative w-full h-full overflow-hidden bg-slate-900">

    <!-- ── FULL SCREEN MAP ──────────────────────────────────── -->
    <div id="app-map-root" class="absolute inset-0 z-0"></div>

    <!-- Top gradient scrim -->
    <div class="absolute top-0 inset-x-0 h-36 bg-gradient-to-b from-black/40 to-transparent z-10 pointer-events-none"></div>
    <!-- Bottom gradient scrim -->
    <div class="absolute bottom-0 inset-x-0 h-72 bg-gradient-to-t from-black/25 to-transparent z-10 pointer-events-none"></div>

    <!-- ── TOP BAR ──────────────────────────────────────────── -->
    <div class="absolute top-0 inset-x-0 z-20 flex items-center justify-between px-4 pt-5 pb-3">

      <!-- Avatar -->
      <button class="w-11 h-11 rounded-2xl overflow-hidden shadow-lg ring-2 ring-white/20 flex-shrink-0">
        <img :src="avatarUrl" alt="avatar" class="w-full h-full object-cover" />
      </button>

      <!-- Driver status toggle -->
      <DriverStatusToggle
        v-model="isDriverOnline"
        :loading="isTogglingStatus"
        @update:modelValue="toggleDriverStatus"
      />

      <!-- Mode toggle -->
      <ModeToggle v-model="modeValue" />

    </div>

    <!-- ── FLOATING EARNINGS CHIP ────────────────────────────── -->
    <div class="absolute top-[72px] left-4 z-20 pointer-events-none">
      <div
        ref="walletChipRef"
        class="flex items-center gap-3 rounded-md bg-slate-800 py-1 px-3 border border-transparent text-sm text-white transition-all shadow-sm"
        :class="{ 'wallet-bounce': walletBounce }"
      >
        <span class="text-base leading-none">💰</span>
        <span class="text-white font-extrabold text-sm tracking-tight">${{ todayEarnings.toFixed(2) }}</span>
        <span class="w-px h-3 bg-white/20 flex-shrink-0"></span>
        <span class="text-white/50 text-xs">{{ todayTrips }} {{ todayTrips === 1 ? 'viaje' : 'viajes' }} hoy</span>
      </div>
    </div>

    <!-- ── FLOATING TRIPS CHIP ───────────────────────────────── -->
    <div v-if="viajesDisponibles !== null" class="absolute top-[72px] right-4 z-20 pointer-events-none">
      <div
        class="flex items-center gap-3 rounded-md py-1 px-3 border border-transparent text-sm text-white transition-all shadow-sm"
        :class="guaranteeBalance > 0 ? 'bg-blue-700' : 'bg-red-700'"
      >
        <span class="text-base leading-none">🎫</span>
        <span class="text-white font-extrabold text-sm tracking-tight">{{ viajesDisponibles }}</span>
        <span class="w-px h-3 bg-white/20 flex-shrink-0"></span>
        <span class="text-white/50 text-xs">viajes</span>
      </div>
    </div>

    <!-- ── ARRIVAL TOAST ──────────────────────────────────────── -->
    <Transition name="toast-drop">
      <div
        v-if="showArrivalToast"
        class="absolute top-24 inset-x-4 z-40 flex items-center gap-4
               bg-emerald-500 text-white rounded-2xl px-5 py-4
               shadow-[0_8px_32px_rgba(16,185,129,0.5)] pointer-events-none"
      >
        <span class="text-2xl flex-shrink-0">📍</span>
        <div>
          <p class="font-extrabold text-[15px] leading-tight">Llegaste al punto de recogida</p>
          <p class="text-emerald-100 text-[13px] mt-0.5">El cliente está esperando</p>
        </div>
      </div>
    </Transition>

    <!-- ── CANCELLATION TOAST ──────────────────────────────────── -->
    <Transition name="toast-drop">
      <div
        v-if="showCancelledToast"
        class="absolute top-24 inset-x-4 z-40 flex items-center gap-4
               bg-red-500 text-white rounded-2xl px-5 py-4
               shadow-[0_8px_32px_rgba(239,68,68,0.5)] pointer-events-none"
      >
        <span class="text-2xl flex-shrink-0">🚫</span>
        <div>
          <p class="font-extrabold text-[15px] leading-tight">Viaje cancelado</p>
          <p class="text-red-100 text-[13px] mt-0.5">El cliente canceló el pedido</p>
        </div>
      </div>
    </Transition>

    <!-- ── LOADING OVERLAY ──────────────────────────────────── -->
    <Transition name="fade">
      <div v-if="mapLoading" class="absolute inset-0 z-50 bg-slate-900 flex flex-col items-center justify-center gap-4">
        <div class="w-14 h-14 rounded-full border-4 border-blue-900 border-t-blue-500 animate-spin"></div>
        <p class="text-white/70 font-medium text-sm">Conectando con la flota...</p>
      </div>
    </Transition>

    <!-- ── BOTTOM CARD ──────────────────────────────────────── -->
    <div class="absolute bottom-0 inset-x-0 z-20">

      <!-- ─ NO ACTIVE ORDER ─ -->
      <Transition name="slide-up">
        <div v-if="!activeOrder" class="sheet-card">

          <!-- Handle -->
          <div class="w-14 h-[6px] bg-gray-200 rounded-full mx-auto mb-8"></div>

          <!-- Has orders -->
          <template v-if="availableOrders.length > 0">

            <!-- Content block -->
            <div class="sheet-content">

              <!-- Tappable route preview -->
              <button
                @click="showRouteDetail = !showRouteDetail"
                class="w-full text-left bg-gray-50 active:bg-gray-100 rounded-3xl px-6 py-6 mb-6
                       transition-colors duration-150 border border-gray-100"
              >
                <div class="flex items-center gap-5">
                  <div class="w-16 h-16 rounded-2xl bg-blue-50 border border-blue-100 flex items-center justify-center text-3xl flex-shrink-0">
                    🚚
                  </div>
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2.5 mb-2.5">
                      <span class="w-3 h-3 rounded-full bg-emerald-500 flex-shrink-0"></span>
                      <p class="text-[16px] font-semibold text-gray-800 truncate">
                        {{ availableOrders[0].pickup_address.split(',')[0] }}
                      </p>
                    </div>
                    <div class="flex items-center gap-2.5">
                      <span class="w-3 h-3 rounded-full bg-red-500 flex-shrink-0"></span>
                      <p class="text-[15px] text-gray-500 truncate">
                        {{ availableOrders[0].drop_address?.split(',')[0] ?? 'Destino' }}
                      </p>
                    </div>
                  </div>
                  <svg
                    class="w-6 h-6 text-gray-400 flex-shrink-0 transition-transform duration-200"
                    :class="showRouteDetail ? 'rotate-180' : ''"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                  >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                  </svg>
                </div>

                <Transition name="expand">
                  <div v-if="showRouteDetail" class="mt-5 pt-5 border-t border-gray-200 space-y-4">
                    <div class="flex gap-4 text-[15px]">
                      <span class="text-gray-400 font-semibold w-20 flex-shrink-0">Origen</span>
                      <span class="text-gray-700 font-semibold leading-snug">{{ availableOrders[0].pickup_address }}</span>
                    </div>
                    <div class="flex gap-4 text-[15px]">
                      <span class="text-gray-400 font-semibold w-20 flex-shrink-0">Destino</span>
                      <span class="text-gray-700 font-semibold leading-snug">{{ availableOrders[0].drop_address }}</span>
                    </div>
                    <div class="flex gap-4 text-[15px]">
                      <span class="text-gray-400 font-semibold w-20 flex-shrink-0">Distancia</span>
                      <span class="text-gray-700 font-semibold">{{ availableOrders[0].distance_km }} km</span>
                    </div>
                  </div>
                </Transition>
              </button>

              <!-- Stats chips -->
              <div class="grid grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded-2xl px-3 py-5 text-center border border-gray-100">
                  <p class="text-[11px] text-gray-400 font-semibold uppercase tracking-wide mb-2">Distancia</p>
                  <p class="font-bold text-gray-800 text-[17px]">{{ availableOrders[0].distance_km }} km</p>
                </div>
                <div class="bg-blue-50 rounded-2xl px-3 py-5 text-center border border-blue-100">
                  <p class="text-[11px] text-blue-400 font-semibold uppercase tracking-wide mb-2">Tarifa</p>
                  <p class="font-extrabold text-blue-600 text-[17px]">${{ availableOrders[0].cost }}</p>
                </div>
                <div class="bg-gray-50 rounded-2xl px-3 py-5 text-center border border-gray-100">
                  <p class="text-[11px] text-gray-400 font-semibold uppercase tracking-wide mb-2">Pago</p>
                  <p class="font-bold text-gray-800 text-[13px] leading-tight">
                    {{ availableOrders[0].payment_type === 'prepaid' ? 'Prepago' : 'Efectivo' }}
                  </p>
                </div>
              </div>
            </div>

            <!-- CTA block — pinned to bottom -->
            <div class="sheet-cta">
              <div
                v-if="!canAcceptTrips"
                class="flex items-center gap-3 bg-red-50 border border-red-200 rounded-2xl px-4 py-3 mb-3"
              >
                <span class="text-xl flex-shrink-0">🔒</span>
                <div>
                  <p class="text-red-700 font-bold text-[13px] leading-tight">Sin saldo de garantía</p>
                  <p class="text-red-400 text-[12px] mt-0.5">Recarga tu saldo para poder seguir recibiendo viajes nuevos.</p>
                </div>
              </div>
              <button
                @click="acceptOrder(availableOrders[0])"
                class="sheet-btn"
                :class="(canAcceptTrips && !isAccepting) ? 'sheet-btn--blue' : 'sheet-btn--disabled'"
                :disabled="!canAcceptTrips || isAccepting"
              >
                {{ isAccepting ? 'Aceptando...' : 'Aceptar Viaje' }}
              </button>
              <p class="text-center text-gray-400 text-[14px] mt-4">
                <template v-if="availableOrders.length > 1">
                  +{{ availableOrders.length - 1 }} viaje{{ availableOrders.length > 2 ? 's' : '' }} más disponible{{ availableOrders.length > 2 ? 's' : '' }}
                </template>
                <template v-else>Desliza para rechazar</template>
              </p>
            </div>
          </template>

          <!-- Empty state -->
          <template v-else>
            <div class="sheet-content flex flex-col items-center justify-center text-center">
              <p class="text-7xl mb-6 opacity-25">{{ isDriverOnline ? '📡' : '🔴' }}</p>
              <p class="text-gray-700 font-bold text-xl mb-3">
                {{ isDriverOnline ? 'Sin viajes disponibles' : 'Estás offline' }}
              </p>
              <p class="text-gray-400 text-[15px]">
                {{ isDriverOnline ? 'Esperando nuevas solicitudes...' : 'Activa el toggle "En línea" para recibir viajes' }}
              </p>
            </div>
            <div class="sheet-cta">
              <button
                v-if="isDriverOnline"
                @click="loadAvailableOrders"
                class="sheet-btn sheet-btn--blue"
              >
                Buscar de nuevo
              </button>
            </div>
          </template>
        </div>
      </Transition>

      <!-- ─ ACTIVE TRIP ─ -->
      <Transition name="slide-up">
        <div v-if="activeOrder" class="sheet-card">

          <!-- Handle -->
          <div class="w-14 h-[6px] bg-gray-200 rounded-full mx-auto mb-7"></div>

          <!-- Status badge -->
          <div class="flex justify-center mb-7">
            <span
              class="px-7 py-3 rounded-full text-[13px] font-extrabold uppercase tracking-widest"
              :class="{
                'bg-amber-100 text-amber-700':     activeOrder.status === 'tomado',
                'bg-blue-100 text-blue-700':       activeOrder.status === 'arribado',
                'bg-emerald-100 text-emerald-700': activeOrder.status === 'en_camino',
              }"
            >
              {{
                activeOrder.status === 'tomado'    ? '📍 En camino al origen' :
                activeOrder.status === 'arribado'  ? '✅ En punto de recogida' :
                activeOrder.status === 'en_camino' ? '🚚 En camino al destino' :
                activeOrder.status.toUpperCase()
              }}
            </span>
          </div>

          <!-- Content block -->
          <div class="sheet-content">

            <!-- Tappable route card -->
            <button
              @click="showRouteDetail = !showRouteDetail"
              class="w-full text-left bg-gray-50 active:bg-gray-100 rounded-3xl px-6 py-6 mb-6
                     transition-colors duration-150 border border-gray-100"
            >
              <div class="flex items-center justify-between mb-5">
                <span class="text-[12px] font-bold text-gray-400 uppercase tracking-widest">Ruta</span>
                <svg
                  class="w-6 h-6 text-gray-400 transition-transform duration-200"
                  :class="showRouteDetail ? 'rotate-180' : ''"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
              </div>

              <div class="flex items-start gap-5">
                <div class="flex flex-col items-center pt-1.5 gap-[6px] flex-shrink-0">
                  <span class="w-3.5 h-3.5 rounded-full bg-emerald-500 ring-2 ring-emerald-200"></span>
                  <span class="w-px h-8 bg-gray-300"></span>
                  <span class="w-3.5 h-3.5 rounded-full bg-red-500 ring-2 ring-red-200"></span>
                </div>
                <div class="flex-1 min-w-0 space-y-5">
                  <div>
                    <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest mb-1.5">Recogida</p>
                    <p class="text-[16px] font-semibold text-gray-800 leading-snug truncate">{{ activeOrder.pickup_address }}</p>
                  </div>
                  <div>
                    <p class="text-[11px] text-gray-400 font-bold uppercase tracking-widest mb-1.5">Entrega</p>
                    <p class="text-[16px] font-semibold text-gray-800 leading-snug truncate">{{ activeOrder.drop_address }}</p>
                  </div>
                </div>
              </div>

              <Transition name="expand">
                <div v-if="showRouteDetail" class="mt-5 pt-5 border-t border-gray-200 space-y-4">
                  <div class="flex gap-4 text-[15px]">
                    <span class="text-gray-400 font-semibold w-20 flex-shrink-0">Origen</span>
                    <span class="text-gray-700 font-semibold leading-snug">{{ activeOrder.pickup_address }}</span>
                  </div>
                  <div class="flex gap-4 text-[15px]">
                    <span class="text-gray-400 font-semibold w-20 flex-shrink-0">Destino</span>
                    <span class="text-gray-700 font-semibold leading-snug">{{ activeOrder.drop_address }}</span>
                  </div>
                  <div class="flex gap-4 text-[15px]">
                    <span class="text-gray-400 font-semibold w-20 flex-shrink-0">Distancia</span>
                    <span class="text-gray-700 font-semibold">{{ activeOrder.distance_km }} km</span>
                  </div>
                </div>
              </Transition>
            </button>

            <!-- Progress bar (simulator only) -->
            <div v-if="isSimulatorMode">
              <div class="flex justify-between text-[13px] text-gray-500 font-semibold mb-3">
                <span>Progreso de simulación</span>
                <span class="text-blue-600 font-bold">{{ progress }}%</span>
              </div>
              <div class="h-3 bg-gray-100 rounded-full overflow-hidden">
                <div
                  class="h-full bg-blue-600 rounded-full transition-all duration-300"
                  :style="{ width: progress + '%' }"
                ></div>
              </div>
            </div>

            <!-- Simulator speed control -->
            <div v-if="isSimulatorMode" class="mt-5 bg-amber-50 border border-amber-100 rounded-2xl px-6 py-5">
              <div class="flex justify-between items-center mb-4">
                <p class="text-[12px] font-bold text-amber-700 uppercase tracking-widest">Velocidad</p>
                <p class="text-[17px] font-extrabold text-amber-700">{{ speedKmh }} km/h</p>
              </div>
              <input type="range" min="30" max="120" v-model="speedKmh"
                     class="w-full accent-amber-500 cursor-pointer h-2" />
            </div>
          </div>

          <!-- CTA block — pinned to bottom -->
          <div class="sheet-cta">

            <!-- Error message -->
            <div v-if="statusError" class="flex items-center gap-4 text-red-600 text-[14px] font-semibold
                                           bg-red-50 border border-red-200 rounded-2xl px-5 py-4 mb-4">
              <svg class="w-5 h-5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
              </svg>
              {{ statusError }}
            </div>

            <!-- Contacto del receptor — solo cuando el viaje está aceptado -->
            <div
              v-if="['tomado','arribado','en_camino'].includes(activeOrder.status) && activeOrder.receiver_phone"
              class="flex items-center justify-between bg-gray-50 border border-gray-100 rounded-2xl px-5 py-4 mb-4 gap-3"
            >
              <div class="min-w-0">
                <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-0.5">Receptor</p>
                <p class="text-[15px] font-bold text-gray-800 truncate">{{ activeOrder.receiver_name || 'Sin nombre' }}</p>
                <p class="text-[13px] text-gray-500 font-semibold">{{ activeOrder.receiver_phone }}</p>
              </div>
              <div class="flex gap-2 flex-shrink-0">
                <a
                  :href="`tel:${activeOrder.receiver_phone}`"
                  class="w-11 h-11 rounded-full bg-emerald-500 flex items-center justify-center shadow-md active:scale-95 transition-transform"
                  title="Llamar"
                >
                  <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M6.62 10.79a15.05 15.05 0 006.59 6.59l2.2-2.2a1 1 0 011.01-.24 11.47 11.47 0 003.59.57 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1 11.47 11.47 0 00.57 3.59 1 1 0 01-.25 1.01l-2.2 2.2z"/>
                  </svg>
                </a>
                <a
                  :href="`https://wa.me/52${activeOrder.receiver_phone.replace(/\D/g, '')}`"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="w-11 h-11 rounded-full bg-[#25D366] flex items-center justify-center shadow-md active:scale-95 transition-transform"
                  title="WhatsApp"
                >
                  <svg class="w-5 h-5 text-white" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                  </svg>
                </a>
              </div>
            </div>

            <!-- PASO 1 — auto-arrival, no manual button needed -->
            <template v-if="activeOrder.status === 'tomado'">
              <div class="sheet-btn-disabled">
                {{ isSimulatorMode ? `Simulando… ${progress}%` : 'Navegando al punto de recogida…' }}
              </div>
            </template>

            <!-- PASO 2 -->
            <template v-else-if="activeOrder.status === 'arribado'">
              <button
                @click="updateStatus('en_camino')"
                class="sheet-btn sheet-btn--indigo"
              >
                Iniciar viaje al destino
              </button>
            </template>

            <!-- PASO 3 -->
            <template v-else-if="activeOrder.status === 'en_camino'">
              <button
                v-if="!isSimulatorMode || progress >= 100"
                ref="completeButtonRef"
                @click="completeDelivery"
                class="sheet-btn sheet-btn--green"
              >
                Completar Entrega ✓
              </button>
              <div v-else class="sheet-btn-disabled">
                Simulando trayecto al destino...
              </div>
            </template>
          </div>
        </div>
      </Transition>
    </div>
  </div>
</template>

<style scoped>
/* Map must fill its container */
#app-map-root {
  width: 100%;
  height: 100%;
}

/* ── Bottom Sheet ───────────────────────────────────────────── */

/* Container: flex column, anchored to bottom of screen */
.sheet-card {
  display: flex;
  flex-direction: column;
  min-height: 48vh;
  background: #ffffff;
  border-radius: 40px 40px 0 0;
  box-shadow: 0 -14px 56px rgba(0, 0, 0, 0.22);
  padding: 24px 32px 0 32px;       /* top + sides only — bottom handled by CTA */
}

/* Content area grows to fill available space */
.sheet-content {
  flex: 1;
  min-height: 0;
}

/* CTA area pinned to bottom, clears the BottomNav (72px) + home indicator */
.sheet-cta {
  margin-top: auto;
  padding: 24px 0 96px;
}

/* ── Primary button ─────────────────────────────────────────── */

.sheet-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  padding: 26px 24px;
  border-radius: 24px;
  border: none;
  font-size: 20px;
  font-weight: 800;
  letter-spacing: 0.015em;
  color: #ffffff;
  cursor: pointer;
  transition: transform 0.12s ease, box-shadow 0.12s ease;
  -webkit-tap-highlight-color: transparent;
}

.sheet-btn:active {
  transform: scale(0.97);
}

.sheet-btn--blue {
  background: #2563eb;
  box-shadow: 0 12px 40px rgba(37, 99, 235, 0.55);
}
.sheet-btn--blue:active { background: #1d4ed8; }

.sheet-btn--disabled {
  background: #E5E7EB;
  color: #9CA3AF;
  cursor: not-allowed;
}

.sheet-btn--indigo {
  background: #4f46e5;
  box-shadow: 0 12px 40px rgba(79, 70, 229, 0.55);
}
.sheet-btn--indigo:active { background: #4338ca; }

.sheet-btn--green {
  background: #10b981;
  box-shadow: 0 12px 40px rgba(16, 185, 129, 0.55);
}
.sheet-btn--green:active { background: #059669; }

/* ── Wallet bounce on coin arrival ─────────────────────────── */
@keyframes walletBounce {
  0%   { transform: scale(1); }
  20%  { transform: scale(1.45); }
  45%  { transform: scale(0.88); }
  65%  { transform: scale(1.2); }
  80%  { transform: scale(0.96); }
  100% { transform: scale(1); }
}

.wallet-bounce {
  animation: walletBounce 0.65s cubic-bezier(0.36, 0.07, 0.19, 0.97) forwards;
}

/* Disabled placeholder */
.sheet-btn-disabled {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  padding: 26px 24px;
  border-radius: 24px;
  background: #f3f4f6;
  color: #9ca3af;
  font-size: 16px;
  font-weight: 600;
  text-align: center;
  cursor: not-allowed;
  user-select: none;
}

/* ── Transitions ────────────────────────────────────────────── */

.fade-enter-active, .fade-leave-active { transition: opacity 0.4s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-up-enter-active, .slide-up-leave-active { transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
.slide-up-enter-from, .slide-up-leave-to { opacity: 0; transform: translateY(24px); }

.toast-drop-enter-active { transition: all 0.35s cubic-bezier(0.34, 1.15, 0.64, 1); }
.toast-drop-leave-active { transition: all 0.25s ease; }
.toast-drop-enter-from { opacity: 0; transform: translateY(-16px) scale(0.95); }
.toast-drop-leave-to   { opacity: 0; transform: translateY(-8px); }

.expand-enter-active, .expand-leave-active { transition: all 0.25s ease; overflow: hidden; }
.expand-enter-from, .expand-leave-to { opacity: 0; max-height: 0; }
.expand-enter-to, .expand-leave-from { opacity: 1; max-height: 200px; }
</style>
