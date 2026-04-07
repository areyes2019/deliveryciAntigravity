<script setup>
import { ref, onMounted, nextTick, watch } from 'vue'
import api from '../api'
import MapService from '../services/maps/MapService'

const loading = ref(true)
const saving = ref(false)
const isEditing = ref(false)

const config = ref({
    pricing_mode: 'distance',
    base_fare: 0,
    price_per_km: 0
})

const zones = ref([])
const newZone = ref({ name: '', base_price: 0 })

let mapInstance = null
let drawingManager = null
let currentPolygon = null

const fetchConfig = async () => {
    try {
        const meRes = await api.get('/auth/me');
        if (meRes.data.status) {
            config.value.pricing_mode = meRes.data.data.client.pricing_mode || 'distance'
            config.value.base_fare = parseFloat(meRes.data.data.client.base_fare) || 0
            config.value.price_per_km = parseFloat(meRes.data.data.client.price_per_km) || 0
        }
    } catch (e) {
        console.error('Error fetching config:', e)
    }
}

const fetchZones = async () => {
    try {
        const res = await api.get('/zones')
        if (res.data.status) {
            zones.value = res.data.data
        }
    } catch (e) {
        console.error('Error fetching zones:', e)
    }
}

const loadData = async () => {
    loading.value = true
    await fetchConfig()
    await fetchZones()

    // Ensure SDK is fully loaded before continuing
    try {
        await MapService.ensureSDKLoaded()
    } catch(e) {
        console.warn('MapService error:', e)
    }

    loading.value = false
    
    // Check if configuration is already established
    if (config.value.base_fare > 0 || config.value.price_per_km > 0 || config.value.pricing_mode === 'zone' || zones.value.length > 0) {
        isEditing.value = false;
    } else {
        isEditing.value = true;
    }
    
    if (isEditing.value && config.value.pricing_mode === 'zone') {
        setTimeout(initMap, 300)
    }
}

const enterEditMode = () => {
    isEditing.value = true;
    if (config.value.pricing_mode === 'zone') {
        setTimeout(initMap, 300)
    }
}

watch(() => config.value.pricing_mode, (newMode) => {
    if (isEditing.value && newMode === 'zone') {
        setTimeout(initMap, 300)
    }
})

const saveConfig = async () => {
    saving.value = true
    try {
        await api.put('/pricing-config', config.value)
        alert('Configuración guardada exitosamente')
        isEditing.value = false;
    } catch (e) {
        alert('Error al guardar configuración')
    } finally {
        saving.value = false
    }
}

const initMap = () => {
    if (!window.google?.maps) return;
    
    const mapEl = document.getElementById('zone-map')
    if (!mapEl) return;

    // Detect if the previous DOM element was destroyed by Vue v-if
    if (mapInstance && mapInstance.getDiv() !== mapEl) {
        mapInstance = null;
        drawingManager = null;
        currentPolygon = null;
    }

    if (!mapInstance) {
        mapInstance = new google.maps.Map(mapEl, {
            center: { lat: 20.5222, lng: -100.8122 },
            zoom: 13,
            fullscreenControl: false,
            streetViewControl: false
        })

        drawingManager = new google.maps.drawing.DrawingManager({
            drawingMode: google.maps.drawing.OverlayType.POLYGON,
            drawingControl: true,
            drawingControlOptions: {
                position: google.maps.ControlPosition.TOP_CENTER,
                drawingModes: [google.maps.drawing.OverlayType.POLYGON],
            },
            polygonOptions: {
                fillColor: '#6366F1',
                fillOpacity: 0.3,
                strokeWeight: 2,
                clickable: false,
                editable: false,
                zIndex: 1,
            },
        })
        drawingManager.setMap(mapInstance)

        google.maps.event.addListener(drawingManager, 'polygoncomplete', function (polygon) {
            if (currentPolygon) currentPolygon.setMap(null);
            currentPolygon = polygon;
            drawingManager.setDrawingMode(null); // Switch back to generic cursor
        });
    }

    renderExistingZones();
}

let renderedPolygons = [];
const renderExistingZones = () => {
    renderedPolygons.forEach(p => p.setMap(null));
    renderedPolygons = [];

    zones.value.forEach(zone => {
        let coords = null;
        try {
            coords = typeof zone.polygon_coordinates === 'string' ? JSON.parse(zone.polygon_coordinates) : zone.polygon_coordinates;
        } catch(e){}

        if (Array.isArray(coords)) {
            const polygon = new google.maps.Polygon({
                paths: coords,
                strokeColor: "#10B981",
                strokeOpacity: 0.8,
                strokeWeight: 2,
                fillColor: "#10B981",
                fillOpacity: 0.35,
                map: mapInstance
            });
            renderedPolygons.push(polygon);
        }
    });
}

