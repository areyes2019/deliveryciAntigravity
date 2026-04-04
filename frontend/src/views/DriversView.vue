<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const drivers = ref([])
const loading = ref(true)
const showModal = ref(false)
const editingDriver = ref(null)

const form = ref({
  name: '',
  email: '',
  password: '',
  phone: '',
  vehicle_details: '',
  is_suspended: 0
})

const fetchDrivers = async () => {
  loading.value = true
  try {
    const response = await api.get('/drivers')
    if (response.data.status) {
      drivers.value = response.data.data
    }
  } catch (error) {
    console.error('Error fetching drivers:', error)
  } finally {
    loading.value = false
  }
}

const openModal = (driver = null) => {
  if (driver) {
    editingDriver.value = driver
    form.value = {
      name: driver.name,
      email: driver.email,
      password: '',
      phone: driver.phone,
      vehicle_details: driver.vehicle_details,
      is_suspended: parseInt(driver.is_suspended)
    }
  } else {
    editingDriver.value = null
    form.value = {
      name: '',
      email: '',
      password: '',
      phone: '',
      vehicle_details: '',
      is_suspended: 0
    }
  }
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  editingDriver.value = null
}

const saveDriver = async () => {
  try {
    let response
    if (editingDriver.value) {
      response = await api.put(`/drivers/${editingDriver.value.id}`, form.value)
    } else {
      response = await api.post('/drivers', form.value)
    }

    if (response.data.status) {
      fetchDrivers()
      closeModal()
    }
  } catch (error) {
    console.error('Error saving driver:', error)
    alert(error.response?.data?.message || 'Error al guardar el conductor')
  }
}

const deleteDriver = async (id) => {
  if (!confirm('¿Estás seguro de que deseas eliminar a este conductor?')) return
  
  try {
    const response = await api.delete(`/drivers/${id}`)
    if (response.data.status) {
      fetchDrivers()
    }
  } catch (error) {
    console.error('Error deleting driver:', error)
  }
}

onMounted(fetchDrivers)
</script>

