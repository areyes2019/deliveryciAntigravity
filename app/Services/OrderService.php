<?php

namespace App\Services;

use App\Models\OrderModel;
use App\Models\OrderStatusLogModel;
use App\Models\ClientModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class OrderService
{
    private BaseConnection $db;
    private OrderModel $orderModel;
    private OrderStatusLogModel $statusLogModel;
    private CreditService $creditService;
    private MockDistanceMatrixService $distanceService;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->orderModel = new OrderModel();
        $this->statusLogModel = new OrderStatusLogModel();
        $this->creditService = new CreditService();
        $this->distanceService = new MockDistanceMatrixService();
    }

    public function createOrder(int $clientId, array $data): array
    {
        $clientModel = new ClientModel();
        $client = $clientModel->find($clientId);

        if (!$client) {
            return ['status' => false, 'message' => 'Client not found'];
        }

        if ($client['credits_balance'] < 1) {
            return ['status' => false, 'message' => 'Saldo insuficiente para crear este pedido. Necesitas al menos 1 crédito.'];
        }

        $distanceKm = $this->distanceService->getDistanceInKm($data['pickup_address'], $data['drop_address']);
        
        $pricingService = new PricingService();
        $priceResult = $pricingService->calculatePrice($clientId, (float)$data['pickup_lat'], (float)$data['pickup_lng'], (float)$data['drop_lat'], (float)$data['drop_lng'], $distanceKm);

        if (!$priceResult['status']) {
            return ['status' => false, 'message' => $priceResult['message']];
        }

        $cost = $priceResult['price'];

        $this->db->transStart();

        $orderData = [
            'client_id'      => $clientId,
            'pickup_lat'     => $data['pickup_lat'],
            'pickup_lng'     => $data['pickup_lng'],
            'pickup_address' => $data['pickup_address'],
            'drop_lat'       => $data['drop_lat'],
            'drop_lng'       => $data['drop_lng'],
            'drop_address'   => $data['drop_address'],
            'description'    => $data['description'] ?? null,
            'status'         => 'publicado',
            'payment_type'   => $data['payment_type'] ?? 'prepaid',
            'cost'           => $cost,
            'distance_km'    => $distanceKm
        ];

        $orderId = $this->orderModel->insert($orderData);

        if (!$orderId) {
            $this->db->transRollback();
            return ['status' => false, 'message' => 'Failed to create order'];
        }

        $creditDeducted = $this->creditService->deductCredit($clientId, $orderId, 'Order created');
        
        if (!$creditDeducted) {
            $this->db->transRollback();
            return ['status' => false, 'message' => 'Insufficient credits during transaction'];
        }

        $this->statusLogModel->insert([
            'order_id'        => $orderId,
            'previous_status' => null,
            'new_status'      => 'publicado'
        ]);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return ['status' => false, 'message' => 'Database transaction failed'];
        }

        $order = $this->orderModel->find($orderId);
        return ['status' => true, 'message' => 'Order created successfully', 'data' => $order];
    }

    public function cancelOrder(int $orderId, int $clientId): array
    {
        $order = $this->orderModel->find($orderId);

        if (!$order) {
            return ['status' => false, 'message' => 'Order not found'];
        }

        if ($order['client_id'] != $clientId) {
            return ['status' => false, 'message' => 'Unauthorized to cancel this order'];
        }

        if ($order['status'] !== 'publicado') {
            return ['status' => false, 'message' => 'Only published orders can be cancelled'];
        }

        $this->db->transStart();

        $this->orderModel->update($orderId, ['status' => 'cancelado']);

        // Refund credit
        $refunded = $this->creditService->refundCredit($clientId, $orderId, 'Refund for cancelled order');

        if (!$refunded) {
            $this->db->transRollback();
            return ['status' => false, 'message' => 'Failed to refund credits'];
        }

        $this->statusLogModel->insert([
            'order_id'        => $orderId,
            'previous_status' => 'publicado',
            'new_status'      => 'cancelado'
        ]);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return ['status' => false, 'message' => 'Database transaction failed'];
        }

        return ['status' => true, 'message' => 'Order cancelled successfully'];
    }
}
