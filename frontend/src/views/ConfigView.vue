<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import api from '../api'
import MapService from '../services/maps/MapService'

const activeTab = ref('billing')

// ─────────────────────────────────────────────
// COBRO A CONDUCTORES
// ─────────────────────────────────────────────
const billingLoading  = ref(true)
const billingSaving   = ref(false)
const billingFeedback = ref({ type: '', message: '' })

const billingForm = ref({
    tipo_esquema:        'credito',
    precio_credito:      '',
    porcentaje_comision: '',
})

const fetchBillingConfig = async () => {
    try {
        const res = await api.get('/driver-billing')
        if (res.data.status && res.data.data?.tipo_esquema) {
            const c = res.data.data
            billingForm.value.tipo_esquema        = c.tipo_esquema
            billingForm.value.precio_credito      = c.precio_credito      ?? ''
            billingForm.value.porcentaje_comision = c.porcentaje_comision ?? ''
        }
    } catch (e) {
        console.error('Error fetching billing config:', e)
    } finally {
        billingLoading.value = false
    }
}

const saveBillingConfig = async () => {
    billingFeedback.value = { type: '', message: '' }
    if (billingForm.value.tipo_esquema === 'credito') {
        const v = parseFloat(billingForm.value.precio_credito)
        if (!v || v <= 0) {
            billingFeedback.value = { type: 'error', message: 'Ingresa un precio de crédito válido mayor a 0.' }
            return
        }
    } else {
        const v = parseFloat(billingForm.value.porcentaje_comision)
        if (!v || v <= 0 || v > 100) {
            billingFeedback.value = { type: 'error', message: 'Ingresa un porcentaje válido entre 1 y 100.' }
            return
        }
    }
    billingSaving.value = true
    try {
        const payload = { tipo_esquema: billingForm.value.tipo_esquema }
        if (billingForm.value.tipo_esquema === 'credito') {
            payload.precio_credito = parseFloat(billingForm.value.precio_credito)
        } else {
            payload.porcentaje_comision = parseFloat(billingForm.value.porcentaje_comision)
        }
        const res = await api.put('/driver-billing', payload)
        billingFeedback.value = {
            type: res.data.status ? 'success' : 'error',
            message: res.data.message,
        }
    } catch (e) {
        billingFeedback.value = { type: 'error', message: e.response?.data?.message || 'Error al guardar.' }
    } finally {
        billingSaving.value = false
    }
}

const onBillingSchemaChange = () => {
    billingForm.value.precio_credito      = ''
    billingForm.value.porcentaje_comision = ''
    billingFeedback.value = { type: '', message: '' }
}

const isCredito    = computed(() => billingForm.value.tipo_esquema === 'credito')
const isPorcentaje = computed(() => billingForm.value.tipo_esquema === 'porcentaje')

// ─────────────────────────────────────────────
// PRECIOS DE ENVÍO
// ─────────────────────────────────────────────
const pricingLoading  = ref(true)
const pricingSaving   = ref(false)
const pricingEditing  = ref(false)

const pricingConfig = ref({
    pricing_mode:    'distance',
    base_fare:       0,
    price_per_km:    0,
    min_distance_km: 0,
})

const geofence    = ref(null)   // única geocerca del cliente (o null)
const newZoneName = ref('')

let mapInstance      = null
let drawingManager   = null
let currentPolygon   = null
let renderedPolygons = []

const fetchPricingConfig = async () => {
    try {
        const res = await api.get('/auth/me')
        if (res.data.status) {
            const c = res.data.data.client
            pricingConfig.value.pricing_mode    = c.pricing_mode    || 'distance'
            pricingConfig.value.base_fare       = parseFloat(c.base_fare)       || 0
            pricingConfig.value.price_per_km    = parseFloat(c.price_per_km)    || 0
            pricingConfig.value.min_distance_km = parseFloat(c.min_distance_km) || 0
        }
    } catch (e) {
        console.error('Error fetching pricing config:', e)
    }
}

const fetchGeofence = async () => {
    try {
        const res = await api.get('/geofences')
        geofence.value = res.data?.data?.[0] ?? null
    } catch (e) {
        console.error('Error fetching geofence:', e)
        geofence.value = null
    }
}

