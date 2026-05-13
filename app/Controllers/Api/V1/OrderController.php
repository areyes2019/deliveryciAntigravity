<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Services\OrderService;
use App\Services\PusherService;
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

        // Auto-publish scheduled orders whose time has arrived (lazy approach, no cron needed)
        $this->orderService->publishDueOrders();

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

        // scheduled_at: si se envía debe tener formato válido.
        // Si no se envía, OrderService::createOrder lo asigna con date('Y-m-d H:i:s') automáticamente.
        // NUNCA debe quedar NULL en la base de datos.
        if (!empty($data['scheduled_at'])) {
            $rules['scheduled_at'] = 'valid_date';
        }

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

        $orderModel = new \App\Models\OrderModel();
        $order = $orderModel->find($id);
        if (!$order) {
            return $this->respondError('Order not found', [], 404);
        }

        $clientModel = new ClientModel();
        if ($userData['role'] === 'client_admin') {
            $client = $clientModel->where('user_id', $userData['id'])->first();
            if (!$client) {
                return $this->respondError('Client profile not found', [], 404);
            }
            $clientId = $client['id'];
        } else {
            $clientId = $order['client_id'];
        }

        // Capturar driver_id ANTES de cancelar — cancelOrder puede limpiar el campo
        $assignedDriverId = $order['driver_id'] ?? null;

        $result = $this->orderService->cancelOrder($id, $clientId);

        if ($result['status']) {
            // Notificar al driver asignado para que limpie su viaje activo
            if ($assignedDriverId) {
                PusherService::trigger(
                    'driver.' . $assignedDriverId,
                    'order-cancelled',
                    ['order_id' => (int) $id]
                );
            }

            // Notificar al panel de órdenes del cliente
            PusherService::trigger(
                'orders.' . $clientId,
                'order-cancelled',
                ['order_id' => (int) $id]
            );

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

        // Actualizar estado: vuelve a publicado para que otro driver lo tome
        $orderModel->update($id, [
            'status'    => 'publicado',
            'driver_id' => null,
        ]);

        // Registrar en log de estados
        $logModel = new \App\Models\OrderStatusLogModel();
        $logModel->insert([
            'order_id'   => $id,
            'status'     => 'publicado',
            'user_id'    => $userData['id'],
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        PusherService::trigger(
            'trips.' . $order['client_id'],
            'new-trip',
            ['trip_id' => (int) $id]
        );

        return $this->respondSuccess('Order cancelled successfully');
    }
}
