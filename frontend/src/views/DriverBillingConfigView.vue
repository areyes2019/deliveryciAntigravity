<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'

// --- State ---
const loading  = ref(true)
const saving   = ref(false)
const feedback = ref({ type: '', message: '' })

const form = ref({
    tipo_esquema:        'credito',
    precio_credito:      '',
    porcentaje_comision: '',
})

// --- Fetch existing config ---
const fetchConfig = async () => {
    try {
        const res = await api.get('/driver-billing')
        if (res.data.status && res.data.data?.tipo_esquema) {
            const c = res.data.data
            form.value.tipo_esquema        = c.tipo_esquema
            form.value.precio_credito      = c.precio_credito      ?? ''
            form.value.porcentaje_comision = c.porcentaje_comision ?? ''
        }
    } catch (e) {
        console.error('Error fetching billing config:', e)
    } finally {
        loading.value = false
    }
}

// --- Save ---
const saveConfig = async () => {
    feedback.value = { type: '', message: '' }

    // Client-side validation mirrors backend rules
    if (form.value.tipo_esquema === 'credito') {
        const v = parseFloat(form.value.precio_credito)
        if (!v || v <= 0) {
            feedback.value = { type: 'error', message: 'Ingresa un precio de crédito válido mayor a 0.' }
            return
        }
    } else {
        const v = parseFloat(form.value.porcentaje_comision)
        if (!v || v <= 0 || v > 100) {
            feedback.value = { type: 'error', message: 'Ingresa un porcentaje válido entre 1 y 100.' }
            return
        }
    }

    saving.value = true
    try {
        const payload = { tipo_esquema: form.value.tipo_esquema }

        if (form.value.tipo_esquema === 'credito') {
            payload.precio_credito = parseFloat(form.value.precio_credito)
        } else {
            payload.porcentaje_comision = parseFloat(form.value.porcentaje_comision)
        }

        const res = await api.put('/driver-billing', payload)
        feedback.value = {
            type: res.data.status ? 'success' : 'error',
            message: res.data.message,
        }
    } catch (e) {
        feedback.value = { type: 'error', message: e.response?.data?.message || 'Error al guardar.' }
    } finally {
        saving.value = false
    }
}

// Reset the irrelevant field when schema type changes
const onSchemaChange = () => {
    form.value.precio_credito      = ''
    form.value.porcentaje_comision = ''
    feedback.value = { type: '', message: '' }
}

const isCredito     = computed(() => form.value.tipo_esquema === 'credito')
const isPorcentaje  = computed(() => form.value.tipo_esquema === 'porcentaje')

onMounted(fetchConfig)
</script>