const loadPricingData = async () => {
    pricingLoading.value = true
    await fetchPricingConfig()
    try { await MapService.ensureSDKLoaded() } catch {}
    pricingLoading.value = false

    const hasConfig = pricingConfig.value.base_fare > 0 || pricingConfig.value.price_per_km > 0
    pricingEditing.value = !hasConfig
}

const enterPricingEditMode = () => {
    pricingEditing.value = true
}

watch(() => activeTab.value, async (tab) => {
    if (tab === 'zones') {
        await fetchGeofence()
        setTimeout(initMap, 300)
    }
})

const savePricingConfig = async () => {
    pricingSaving.value = true
    try {
        await api.put('/pricing-config', pricingConfig.value)
        alert('Configuración guardada exitosamente')
        pricingEditing.value = false
    } catch {
        alert('Error al guardar configuración')
    } finally {
        pricingSaving.value = false
    }
}

const initMap = () => {
    if (!window.google?.maps) return
    const mapEl = document.getElementById('zone-map')
    if (!mapEl) return

    if (mapInstance && mapInstance.getDiv() !== mapEl) {
        mapInstance = null; drawingManager = null; currentPolygon = null
    }

    if (!mapInstance) {
        mapInstance = new google.maps.Map(mapEl, {
            center: { lat: 20.5222, lng: -100.8122 }, zoom: 13,
            fullscreenControl: false, streetViewControl: false
        })
        drawingManager = new google.maps.drawing.DrawingManager({
            drawingMode: google.maps.drawing.OverlayType.POLYGON,
            drawingControl: true,
            drawingControlOptions: {
                position: google.maps.ControlPosition.TOP_CENTER,
                drawingModes: [google.maps.drawing.OverlayType.POLYGON],
            },
            polygonOptions: { fillColor: '#6366F1', fillOpacity: 0.3, strokeWeight: 2, clickable: false, editable: false, zIndex: 1 },
        })
        drawingManager.setMap(mapInstance)
        google.maps.event.addListener(drawingManager, 'polygoncomplete', (polygon) => {
            if (currentPolygon) currentPolygon.setMap(null)
            currentPolygon = polygon
            drawingManager.setDrawingMode(null)
        })
    }

    // Si ya hay geocerca, desactivar dibujo
    if (geofence.value) {
        drawingManager.setDrawingMode(null)
    }

    renderExistingZones()
}

const renderExistingZones = () => {
    renderedPolygons.forEach(p => p.setMap(null))
    renderedPolygons = []
    if (!geofence.value) return
    let coords = null
    try {
        coords = typeof geofence.value.polygon_coordinates === 'string'
            ? JSON.parse(geofence.value.polygon_coordinates)
            : geofence.value.polygon_coordinates
    } catch {}
    if (Array.isArray(coords)) {
        const polygon = new google.maps.Polygon({
            paths: coords, strokeColor: '#10B981', strokeOpacity: 0.8,
            strokeWeight: 2, fillColor: '#10B981', fillOpacity: 0.35, map: mapInstance
        })
        renderedPolygons.push(polygon)
        // Si ya existe geocerca, desactivar modo dibujo
        if (drawingManager) drawingManager.setDrawingMode(null)
    }
}

const saveGeofence = async () => {
    if (!currentPolygon) { alert('Debes dibujar un polígono en el mapa.'); return }
    const path = currentPolygon.getPath()
    const coords = []
    for (let i = 0; i < path.getLength(); i++) {
        const pt = path.getAt(i)
        coords.push({ lat: pt.lat(), lng: pt.lng() })
    }
    try {
        await api.post('/geofences', {
            name: newZoneName.value.trim() || 'Zona de Operación',
            polygon_coordinates: coords
        })
        currentPolygon.setMap(null); currentPolygon = null
        newZoneName.value = ''
        await fetchGeofence(); renderExistingZones()
    } catch { alert('Error al guardar geocerca.') }
}

const deleteGeofence = async () => {
    if (!geofence.value) return
    if (!confirm('¿Eliminar la geocerca? Los viajes no tendrán restricción de zona.')) return
    try {
        await api.delete(`/geofences/${geofence.value.id}`)
        geofence.value = null
        renderedPolygons.forEach(p => p.setMap(null)); renderedPolygons = []
        // Reactivar modo dibujo
        if (drawingManager) drawingManager.setDrawingMode(google.maps.drawing.OverlayType.POLYGON)
    } catch { alert('Error al eliminar geocerca') }
}

