<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../api'
import CreateOrderModal from '../components/CreateOrderModal.vue'

const orders = ref([])
const loading = ref(true)
const showModal = ref(false)
const userBalance = ref(0)
const tripCost = ref(0)

const form = ref({
  pickup_address: '',
  drop_address: '',
  description: '',
  payment_type: 'prepaid',
  pickup_lat: 40.7128, // Default placeholders
  pickup_lng: -74.0060,
  drop_lat: 40.7306,
  drop_lng: -73.9352
})

const fetchOrders = async () => {
  loading.value = true
  try {
    const response = await api.get('/orders')
    if (response.data.status) {
      orders.value = response.data.data
    }

    // Refresh client data to get fresh balance
    const meResponse = await api.get('/auth/me')
    if (meResponse.data.status) {
        userBalance.value = parseFloat(meResponse.data.data.client_balance) || 0
        tripCost.value = parseFloat(meResponse.data.data.cost_per_trip) || 0
    }
  } catch (error) {
    console.error('Error fetching orders:', error)
  } finally {
    loading.value = false
  }
}

const canAffordOrder = computed(() => {
    return userBalance.value >= tripCost.value
})

const openModal = () => {
  showModal.value = true
}



const cancelOrder = async (orderId) => {
    if (!confirm('¿Estás seguro de que deseas cancelar este pedido?')) return;
    
    try {
        const response = await api.put(`/orders/${orderId}/cancel`);
        if (response.data.status) {
            alert('Pedido cancelado exitosamente');
            fetchOrders();
        }
    } catch (error) {
        console.error('Error cancelling order:', error);
        alert(error.response?.data?.message || 'Error al cancelar el pedido');
    }
}

const getStatusBadgeClass = (status) => {
  switch (status) {
    case 'publicado': return 'status-published'
    case 'en_curso': return 'status-ongoing'
    case 'entregado': return 'status-delivered'
    case 'cancelado': return 'status-cancelled'
    default: return ''
  }
}

const formatDate = (dateString) => {
    if (!dateString) return '-'
    const d = new Date(dateString)
    return d.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

onMounted(fetchOrders)
</script>

<template>
  <div class="orders-view">
    <div class="page-header">
      <div class="header-info">
        <h1>Gestión de Pedidos</h1>
        <p>Realiza y rastrea tus envíos en tiempo real.</p>
      </div>
      <div class="header-actions">
          <div class="balance-card">
              <span class="label">Viajes Disponibles</span>
              <span class="value">{{ Math.floor(userBalance / (tripCost || 1)) }}</span>
          </div>
          <button class="btn-primary" @click="openModal" :disabled="!canAffordOrder">
            <span class="icon">+</span> Nuevo Pedido
          </button>
      </div>
    </div>

    <!-- Alert for insufficient balance -->
    <div v-if="!canAffordOrder && !loading" class="alert-warning">
        ⚠️ Tu saldo actual es insuficiente. Por favor, contacta al administrador para recargar créditos.
    </div>

    <div v-if="loading" class="loading-container">
      <div class="spinner"></div>
    </div>

    <div v-else class="table-container">
      <table class="custom-table">
        <thead>
          <tr>
            <th>Ticket</th>
            <th>Origen / Destino</th>
            <th class="text-center">Estado</th>
            <th class="text-center">Costo</th>
            <th class="text-center">Fecha</th>
            <th class="text-center">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="order in orders" :key="order.id">
            <td>
              <span class="order-id">#{{ order.id }}</span>
              <span class="uuid-short">{{ order.uuid.substring(0, 8) }}</span>
            </td>
            <td>
              <div class="addresses">
                <div class="address-item pickup">
                    <span class="dot green"></span> {{ order.pickup_address }}
                </div>
                <div class="address-item drop">
                    <span class="dot red"></span> {{ order.drop_address }}
                </div>
              </div>
            </td>
            <td class="text-center">
              <span class="status-badge" :class="getStatusBadgeClass(order.status)">
                {{ order.status.toUpperCase() }}
              </span>
            </td>
            <td class="text-center">
              <span class="order-cost">$ {{ order.cost }}</span>
            </td>
            <td class="text-center">
              <span class="order-date">{{ formatDate(order.created_at) }}</span>
            </td>
            <td class="text-center">
                <button 
                    v-if="order.status === 'publicado'" 
                    @click="cancelOrder(order.id)" 
                    class="btn-icon btn-danger"
                    title="Cancelar pedido">
                    <span class="icon">✕</span> Cancelar
                </button>
            </td>
          </tr>
          <tr v-if="orders.length === 0">
            <td colspan="6" class="empty-row">
              <div class="empty-state">
                <span class="empty-icon">📦</span>
                <p>No tienes pedidos registrados todavía.</p>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Modal Generar Viaje -->
    <CreateOrderModal
        v-if="showModal"
        @close="showModal = false"
        @created="fetchOrders"
    />
  </div>
</template>

<style scoped>
.orders-view { display: flex; flex-direction: column; }

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 2rem;
}