<template>
  <div class="billing-page">

    <div class="page-header">
      <h1>Cobro a Conductores</h1>
      <p>Define cómo se le cobra a tu flotilla por cada viaje completado.</p>
    </div>

    <div v-if="loading" class="loading-state">Cargando configuración…</div>

    <div v-else class="card">

      <!-- Tipo de esquema -->
      <div class="field-group">
        <label class="field-label">Tipo de esquema</label>
        <div class="schema-options">
          <label class="schema-option" :class="{ selected: isCredito }">
            <input
              type="radio"
              value="credito"
              v-model="form.tipo_esquema"
              @change="onSchemaChange"
            />
            <div class="option-content">
              <span class="option-icon">🪙</span>
              <div>
                <strong>Crédito</strong>
                <p>Se descuenta un monto fijo del monedero por cada viaje.</p>
              </div>
            </div>
          </label>

          <label class="schema-option" :class="{ selected: isPorcentaje }">
            <input
              type="radio"
              value="porcentaje"
              v-model="form.tipo_esquema"
              @change="onSchemaChange"
            />
            <div class="option-content">
              <span class="option-icon">📊</span>
              <div>
                <strong>Porcentaje</strong>
                <p>Se retiene un % del ingreso del conductor por cada viaje.</p>
              </div>
            </div>
          </label>
        </div>
      </div>

      <!-- Campo dinámico -->
      <div class="field-group" v-if="isCredito">
        <label class="field-label" for="precio_credito">Precio por viaje (crédito)</label>
        <div class="input-prefix">
          <span>$</span>
          <input
            id="precio_credito"
            type="number"
            min="0.01"
            step="0.01"
            placeholder="Ej. 15.00"
            v-model="form.precio_credito"
          />
        </div>
        <small>Monto que se descontará del saldo del conductor al completar un viaje.</small>
      </div>

      <div class="field-group" v-if="isPorcentaje">
        <label class="field-label" for="porcentaje_comision">Comisión por viaje (%)</label>
        <div class="input-prefix">
          <span>%</span>
          <input
            id="porcentaje_comision"
            type="number"
            min="1"
            max="100"
            step="0.01"
            placeholder="Ej. 20"
            v-model="form.porcentaje_comision"
          />
        </div>
        <small>Porcentaje del valor del viaje que retiene la empresa.</small>
      </div>

      <!-- Feedback -->
      <div v-if="feedback.message" class="feedback" :class="feedback.type">
        {{ feedback.message }}
      </div>

      <!-- Guardar -->
      <button class="btn-save" :disabled="saving" @click="saveConfig">
        {{ saving ? 'Guardando…' : 'Guardar configuración' }}
      </button>

    </div>
  </div>
</template>

<style scoped>
.billing-page {
  padding: 2rem;
  max-width: 640px;
}

.page-header { margin-bottom: 1.5rem; }
.page-header h1 { font-size: 1.5rem; font-weight: 700; color: var(--text-main); }
.page-header p  { color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem; }

.loading-state {
  color: var(--text-muted);
  font-size: 0.95rem;
}

/* Card */
.card {
  background: white;
  border: 1px solid var(--border-light);
  border-radius: 14px;
  padding: 1.75rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

/* Field group */
.field-group { display: flex; flex-direction: column; gap: 0.5rem; }
.field-label  { font-size: 0.875rem; font-weight: 600; color: var(--text-main); }
.field-group small { font-size: 0.8rem; color: var(--text-muted); }

/* Schema radio options */
.schema-options {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.schema-option {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border: 2px solid var(--border-light);
  border-radius: 10px;
  padding: 1rem;
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}

.schema-option input[type="radio"] { display: none; }

.schema-option.selected {
  border-color: #6366f1;
  background: #f5f3ff;
}

.option-content {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.option-icon { font-size: 1.5rem; line-height: 1; }

.option-content strong { font-size: 0.95rem; display: block; color: var(--text-main); }
.option-content p      { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem; }

/* Input with prefix */
.input-prefix {
  display: flex;
  align-items: center;
  border: 1px solid var(--border-light);
  border-radius: 8px;
  overflow: hidden;
}

.input-prefix span {
  padding: 0 0.75rem;
  background: #f9fafb;
  border-right: 1px solid var(--border-light);
  font-size: 0.9rem;
  color: var(--text-muted);
  line-height: 2.5rem;
}

.input-prefix input {
  flex: 1;
  padding: 0.6rem 0.75rem;
  border: none;
  outline: none;
  font-size: 0.95rem;
}

/* Feedback */
.feedback {
  padding: 0.75rem 1rem;
  border-radius: 8px;
  font-size: 0.875rem;
}
.feedback.success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.feedback.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

/* Button */
.btn-save {
  padding: 0.75rem 1.5rem;
  background: #6366f1;
  color: white;
  border: none;
  border-radius: 9px;
  font-weight: 600;
  font-size: 0.95rem;
  cursor: pointer;
  transition: opacity 0.2s;
  align-self: flex-start;
}
.btn-save:disabled { opacity: 0.6; cursor: not-allowed; }
.btn-save:not(:disabled):hover { opacity: 0.88; }
</style>