// ─────────────────────────────────────────────
// INIT
// ─────────────────────────────────────────────
onMounted(() => {
    fetchBillingConfig()
    loadPricingData()
    fetchGeofence()
})
</script>

<template>
  <div class="config-page" :class="{ 'full-width': activeTab === 'zones' }">

    <div class="config-header">
      <h1>⚙️ Configuración del Negocio</h1>
      <p>Administra las preferencias de tu empresa.</p>
    </div>

    <div class="tabs">
      <button class="tab-btn" :class="{ active: activeTab === 'billing' }" @click="activeTab = 'billing'">🤝 Cobro a Conductores</button>
      <button class="tab-btn" :class="{ active: activeTab === 'pricing' }" @click="activeTab = 'pricing'">💲 Precios de Envío</button>
      <button class="tab-btn" :class="{ active: activeTab === 'zones' }" @click="activeTab = 'zones'">🗺️ Zonas</button>
    </div>

    <div class="sections">

      <!-- ── Cobro a Conductores ── -->
      <section class="config-section" v-show="activeTab === 'billing'">
        <div class="section-header">
          <h2>🤝 Cobro a Conductores</h2>
          <p>Define cómo se le cobra a tu flotilla por cada viaje completado.</p>
        </div>

        <div v-if="billingLoading" class="loading-state">Cargando…</div>

        <div v-else class="card">
          <div class="field-group">
            <label class="field-label">Tipo de esquema</label>
            <div class="schema-options">
              <label class="schema-option" :class="{ selected: isCredito }">
                <input type="radio" value="credito" v-model="billingForm.tipo_esquema" @change="onBillingSchemaChange" />
                <div class="option-content">
                  <span class="option-icon">🪙</span>
                  <div><strong>Crédito</strong><p>Se descuenta un monto fijo del monedero por cada viaje.</p></div>
                </div>
              </label>
              <label class="schema-option" :class="{ selected: isPorcentaje }">
                <input type="radio" value="porcentaje" v-model="billingForm.tipo_esquema" @change="onBillingSchemaChange" />
                <div class="option-content">
                  <span class="option-icon">📊</span>
                  <div><strong>Porcentaje</strong><p>Se retiene un % del ingreso del conductor por cada viaje.</p></div>
                </div>
              </label>
            </div>
          </div>

          <div class="field-group" v-if="isCredito">
            <label class="field-label" for="precio_credito">Precio por viaje (crédito)</label>
            <div class="input-prefix">
              <span>$</span>
              <input id="precio_credito" type="number" min="0.01" step="0.01" placeholder="Ej. 15.00" v-model="billingForm.precio_credito" />
            </div>
            <small>Monto que se descontará del saldo del conductor al completar un viaje.</small>
          </div>

          <div class="field-group" v-if="isPorcentaje">
            <label class="field-label" for="porcentaje_comision">Comisión por viaje (%)</label>
            <div class="input-prefix">
              <span>%</span>
              <input id="porcentaje_comision" type="number" min="1" max="100" step="0.01" placeholder="Ej. 20" v-model="billingForm.porcentaje_comision" />
            </div>
            <small>Porcentaje del valor del viaje que retiene la empresa.</small>
          </div>

          <div v-if="billingFeedback.message" class="feedback" :class="billingFeedback.type">
            {{ billingFeedback.message }}
          </div>

          <button class="btn-save" :disabled="billingSaving" @click="saveBillingConfig">
            {{ billingSaving ? 'Guardando…' : 'Guardar configuración' }}
          </button>
        </div>
      </section>

      <!-- ── Precios de Envío ── -->
      <section class="config-section" v-show="activeTab === 'pricing'">
        <div class="section-header">
          <h2>💲 Precios de Envío</h2>
          <p>Define cómo calcular los costos de envío para tus clientes.</p>
        </div>

        <div v-if="pricingLoading" class="loading-state">Cargando…</div>

        <!-- Summary -->
        <div v-else-if="!pricingEditing" class="summary-card">
          <h3>Tarifa Actual Establecida</h3>
          <div class="summary-stats">
            <div class="stat-row">
              <span class="label">Modelo de Cobro:</span>
              <span class="value badge">{{ pricingConfig.pricing_mode === 'distance' ? 'Distancia (Kilometraje)' : 'Zonas (Geocercas)' }}</span>
            </div>
            <template v-if="pricingConfig.pricing_mode === 'distance'">
              <div class="stat-row"><span class="label">Tarifa Base:</span><span class="value">${{ pricingConfig.base_fare.toFixed(2) }}</span></div>
              <div class="stat-row"><span class="label">Km mínimo incluido:</span><span class="value">{{ pricingConfig.min_distance_km.toFixed(1) }} km</span></div>
              <div class="stat-row"><span class="label">Costo por Km adicional:</span><span class="value">${{ pricingConfig.price_per_km.toFixed(2) }}</span></div>
            </template>
            <template v-else>
              <div class="stat-row"><span class="label">Zonas Configuradas:</span><span class="value">{{ zones.length }} Zonas activas</span></div>
            </template>
          </div>
          <p class="summary-hint">Los viajes se calculan automáticamente usando esta configuración.</p>
          <button class="btn-save" @click="enterPricingEditMode">✏️ Editar Tarifas</button>
        </div>

        <!-- Edit mode -->
        <div v-else class="content-split">
          <div class="config-panel">
            <h3>Tarifa por Distancia</h3>
            <div class="mode-config-box">
              <div class="form-group">
                <label>Tarifa Base ($)</label>
                <input type="number" step="0.5" min="0" v-model="pricingConfig.base_fare" placeholder="0.00">
              </div>
              <div class="form-group">
                <label>Km mínimo incluido</label>
                <input type="number" step="0.5" min="0" v-model="pricingConfig.min_distance_km" placeholder="0">
                <span class="field-hint">La tarifa base cubre los primeros {{ pricingConfig.min_distance_km || 0 }} km.</span>
              </div>
              <div class="form-group">
                <label>Precio por Km adicional ($)</label>
                <input type="number" step="0.5" min="0" v-model="pricingConfig.price_per_km" placeholder="0.00">
              </div>
            </div>

            <div class="action-btns">
              <button class="btn-save" @click="savePricingConfig" :disabled="pricingSaving">
                {{ pricingSaving ? 'Guardando...' : 'Guardar' }}
              </button>
              <button class="btn-cancel" @click="pricingEditing = false" :disabled="pricingSaving">Cancelar</button>
            </div>
          </div>
        </div>
      </section>

      <!-- ── Zonas ── -->
      <section class="config-section" v-show="activeTab === 'zones'">
        <div class="section-header">
          <h2>🗺️ Geocerca de Operación</h2>
          <p>Define el área geográfica donde tu flotilla puede operar. Solo se permite una geocerca activa.</p>
        </div>

        <div class="zones-layout">
          <div class="zone-panel">
            <div class="map-container-wrapper">
              <div id="zone-map" style="width:100%;height:580px;border-radius:12px;overflow:hidden"></div>
              <div class="map-help" v-if="!geofence">
                Usa la herramienta ⬟ del mapa para trazar el polígono, luego guárdalo.
              </div>
              <div class="map-help geofence-active" v-else>
                ✅ Geocerca activa. Elimínala para trazar una nueva.
              </div>
            </div>
          </div>

          <div class="zones-column">
            <h3>Geocerca Activa</h3>

            <!-- Sin geocerca -->
            <div v-if="!geofence">
              <div class="empty">
                <span class="empty-icon">🗺️</span>
                <p>No hay geocerca configurada.<br>Traza un polígono en el mapa.</p>
              </div>
              <div class="new-zone-form">
                <input type="text" v-model="newZoneName" placeholder="Nombre (Ej. Zona Centro)">
                <button class="btn-save save-zone-btn" @click="saveGeofence">+ Guardar</button>
              </div>
            </div>

            <!-- Con geocerca -->
            <div v-else>
              <div class="zone-item geofence-item">
                <div class="z-color-dot active"></div>
                <div class="z-info">
                  <strong>{{ geofence.name }}</strong>
                  <small>Activa</small>
                </div>
                <button class="btn-danger btn-sm" @click="deleteGeofence" title="Eliminar geocerca">✕</button>
              </div>
              <p class="zones-column-hint" style="margin-top:1rem;">
                Para cambiar el área, elimina la geocerca actual y traza una nueva.
              </p>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</template>

