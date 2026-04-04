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
  cost_per_km: 0.5
})

const fetchClients = async () => {
  loading.ref = true
  try {
    const response = await api.get('/api/v1/clients')
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
      cost_per_km: client.cost_per_km
    }
  } else {
    editingClient.value = null
    form.value = {
      name: '',
      email: '',
      password: '',
      business_name: '',
      cost_per_km: 0.5
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
      response = await api.put(`/api/v1/clients/${editingClient.value.id}`, form.value)
    } else {
      response = await api.post('/api/v1/clients', form.value)
    }

    if (response.data.status) {
      fetchClients()
      closeModal()
    }
  } catch (error) {
    console.error('Error saving client:', error)
    alert(error.response?.data?.message || 'Error saving client')
  }
}

const deleteClient = async (id) => {
  if (!confirm('Are you sure you want to delete this client? This will also delete the admin user.')) return
  
  try {
    const response = await api.delete(`/api/v1/clients/${id}`)
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
    const response = await api.post(`/api/v1/clients/${selectedClientId.value}/add-credits`, {
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
      <div>
        <h1 class="text-2xl font-bold text-gray-800">Clients Management</h1>
        <p class="text-sm text-gray-500">Manage business tenants and their credit balances.</p>
      </div>
      <button class="btn-primary" @click="openModal()">
        <span class="icon">+</span> Add New Client
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center p-12">
      <div class="spinner"></div>
    </div>

    <!-- Clients Table -->
    <div v-else class="mt-8 overflow-hidden bg-white border border-gray-200 rounded-xl shadow-sm">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-50 border-bottom">
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Business Name</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Admin</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Balance</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Cost/KM</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="client in clients" :key="client.id" class="hover:bg-gray-50 transition-colors">
            <td class="px-6 py-4">
              <div class="font-semibold text-gray-900">{{ client.business_name }}</div>
              <div class="text-xs text-gray-400">UUID: {{ client.uuid }}</div>
            </td>
            <td class="px-6 py-4">
              <div class="text-sm text-gray-700">{{ client.admin_name }}</div>
              <div class="text-xs text-gray-400">{{ client.admin_email }}</div>
            </td>
            <td class="px-6 py-4">
              <span class="px-2 py-1 text-xs font-bold rounded-full" :class="client.credits_balance > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                ${{ client.credits_balance }}
              </span>
            </td>
            <td class="px-6 py-4 text-sm text-gray-600">${{ client.cost_per_km }}</td>
            <td class="px-6 py-4">
              <div class="flex gap-2">
                <button class="btn-icon" @click="openCreditsModal(client.id)" title="Add Credits">💰</button>
                <button class="btn-icon" @click="openModal(client)" title="Edit">✏️</button>
                <button class="btn-icon delete" @click="deleteClient(client.id)" title="Delete">🗑️</button>
              </div>
            </td>
          </tr>
          <tr v-if="clients.length === 0">
            <td colspan="5" class="px-6 py-12 text-center text-gray-400">
               No clients found. Click "Add New Client" to get started.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Client Form Modal -->
    <div v-if="showModal" class="modal-overlay">
      <div class="modal-content">
        <div class="modal-header">
          <h2>{{ editingClient ? 'Edit Client' : 'New Client' }}</h2>
          <button @click="closeModal" class="close-btn">&times;</button>
        </div>
        <form @submit.prevent="saveClient" class="modal-body">
          <div class="form-group">
            <label>Business Name</label>
            <input v-model="form.business_name" type="text" placeholder="e.g. Pizza Palace" required>
          </div>
          <div class="form-grid">
            <div class="form-group">
              <label>Admin Name</label>
              <input v-model="form.name" type="text" placeholder="Full name" required>
            </div>
            <div class="form-group">
              <label>Email Address</label>
              <input v-model="form.email" type="email" placeholder="admin@example.com" required>
            </div>
          </div>
          <div class="form-group" v-if="!editingClient">
            <label>Password</label>
            <input v-model="form.password" type="password" placeholder="Minimum 6 characters" required>
          </div>
          <div class="form-group">
            <label>Cost Per KM ($)</label>
            <input v-model="form.cost_per_km" type="number" step="0.01" required>
          </div>
          <div class="modal-footer">
            <button type="button" @click="closeModal" class="btn-secondary">Cancel</button>
            <button type="submit" class="btn-primary">
              {{ editingClient ? 'Update Client' : 'Create Client' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Credits Modal -->
    <div v-if="showCreditsModal" class="modal-overlay">
      <div class="modal-content sm">
        <div class="modal-header">
          <h2>Add Credits</h2>
          <button @click="showCreditsModal = false" class="close-btn">&times;</button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Amount to Recharge ($)</label>
            <input v-model="creditsAmount" type="number" min="1" step="1" required>
          </div>
        </div>
        <div class="modal-footer">
          <button @click="showCreditsModal = false" class="btn-secondary">Cancel</button>
          <button @click="addCredits" class="btn-primary">Apply Recharge</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 2rem;
}

.btn-icon {
  background: none;
  border: 1px solid var(--border-light);
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s;
}

.btn-icon:hover {
  background-color: var(--bg-app);
}

.btn-icon.delete:hover {
  background-color: #FEE2E2;
  color: #DC2626;
  border-color: #FCA5A5;
}

/* Modal Styles */
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: rgba(0, 0, 0, 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(4px);
}

.modal-content {
  background: white;
  width: 100%;
  max-width: 600px;
  border-radius: 12px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

.modal-content.sm {
  max-width: 400px;
}

.modal-header {
  padding: 1.5rem;
  border-bottom: 1px solid var(--border-light);
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-header h2 {
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--text-main);
}

.close-btn {
  background: none;
  border: none;
  font-size: 1.5rem;
  color: var(--text-muted);
  cursor: pointer;
}

.modal-body {
  padding: 1.5rem;
}

.modal-footer {
  padding: 1.25rem 1.5rem;
  background-color: var(--bg-app);
  display: flex;
  justify-content: flex-end;
  gap: 1rem;
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
}

.form-group {
  margin-bottom: 1.25rem;
}

.form-group label {
  display: block;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--text-main);
  margin-bottom: 0.5rem;
}

.form-group input {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid var(--border-light);
  border-radius: 8px;
  font-size: 0.95rem;
  transition: border-color 0.2s;
}

.form-group input:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px rgba(34, 106, 255, 0.1);
}

.spinner {
  width: 40px;
  height: 40px;
  border: 4px solid #f3f3f3;
  border-top: 4px solid var(--primary);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}
</style>
