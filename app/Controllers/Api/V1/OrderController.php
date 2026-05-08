<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Services\OrderService;
use App\Models\ClientModel;
use App\Models\DriverModel;
use App\Traits\ApiResponseTrait;

class OrderController extends BaseController
{
    use ApiResponseTrait;

    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    public function index()
    {
        $userData = $this->request->jwtPayload ?? null;

        if (!$userData) {
            return $this->respondUnauthorized();
        }

        $orderModel = new \App\Models\OrderModel();

        if ($userData['role'] === 'superadmin') {
            $orders = $orderModel->findAll();
        } else if ($userData['role'] === 'client_admin') {
            $clientModel = new ClientModel();
            $client = $clientModel->where('user_id', $userData['id'])->first();

            if (!$client) {
                return $this->respondError('Client profile not found');
            }

            $orders = $orderModel->where('client_id', $client['id'])->findAll();
        } else {
            return $this->respondUnauthorized('Unauthorized access for this role.');
        }

        return $this->respondSuccess('Orders retrieved successfully', $orders);
    }

    public function create()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized('Only clients can create orders.');
        }

        $data = $this->request->getPost();
        if (empty($data)) {
            $data = $this->request->getJSON(true);
        }

        $rules = [
            'pickup_lat'      => 'required|decimal',
            'pickup_lng'      => 'required|decimal',
            'pickup_address'  => 'required',
            'drop_lat'        => 'required|decimal',
            'drop_lng'        => 'required|decimal',
            'drop_address'    => 'required',
            'receiver_name'   => 'required|min_length[3]|max_length[255]',
            'receiver_phone'  => 'required|min_length[7]|max_length[20]',
            'payment_type'    => 'required|in_list[prepaid,cash_on_delivery,cash_full]'
        ];

        // product_amount is required and must be > 0 only for cash_full
        if (($data['payment_type'] ?? '') === 'cash_full') {
            $rules['product_amount'] = 'required|decimal|greater_than[0]';
        }

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        if (!$client) {
            return $this->respondError('Client profile not found for the user', [], 404);
        }

        $result = $this->orderService->createOrder($client['id'], $data);

        if ($result['status']) {
            return $this->respondSuccess($result['message'], $result['data'], 201);
        }

        return $this->respondError($result['message']);
    }

    public function cancel($id)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || ($userData['role'] !== 'client_admin' && $userData['role'] !== 'superadmin')) {
            return $this->respondUnauthorized('Unauthorized to cancel order.');
        }

        $clientModel = new ClientModel();
        // If it's a client admin, verify client id
        if ($userData['role'] === 'client_admin') {
            $client = $clientModel->where('user_id', $userData['id'])->first();
            if (!$client) {
                return $this->respondError('Client profile not found', [], 404);
            }
            $clientId = $client['id'];
        } else {
             // For superadmin, we might need to look up the order and get the clientId 
             // but let's assume they shouldn't cancel directly from this endpoint or just allow it if needed. 
             // For simplicity based on Service logic, pass the clientId of the order, 
             // let's fetch the order first to get its client_id
             $orderModel = new \App\Models\OrderModel();
             $order = $orderModel->find($id);
             if (!$order) {
                 return $this->respondError('Order not found', [], 404);
             }
             $clientId = $order['client_id'];
        }

        $result = $this->orderService->cancelOrder($id, $clientId);

        if ($result['status']) {
            return $this->respondSuccess($result['message']);
        }

        return $this->respondError($result['message']);
    }

    public function cancelByDriver($id)
    {
        $userData = $this->request->jwtPayload ?? null;

        if (!$userData || $userData['role'] !== 'driver') {
            return $this->respondUnauthorized('Only drivers can cancel via this endpoint.');
        }

        $driverModel = new \App\Models\DriverModel();
        $driver = $driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found', [], 404);
        }

        $orderModel = new \App\Models\OrderModel();
        $order = $orderModel->find($id);

        if (!$order) {
            return $this->respondError('Order not found', [], 404);
        }

        // Verificar que el conductor es el asignado (driver_id = drivers.id, no users.id)
        if ($order['driver_id'] != $driver['id']) {
            return $this->respondUnauthorized('You are not assigned to this order.');
        }

        // Estados permitidos para cancelación por driver
        $allowedStatuses = ['tomado', 'arribado', 'en_camino'];
        if (!in_array($order['status'], $allowedStatuses)) {
            return $this->respondError('Cannot cancel order in current status: ' . $order['status']);
        }

        // Actualizar estado
        $orderModel->update($id, [
            'status' => 'cancelled_by_driver',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'driver_id' => null  // Liberar al conductor
        ]);

        // Registrar en log de estados
        $logModel = new \App\Models\OrderStatusLogModel();
        $logModel->insert([
            'order_id' => $id,
            'status' => 'cancelled_by_driver',
            'user_id' => $userData['id'],
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $this->respondSuccess('Order cancelled successfully');
    }
}