.header-info h1 { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.25rem; }
.header-info p { color: var(--text-muted); font-size: 0.95rem; }

.header-actions { display: flex; align-items: center; gap: 1.5rem; }

.balance-card {
    background: #F0F9FF;
    border: 1px solid #BAE6FD;
    padding: 0.5rem 1rem;
    border-radius: 12px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.balance-card .label { font-size: 0.7rem; color: #0369A1; text-transform: uppercase; font-weight: 700; }
.balance-card .value { font-size: 1.25rem; font-weight: 800; color: #0C4A6E; }

.alert-warning {
    background-color: #FFFBEB;
    border: 1px solid #FDE68A;
    color: #92400E;
    padding: 1rem;
    border-radius: 12px;
    margin-bottom: 1.5rem;
    font-weight: 500;
}

/* Table */
.table-container {
  background: white;
  border-radius: var(--radius-lg);
  border: 1px solid var(--border-light);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.custom-table { width: 100%; border-collapse: collapse; }
.custom-table th {
  background-color: #F9FAFB;
  padding: 1rem 1.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  color: var(--text-muted);
  border-bottom: 1px solid var(--border-light);
  text-align: left;
}

.custom-table td { padding: 1.25rem 1.5rem; border-bottom: 1px solid #F3F4F6; vertical-align: middle; }

/* Cell Content */
.order-id { display: block; font-weight: 700; color: var(--text-main); }
.uuid-short { font-size: 0.7rem; color: var(--text-light); }

.addresses { display: flex; flex-direction: column; gap: 0.5rem; }
.address-item { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; font-weight: 500; }

.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.dot.green { background-color: #10B981; box-shadow: 0 0 8px rgba(16, 185, 129, 0.4); }
.dot.red { background-color: #EF4444; box-shadow: 0 0 8px rgba(239, 68, 68, 0.4); }

.status-badge {
  padding: 0.25rem 0.75rem;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.status-published { background-color: #E0F2FE; color: #0369A1; }
.status-ongoing { background-color: #FEF3C7; color: #92400E; }
.status-delivered { background-color: #DCFCE7; color: #166534; }
.status-cancelled { background-color: #FEE2E2; color: #991B1B; }

.order-cost { font-weight: 700; color: var(--text-main); }
.order-date { font-size: 0.85rem; color: var(--text-muted); }

.empty-state { text-align: center; padding: 4rem; color: var(--text-light); }
.empty-icon { font-size: 3rem; display: block; margin-bottom: 1rem; }

.text-center { text-align: center; } 

.loading-container { display: flex; justify-content: center; padding: 4rem; }
.spinner {
  width: 40px; height: 40px; border: 4px solid #F3F4F6; border-top: 4px solid var(--primary);
  border-radius: 50%; animation: spin 1s linear infinite;
}
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

.btn-icon {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.35rem 0.75rem;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: all 0.2s ease;
}

.btn-danger {
  background-color: #FEE2E2;
  color: #DC2626;
}

.btn-danger:hover {
  background-color: #FECACA;
}

.btn-danger .icon {
  font-weight: bold;
}
</style>
