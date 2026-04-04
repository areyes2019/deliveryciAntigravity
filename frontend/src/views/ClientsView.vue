<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const clients = ref([])
const loading = ref(true)
const showModal = ref(false)
const showCreditsModal = ref(false)
const editingClient = ref(null)
const selectedClientId = ref(null)
const creditsAmount = ref(0)

const form = ref({
  name: '',
  email: '',
  password: '',
  business_name: '',
  cost_per_trip: 10.0
})

const fetchClients = async () => {
  loading.value = true
  try {
    const response = await api.get('/clients')
    if (response.data.status) {
      clients.value = response.data.data
    }
  } catch (error) {
    console.error('Error fetching clients:', error)
  } finally {
    loading.value = false
  }
}

const openModal = (client = null) => {
  if (client) {
    editingClient.value = client
    form.value = {
      name: client.admin_name,
      email: client.admin_email,
      password: '',
      business_name: client.business_name,
      cost_per_trip: client.cost_per_trip
    }
  } else {
    editingClient.value = null
    form.value = {
      name: '',
      email: '',
      password: '',
      business_name: '',
      cost_per_trip: 10.0
    }
  }
  showModal.value = true
}

const closeModal = () => {
  showModal.value = false
  editingClient.value = null
}

const saveClient = async () => {
  try {
    let response
    if (editingClient.value) {
      response = await api.put(`/clients/${editingClient.value.id}`, form.value)
    } else {
      response = await api.post('/clients', form.value)
    }

    if (response.data.status) {
      fetchClients()
      closeModal()
    }
  } catch (error) {
    console.error('Error saving client:', error)
    if (error.response?.data?.errors) {
      const errors = error.response.data.errors
      const errorMsg = Object.values(errors).join('\n')
      alert(`La validación falló:\n${errorMsg}`)
    } else {
      alert(error.response?.data?.message || 'Error al guardar el cliente')
    }
  }
}

const deleteClient = async (id) => {
  if (!confirm('¿Estás seguro de que deseas eliminar este cliente? Esto también eliminará al usuario administrador.')) return
  
  try {
    const response = await api.delete(`/clients/${id}`)
    if (response.data.status) {
      fetchClients()
    }
  } catch (error) {
    console.error('Error deleting client:', error)
  }
}

const openCreditsModal = (clientId) => {
  selectedClientId.value = clientId
  creditsAmount.value = 0
  showCreditsModal.value = true
}

const addCredits = async () => {
  if (creditsAmount.value <= 0) return
  
  try {
    const response = await api.post(`/clients/${selectedClientId.value}/add-credits`, {
      amount: creditsAmount.value
    })
    if (response.data.status) {
      fetchClients()
      showCreditsModal.value = false
    }
  } catch (error) {
    console.error('Error adding credits:', error)
  }
}

onMounted(fetchClients)
</script>