<style scoped>
.config-page { padding: 2rem; max-width: 900px; }
.config-page.full-width { max-width: 100%; }

.config-header { margin-bottom: 1.5rem; }

.tabs {
  display: flex;
  gap: 0.25rem;
  border-bottom: 2px solid var(--border-light);
  margin-bottom: 2rem;
}

.tab-btn {
  padding: 0.65rem 1.25rem;
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -2px;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--text-muted);
  cursor: pointer;
  transition: color 0.2s, border-color 0.2s;
  border-radius: 6px 6px 0 0;
}

.tab-btn:hover { color: var(--text-main); background: var(--bg-app); }

.tab-btn.active {
  color: #6366f1;
  border-bottom-color: #6366f1;
  background: none;
}

.config-header { margin-bottom: 2rem; }
.config-header h1 { font-size: 1.75rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.25rem; }
.config-header p  { color: var(--text-muted); font-size: 0.95rem; }

.sections { display: flex; flex-direction: column; gap: 2.5rem; }

.section-header { margin-bottom: 1rem; }
.section-header h2 { font-size: 1.15rem; font-weight: 700; color: var(--text-main); margin-bottom: 0.2rem; }
.section-header p  { color: var(--text-muted); font-size: 0.875rem; }

.loading-state { color: var(--text-muted); font-size: 0.95rem; }