<template>
  <div class="drivers-view">
    <div class="page-header">
      <div class="header-content">
        <h1>Gestión de Conductores</h1>
        <p>Administra tu flota de reparto y los detalles de sus vehículos.</p>
      </div>
      <button class="btn-primary" @click="openModal()">
        <span class="icon">+</span> Nuevo Conductor
      </button>
    </div>

    <!-- Estado de Carga -->
    <div v-if="loading" class="loading-container">
      <div class="spinner"></div>
    </div>

    <!-- Tabla de Conductores -->
    <div v-else class="table-container">
      <table class="custom-table">
        <thead>
          <tr>
            <th>Conductor</th>
            <th>Contacto</th>
            <th>Vehículo</th>
            <th class="text-center">Estado</th>
            <th class="text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="driver in drivers" :key="driver.id">
            <td>
              <div class="driver-info">
                <span class="driver-name">{{ driver.name }}</span>
                <span class="driver-id">ID: {{ driver.id }}</span>
              </div>
            </td>
            <td>
              <div class="contact-info">
                <span class="email">{{ driver.email }}</span>
                <span class="phone">Tel: {{ driver.phone }}</span>
              </div>
            </td>
            <td>
              <span class="vehicle-details">{{ driver.vehicle_details }}</span>
            </td>
            <td class="text-center">
              <span v-if="driver.is_suspended == 1" class="status-badge suspended">
                Suspendido
              </span>
              <span v-else class="status-badge active">
                Activo
              </span>
            </td>
            <td>
              <div class="actions-group">
                <button class="action-btn edit" @click="openModal(driver)" title="Editar">✏️</button>
                <button class="action-btn delete" @click="deleteDriver(driver.id)" title="Eliminar">🗑️</button>
              </div>
            </td>
          </tr>
          <tr v-if="drivers.length === 0">
            <td colspan="5" class="empty-row">
               No hay conductores registrados. Haz clic en "Nuevo Conductor" para empezar.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal de Formulario de Conductor -->
    <div v-if="showModal" class="modal-overlay">
      <div class="modal-content">
        <div class="modal-header">
          <h2>{{ editingDriver ? 'Editar Conductor' : 'Nuevo Conductor' }}</h2>
          <button @click="closeModal" class="close-btn">&times;</button>
        </div>
        <form @submit.prevent="saveDriver" class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label>Nombre Completo</label>
              <input v-model="form.name" type="text" placeholder="Nombre completo" required>
            </div>
            <div class="form-group">
              <label>Teléfono</label>
              <input v-model="form.phone" type="text" placeholder="+52..." required>
            </div>
          </div>
          <div class="form-group">
            <label>Correo Electrónico</label>
            <input v-model="form.email" type="email" placeholder="conductor@ejemplo.com" required>
          </div>
          <div class="form-group" v-if="!editingDriver">
            <label>Contraseña por Defecto</label>
            <input v-model="form.password" type="password" placeholder="Mínimo 6 caracteres" required>
          </div>
          <div class="form-group">
            <label>Detalles del Vehículo (Placa, Modelo)</label>
            <input v-model="form.vehicle_details" type="text" placeholder="Ej. Honda Civic ABC-123" required>
          </div>
          <div class="form-group checkbox-group" v-if="editingDriver">
            <label class="checkbox-label">
              <input v-model="form.is_suspended" type="checkbox" :true-value="1" :false-value="0">
              Suspender Conductor
            </label>
          </div>
          <div class="modal-footer">
            <button type="button" @click="closeModal" class="btn-secondary">Cancelar</button>
            <button type="submit" class="btn-primary">
              {{ editingDriver ? 'Actualizar' : 'Crear Conductor' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<style scoped>
.drivers-view {
  display: flex;
  flex-direction: column;
}

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2rem;
}

.header-content h1 {
  font-size: 1.75rem;
  font-weight: 700;
  margin-bottom: 0.25rem;
}

.header-content p {
  color: var(--text-muted);
  font-size: 0.95rem;
}

/* Table Styles */
.table-container {
  background: white;
  border-radius: var(--radius-lg);
  border: 1px solid var(--border-light);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.custom-table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.custom-table th {
  background-color: #F9FAFB;
  padding: 1rem 1.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--text-muted);
  border-bottom: 1px solid var(--border-light);
}

.custom-table td {
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid #F3F4F6;
  vertical-align: middle;
}

.custom-table tr:last-child td {
  border-bottom: none;
}

.custom-table tr:hover {
  background-color: #F9FAFB;
}

/* Cell Content Styles */
.driver-info, .contact-info {
  display: flex;
  flex-direction: column;
}

.driver-name {
  font-weight: 600;
  color: var(--text-main);
  font-size: 0.95rem;
}

.driver-id, .phone {
  font-size: 0.75rem;
  color: var(--text-light);
}

.email {
  font-size: 0.875rem;
  color: var(--text-main);
}

.vehicle-details {
  font-size: 0.9rem;
  color: var(--text-muted);
  background: #F3F4F6;
  padding: 0.25rem 0.5rem;
  border-radius: 6px;
}

.text-center { text-align: center; }
.text-right { text-align: right; }

.status-badge {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 700;
}

.status-badge.active {
  background-color: #DCFCE7;
  color: #166534;
}

.status-badge.suspended {
  background-color: #FEE2E2;
  color: #991B1B;
}

/* Actions */
.actions-group {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

.action-btn {
  width: 36px;
  height: 36px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  border: 1px solid var(--border-light);
  background: white;
  cursor: pointer;
  transition: all 0.2s;
  font-size: 1.1rem;
}

.action-btn:hover {
  background-color: #F3F4F6;
  transform: translateY(-1px);
}

.action-btn.delete:hover {
  background-color: #FEE2E2;
  color: #DC2626;
  border-color: #FCA5A5;
}

.empty-row {
  padding: 4rem !important;
  text-align: center;
  color: var(--text-light);
}

/* Modal and Forms */
.modal-overlay {
  position: fixed;
  top: 0; left: 0; right: 0; bottom: 0;
  background-color: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(4px);
}

.modal-content {
  background: white;
  width: 100%;
  max-width: 550px;
  border-radius: var(--radius-lg);
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.modal-header {
  padding: 1.5rem;
  border-bottom: 1px solid var(--border-light);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-body { padding: 1.5rem; }

.modal-footer {
  padding: 1.25rem 1.5rem;
  background-color: #F9FAFB;
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  border-radius: 0 0 var(--radius-lg) var(--radius-lg);
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.form-group { margin-bottom: 1.25rem; }

.form-group label {
  display: block;
  font-size: 0.875rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.form-group input {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid var(--border-light);
  border-radius: 8px;
  outline: none;
}

.form-group input:focus { border-color: var(--primary); }

.checkbox-group {
  display: flex;
  align-items: center;
}

.checkbox-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font-weight: 500 !important;
}

.loading-container {
  display: flex;
  justify-content: center;
  padding: 4rem;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid #F3F4F6;
  border-top: 4px solid var(--primary);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