<template>
  <div class="clients-view">
    <div class="page-header">
      <div class="header-content">
        <h1>Gestión de Clientes</h1>
        <p>Administra las empresas y sus saldos de crédito.</p>
      </div>
      <button class="btn-primary" @click="openModal()">
        <span class="icon">+</span> Nuevo Cliente
      </button>
    </div>

    <!-- Estado de Carga -->
    <div v-if="loading" class="loading-container">
      <div class="spinner"></div>
    </div>

    <!-- Tabla de Clientes -->
    <div v-else class="table-container">
      <table class="custom-table">
        <thead>
          <tr>
            <th>Nombre de la Empresa</th>
            <th>Administrador</th>
            <th class="text-center">Saldo</th>
            <th class="text-center">Precio/Viaje</th>
            <th class="text-center">Viajes Disp.</th>
            <th class="text-right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="client in clients" :key="client.id">
            <td>
              <div class="business-info">
                <span class="business-name">{{ client.business_name }}</span>
                <span class="uuid">UUID: {{ client.uuid }}</span>
              </div>
            </td>
            <td>
              <div class="admin-info">
                <span class="admin-name">{{ client.admin_name }}</span>
                <span class="admin-email">{{ client.admin_email }}</span>
              </div>
            </td>
            <td class="text-center">
              <span class="balance-badge" :class="client.credits_balance > 0 ? 'positive' : 'empty'">
                ${{ client.credits_balance }}
              </span>
            </td>
            <td class="text-center">
              <span class="cost-value">${{ client.cost_per_trip }}</span>
            </td>
            <td class="text-center">
              <span class="trips-badge">
                {{ Math.floor(client.credits_balance / client.cost_per_trip) }} Viajes
              </span>
            </td>
            <td>
              <div class="actions-group">
                <button class="action-btn credit" @click="openCreditsModal(client.id)" title="Recargar Saldo">💰</button>
                <button class="action-btn edit" @click="openModal(client)" title="Editar">✏️</button>
                <button class="action-btn delete" @click="deleteClient(client.id)" title="Eliminar">🗑️</button>
              </div>
            </td>
          </tr>
          <tr v-if="clients.length === 0">
            <td colspan="5" class="empty-row">
               No hay clientes registrados. Haz clic en "Nuevo Cliente" para empezar.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal de Formulario de Cliente -->
    <div v-if="showModal" class="modal-overlay">
      <div class="modal-content">
        <div class="modal-header">
          <h2>{{ editingClient ? 'Editar Cliente' : 'Nuevo Cliente' }}</h2>
          <button @click="closeModal" class="close-btn">&times;</button>
        </div>
        <form @submit.prevent="saveClient" class="modal-body">
          <div class="form-group">
            <label>Nombre de la Empresa</label>
            <input v-model="form.business_name" type="text" placeholder="Ej. Pizza Palace" required>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label>Nombre del Administrador</label>
              <input v-model="form.name" type="text" placeholder="Nombre completo" required>
            </div>
            <div class="form-group">
              <label>Correo Electrónico</label>
              <input v-model="form.email" type="email" placeholder="admin@empresa.com" required>
            </div>
          </div>
          <div class="form-group" v-if="!editingClient">
            <label>Contraseña</label>
            <input v-model="form.password" type="password" placeholder="Mínimo 6 caracteres" required>
          </div>
          <div class="form-group">
            <label>Precio por Viaje ($)</label>
            <input v-model="form.cost_per_trip" type="number" step="0.01" required>
          </div>
          <div class="modal-footer">
            <button type="button" @click="closeModal" class="btn-secondary">Cancelar</button>
            <button type="submit" class="btn-primary">
              {{ editingClient ? 'Actualizar' : 'Crear Cliente' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal de Créditos -->
    <div v-if="showCreditsModal" class="modal-overlay">
      <div class="modal-content sm">
        <div class="modal-header">
          <h2>Recargar Créditos</h2>
          <button @click="showCreditsModal = false" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Monto a Recargar ($)</label>
            <input v-model="creditsAmount" type="number" min="1" step="1" required>
          </div>
        </div>
        <div class="modal-footer">
          <button @click="showCreditsModal = false" class="btn-secondary">Cancelar</button>
          <button @click="addCredits" class="btn-primary">Aplicar Recarga</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.clients-view {
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
.business-info, .admin-info {
  display: flex;
  flex-direction: column;
}

.business-name, .admin-name {
  font-weight: 600;
  color: var(--text-main);
  font-size: 0.95rem;
}

.uuid, .admin-email {
  font-size: 0.75rem;
  color: var(--text-light);
}

.text-center { text-align: center; }
.text-right { text-align: right; }

.balance-badge {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 999px;
  font-size: 0.85rem;
  font-weight: 700;
}

.balance-badge.positive {
  background-color: #DCFCE7;
  color: #166534;
}

.balance-badge.empty {
  background-color: #FEE2E2;
  color: #991B1B;
}

.cost-value {
  font-weight: 500;
  color: var(--text-muted);
}

.trips-badge {
  background-color: #EEF2FF;
  color: #3730A3;
  padding: 0.25rem 0.5rem;
  border-radius: 6px;
  font-weight: 700;
  font-size: 0.85rem;
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

/* Modal and Forms (keep as is but ensure consistency) */
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

.modal-content.sm { max-width: 400px; }

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