/* ── Billing card ── */
.card { background: white; border: 1px solid var(--border-light); border-radius: 14px; padding: 1.75rem; display: flex; flex-direction: column; gap: 1.5rem; }
.field-group { display: flex; flex-direction: column; gap: 0.5rem; }
.field-label  { font-size: 0.875rem; font-weight: 600; color: var(--text-main); }
.field-group small { font-size: 0.8rem; color: var(--text-muted); }
.schema-options { display: flex; flex-direction: column; gap: 0.75rem; }
.schema-option { display: flex; align-items: center; gap: 0.75rem; border: 2px solid var(--border-light); border-radius: 10px; padding: 1rem; cursor: pointer; transition: border-color 0.15s, background 0.15s; }
.schema-option input[type="radio"] { display: none; }
.schema-option.selected { border-color: #6366f1; background: #f5f3ff; }
.option-content { display: flex; align-items: flex-start; gap: 0.75rem; }
.option-icon { font-size: 1.5rem; line-height: 1; }
.option-content strong { font-size: 0.95rem; display: block; color: var(--text-main); }
.option-content p { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem; }
.input-prefix { display: flex; align-items: center; border: 1px solid var(--border-light); border-radius: 8px; overflow: hidden; }
.input-prefix span { padding: 0 0.75rem; background: #f9fafb; border-right: 1px solid var(--border-light); font-size: 0.9rem; color: var(--text-muted); line-height: 2.5rem; }
.input-prefix input { flex: 1; padding: 0.6rem 0.75rem; border: none; outline: none; font-size: 0.95rem; }
.feedback { padding: 0.75rem 1rem; border-radius: 8px; font-size: 0.875rem; }
.feedback.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.feedback.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* ── Shared button ── */
.btn-save { padding: 0.75rem 1.5rem; background: #6366f1; color: white; border: none; border-radius: 9px; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: opacity 0.2s; align-self: flex-start; }
.btn-save:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-save:not(:disabled):hover { opacity: 0.88; }

/* ── Pricing summary ── */
.summary-card { background: white; border-radius: 16px; padding: 2rem; max-width: 500px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border-light); }
.summary-card h3 { font-size: 1.2rem; margin-bottom: 1.5rem; font-weight: 700; color: #1F2937; }
.summary-stats { display: flex; flex-direction: column; gap: 1rem; background: #F9FAFB; padding: 1.5rem; border-radius: 12px; margin-bottom: 1.5rem; }
.stat-row { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px dashed #E5E7EB; padding-bottom: 0.5rem; }
.stat-row:last-child { border-bottom: none; padding-bottom: 0; }
.stat-row .label { color: #4B5563; font-weight: 600; font-size: 0.95rem; }
.stat-row .value { font-weight: 700; font-size: 1.1rem; color: #111827; }
.stat-row .badge { background: #E0E7FF; color: #4338CA; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.85rem; }
.summary-hint { font-size: 0.85rem; color: #6B7280; margin-bottom: 1.5rem; }

/* ── Pricing edit ── */
.content-split { display: grid; grid-template-columns: 260px 1fr; gap: 1.5rem; align-items: start; }
.zones-layout { display: grid; grid-template-columns: 1fr 280px; gap: 1.5rem; align-items: start; width: 100%; }
.config-panel, .zone-panel { background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border-light); }
.config-panel h3, .zone-panel h3 { font-size: 1.1rem; margin-bottom: 1.25rem; font-weight: 700; }
.form-group { margin-bottom: 1.25rem; }
.form-group label { display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.5rem; color: #374151; }
.form-group input, .form-group select { width: 100%; padding: 0.65rem; border: 1px solid #D1D5DB; border-radius: 8px; font-family: inherit; }
.mode-config-box { background: #F9FAFB; padding: 1rem; border-radius: 8px; border: 1px dashed #D1D5DB; }
.mode-config-box h4 { font-size: 0.9rem; margin-bottom: 1rem; font-weight: 600; }
.action-btns { display: flex; flex-direction: column; gap: 0.6rem; margin-top: 1.25rem; }
.btn-cancel { background: white; color: #374151; padding: 0.7rem 1rem; border-radius: 8px; border: 1px solid #D1D5DB; font-weight: 600; cursor: pointer; transition: all 0.2s; width: 100%; }
.btn-cancel:hover { background: #F3F4F6; }
.map-container-wrapper { position: relative; border: 1px solid #D1D5DB; border-radius: 12px; margin-bottom: 1rem; }
.map-help { padding: 0.5rem 1rem; font-size: 0.75rem; color: #6B7280; text-align: center; background: #F3F4F6; border-bottom-left-radius: 12px; border-bottom-right-radius: 12px; }
.map-help.geofence-active { background: #D1FAE5; color: #065F46; font-weight: 600; }
.new-zone-form { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--border-light); }
.new-zone-form input { width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #D1D5DB; border-radius: 6px; font-family: inherit; font-size: 0.875rem; box-sizing: border-box; }
.save-zone-btn { white-space: nowrap; }
.field-hint { display: block; font-size: 0.75rem; color: #6B7280; margin-top: 0.3rem; }
.rules-info ul { padding-left: 1rem; margin-top: 0.5rem; font-size: 0.82rem; color: #4B5563; line-height: 1.7; }
.zones-column { background: white; border-radius: 16px; padding: 1.5rem; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid var(--border-light); position: sticky; top: 1rem; }
.zones-column h3 { font-size: 1.1rem; font-weight: 700; margin-bottom: 0.25rem; }
.zones-column-hint { font-size: 0.8rem; color: #6B7280; margin-bottom: 1rem; }
.zones-list { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.5rem; }
.empty { display: flex; flex-direction: column; align-items: center; gap: 0.5rem; padding: 2rem 1rem; color: #9CA3AF; font-size: 0.85rem; text-align: center; }
.empty-icon { font-size: 2rem; }
.zone-item { display: flex; align-items: center; gap: 0.6rem; padding: 0.65rem 0.75rem; background: #F9FAFB; border-radius: 8px; border: 1px solid #E5E7EB; transition: border-color 0.15s; }
.zone-item:hover { border-color: #A5B4FC; }
.geofence-item { border-color: #A7F3D0; background: #F0FDF4; }
.z-color-dot { width: 10px; height: 10px; border-radius: 50%; background: #10B981; flex-shrink: 0; }
.z-color-dot.active { background: #10B981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2); }
.z-info small { display: block; font-size: 0.7rem; color: #059669; font-weight: 600; }
.z-info { flex: 1; min-width: 0; }
.z-info strong { display: block; color: #1F2937; font-size: 0.875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.z-info .z-price { font-size: 0.78rem; color: #10B981; font-weight: 700; }
.btn-sm { padding: 0.2rem 0.45rem; font-size: 0.75rem; border-radius: 4px; line-height: 1.4; }
.btn-danger { background: #FEE2E2; color: #DC2626; border: none; cursor: pointer; flex-shrink: 0; }
.btn-danger:hover { background: #FECACA; }

@media (max-width: 900px) {
    .content-split, .content-split.three-cols { grid-template-columns: 1fr; }
}
</style>
