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
    const response = await api.get('/api/v1/drivers')
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
      response = await api.put(`/api/v1/drivers/${editingDriver.value.id}`, form.value)
    } else {
      response = await api.post('/api/v1/drivers', form.value)
    }

    if (response.data.status) {
      fetchDrivers()
      closeModal()
    }
  } catch (error) {
    console.error('Error saving driver:', error)
    alert(error.response?.data?.message || 'Error saving driver')
  }
}

const deleteDriver = async (id) => {
  if (!confirm('Are you sure you want to delete this driver?')) return
  
  try {
    const response = await api.delete(`/api/v1/drivers/${id}`)
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
      <div>
        <h1 class="text-2xl font-bold text-gray-800">Drivers Management</h1>
        <p class="text-sm text-gray-500">Manage your delivery fleet and vehicle details.</p>
      </div>
      <button class="btn-primary" @click="openModal()">
        <span class="icon">+</span> Add New Driver
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex justify-center p-12">
      <div class="spinner"></div>
    </div>

    <!-- Drivers Table -->
    <div v-else class="mt-8 overflow-hidden bg-white border border-gray-200 rounded-xl shadow-sm">
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-50 border-bottom">
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Driver Name</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Contact</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Vehicle Details</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Status</th>
            <th class="px-6 py-4 text-xs font-semibold text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="driver in drivers" :key="driver.id" class="hover:bg-gray-50 transition-colors">
            <td class="px-6 py-4">
              <div class="font-semibold text-gray-900">{{ driver.name }}</div>
              <div class="text-xs text-gray-400">ID: {{ driver.id }}</div>
            </td>
            <td class="px-6 py-4">
              <div class="text-sm text-gray-700">{{ driver.email }}</div>
              <div class="text-xs text-gray-400">Phone: {{ driver.phone }}</div>
            </td>
            <td class="px-6 py-4 text-sm text-gray-600">{{ driver.vehicle_details }}</td>
            <td class="px-6 py-4">
              <span v-if="driver.is_suspended == 1" class="px-2 py-1 text-xs font-bold rounded-full bg-red-100 text-red-700">
                Suspended
              </span>
              <span v-else class="px-2 py-1 text-xs font-bold rounded-full bg-green-100 text-green-700">
                Active
              </span>
            </td>
            <td class="px-6 py-4">
              <div class="flex gap-2">
                <button class="btn-icon" @click="openModal(driver)" title="Edit">✏️</button>
                <button class="btn-icon delete" @click="deleteDriver(driver.id)" title="Delete">🗑️</button>
              </div>
            </td>
          </tr>
          <tr v-if="drivers.length === 0">
            <td colspan="5" class="px-6 py-12 text-center text-gray-400">
               No drivers found. Click "Add New Driver" to get started.
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Driver Form Modal -->
    <div v-if="showModal" class="modal-overlay">
      <div class="modal-content">
        <div class="modal-header">
          <h2>{{ editingDriver ? 'Edit Driver' : 'New Driver' }}</h2>
          <button @click="closeModal" class="close-btn">&times;</button>
        </div>
        <form @submit.prevent="saveDriver" class="modal-body">
          <div class="form-grid">
            <div class="form-group">
              <label>Full Name</label>
              <input v-model="form.name" type="text" placeholder="Driver's name" required>
            </div>
            <div class="form-group">
              <label>Phone Number</label>
              <input v-model="form.phone" type="text" placeholder="+1..." required>
            </div>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input v-model="form.email" type="email" placeholder="driver@example.com" required>
          </div>
          <div class="form-group" v-if="!editingDriver">
            <label>Default Password</label>
            <input v-model="form.password" type="password" placeholder="Minimum 6 characters" required>
          </div>
          <div class="form-group">
            <label>Vehicle Details (License Plate, Model)</label>
            <input v-model="form.vehicle_details" type="text" placeholder="e.g. Honda Civic ABC-123" required>
          </div>
          <div class="form-group" v-if="editingDriver">
            <label class="flex items-center gap-2 cursor-pointer">
              <input v-model="form.is_suspended" type="checkbox" :true-value="1" :false-value="0">
              Suspend Driver
            </label>
          </div>
          <div class="modal-footer">
            <button type="button" @click="closeModal" class="btn-secondary">Cancel</button>
            <button type="submit" class="btn-primary">
              {{ editingDriver ? 'Update Driver' : 'Create Driver' }}
            </button>
          </div>
        </form>
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

.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"],
.form-group input[type="number"] {
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