const saveZone = async () => {
    if (!currentPolygon) {
        alert('Debes dibujar un polígono en el mapa.')
        return
    }
    if (!newZone.value.name || newZone.value.base_price < 0) {
        alert('Datos de la zona incompletos.')
        return
    }

    const path = currentPolygon.getPath()
    const coords = []
    for(let i = 0; i < path.getLength(); i++) {
        let pt = path.getAt(i)
        coords.push({ lat: pt.lat(), lng: pt.lng() })
    }

    try {
        const payload = {
            name: newZone.value.name,
            base_price: parseFloat(newZone.value.base_price),
            polygon_coordinates: coords
        }
        await api.post('/zones', payload)
        
        currentPolygon.setMap(null)
        currentPolygon = null
        newZone.value = { name: '', base_price: 0 }
        
        await fetchZones()
        renderExistingZones()
        
    } catch (e) {
        console.error(e)
        alert('Error al guardar zona.')
    }
}

const deleteZone = async (id) => {
    if (!confirm('¿Eliminar zona?')) return;
    try {
        await api.delete(`/zones/${id}`)
        await fetchZones()
        renderExistingZones()
    } catch (e) {
        alert('Error al eliminar zona')
    }
}

onMounted(() => {
    loadData()
})
</script>

<template>
  <div class="pricing-config">
    <header class="page-header">
      <div class="header-info">
        <h1>Configuración de Precios</h1>
        <p>Define cómo calcular los costos de envío para tus clientes.</p>
      </div>
    </header>

    <div v-if="loading" class="loading-state">
        <div class="spinner"></div> Cargando configuración...
    </div>

    <!-- SUMMARY MODE -->
    <div v-else-if="!isEditing" class="summary-view">
        <div class="summary-card">
            <h3>Tarifa Actual Establecida</h3>
            <div class="summary-stats">
                <div class="stat-row">
                    <span class="label">Modelo de Cobro:</span>
                    <span class="value badge">{{ config.pricing_mode === 'distance' ? 'Distancia (Kilometraje)' : 'Zonas (Geocercas)' }}</span>
                </div>
                
                <template v-if="config.pricing_mode === 'distance'">
                    <div class="stat-row">
                        <span class="label">Tarifa Base:</span>
                        <span class="value">${{ config.base_fare.toFixed(2) }}</span>
                    </div>
                    <div class="stat-row">
                        <span class="label">Costo por Kilómetro:</span>
                        <span class="value">${{ config.price_per_km.toFixed(2) }}</span>
                    </div>
                </template>
                
                <template v-else>
                    <div class="stat-row">
                        <span class="label">Zonas Configuradas:</span>
                        <span class="value">{{ zones.length }} Zonas activas</span>
                    </div>
                </template>
            </div>
            <p class="summary-hint">Los viajes se están calculando y verificando automáticamente usando esta configuración al momento de crear un pedido.</p>
            <button class="btn-primary" @click="enterEditMode">✏️ Editar Tarifas</button>
        </div>
    </div>

    <!-- EDIT MODE -->
    <div v-else class="content-split">
        <!-- GLOBAL PREFS -->
        <div class="config-panel">
            <h3>Estrategia Principal</h3>
            <div class="form-group">
                <label>Modo de cobro</label>
                <select v-model="config.pricing_mode">
                    <option value="distance">Por Distancia (Kilometraje)</option>
                    <option value="zone">Zonas (Geocercas)</option>
                </select>
            </div>

            <!-- DISTANCE CONFIG -->
            <div v-if="config.pricing_mode === 'distance'" class="mode-config-box">
                <h4>Ajustes de Distancia</h4>
                <div class="form-group">
                    <label>Tarifa Base ($)</label>
                    <input type="number" step="0.5" v-model="config.base_fare" placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Precio por Km ($)</label>
                    <input type="number" step="0.5" v-model="config.price_per_km" placeholder="0.00">
                </div>
            </div>

            <!-- ZONE CONFIG INFO -->
            <div v-if="config.pricing_mode === 'zone'" class="mode-config-box">
                <h4>Ajustes por Zona</h4>
                <div class="rules-info">
                    <p><b>Reglas:</b></p>
                    <ul style="padding-left:1rem;margin-top:0.5rem;font-size:0.85rem">
                        <li>Misma zona (Origen - Destino): Tarifa de la zona.</li>
                        <li>Cruce de zonas: Suma de la tarifa de ambas zonas.</li>
                        <li>Puntos sin zona: Rechaza la orden de envío.</li>
                    </ul>
                </div>
            </div>

            <button class="btn-primary mt-4" @click="saveConfig" :disabled="saving">
                {{ saving ? 'Guardando...' : 'Guardar Configuración' }}
            </button>
            <button class="btn-cancel mt-4" style="margin-left:1rem" @click="isEditing = false" :disabled="saving">
                Cancelar
            </button>
        </div>

        <!-- ZONES EDITOR -->
        <div class="zone-panel" v-if="config.pricing_mode === 'zone'">
            <h3>Gestión de Zonas</h3>
            
            <div class="map-container-wrapper">
                <div id="zone-map" style="width:100%;height:450px;border-radius:12px;overflow:hidden"></div>
                <div class="map-help">
                    Utiliza la herramienta ⬟ del mapa para trazar un polígono cerrado.
                </div>
            </div>

            <div class="new-zone-form">
                <input type="text" v-model="newZone.name" placeholder="Nombre (Ej. Centro, Norte)">
                <input type="number" v-model="newZone.base_price" placeholder="Tarifa ($)">
                <button class="btn-secondary" @click="saveZone">Guardar Zona Trazada</button>
            </div>

            <h4 class="mt-4">Zonas Creadas</h4>
            <div class="zones-list">
                <div v-if="zones.length === 0" class="empty">No hay zonas definidas.</div>
                <div v-for="z in zones" :key="z.id" class="zone-item">
                    <div class="z-info">
                        <strong>{{ z.name }}</strong>
                        <span class="z-price">${{ z.base_price }} base</span>
                    </div>
                    <button class="btn-danger btn-sm" @click="deleteZone(z.id)">✕</button>
                </div>
            </div>
        </div>
    </div>
  </div>
</template>

<style scoped>
.pricing-config { display: flex; flex-direction: column; gap: 1.5rem; }
.page-header h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.25rem; }
.page-header p { color: var(--text-muted); font-size: 0.95rem; }

.content-split { display: grid; grid-template-columns: 1fr 2.5fr; gap: 2rem; align-items: start; }

/* Summary */
.summary-view { display: flex; justify-content: center; }
.summary-card { background: white; border-radius: 16px; padding: 2rem; width: 100%; max-width: 500px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border-light); text-align: center; }
.summary-card h3 { font-size: 1.35rem; margin-bottom: 1.5rem; font-weight: 700; color: #1F2937; }
.summary-stats { display: flex; flex-direction: column; gap: 1rem; text-align: left; background: #F9FAFB; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; }
.stat-row { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #E5E7EB; padding-bottom: 0.5rem; }
.stat-row:last-child { border-bottom: none; padding-bottom: 0; }
.stat-row .label { color: #4B5563; font-weight: 600; font-size: 0.95rem; }
.stat-row .value { font-weight: 700; font-size: 1.1rem; color: #111827; }
.stat-row .badge { background: #E0E7FF; color: #4338CA; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; }
.summary-hint { font-size: 0.85rem; color: #6B7280; margin-bottom: 1.5rem; }

.config-panel, .zone-panel {
    background: white; border-radius: 16px; padding: 1.5rem;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border-light);
}

.config-panel h3, .zone-panel h3 { font-size: 1.1rem; margin-bottom: 1.25rem; font-weight: 700; }

.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; color: #374151; }
.form-group input, .form-group select {
    width: 100%; padding: 0.65rem; border: 1px solid #D1D5DB; border-radius: 8px; font-family: inherit;
}

.mode-config-box { background: #F9FAFB; padding: 1rem; border-radius: 8px; border: 1px dashed #D1D5DB; }
.mode-config-box h4 { font-size: 0.9rem; margin-bottom: 1rem; font-weight: 600; }

.mt-4 { margin-top: 1rem; }

.map-container-wrapper { position: relative; border: 1px solid #D1D5DB; border-radius: 12px; margin-bottom: 1rem; }
.map-help { padding: 0.5rem; font-size: 0.75rem; color: #6B7280; text-align: center; background: #F3F4F6; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px;}

.new-zone-form { display: flex; gap: 0.5rem; }
.new-zone-form input { flex: 1; padding: 0.5rem; border: 1px solid #D1D5DB; border-radius: 6px; }

.zones-list { display: flex; flex-direction: column; gap: 0.5rem; }
.zone-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem; background: #F9FAFB; border-radius: 6px; border: 1px solid #E5E7EB; }
.z-info strong { display: block; color: #1F2937; font-size: 0.9rem; }
.z-info .z-price { font-size: 0.8rem; color: #10B981; font-weight: 700; }
.btn-sm { padding: 0.25rem 0.5rem; font-size: 0.8rem; border-radius: 4px; }
.btn-danger { background: #FEE2E2; color: #DC2626; border: none; cursor: pointer; }
.btn-cancel { background: white; color: #374151; padding: 0.8rem 1.5rem; border-radius: 8px; border: 1px solid #D1D5DB; font-weight: 600; cursor: pointer; transition: all 0.2s; }
.btn-cancel:hover { background: #F3F4F6; }

@media (max-width: 800px) {
    .content-split { grid-template-columns: 1fr; }
}
</style>
