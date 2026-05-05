<?php

namespace App\Controllers\Api\V1\Driver;

use App\Controllers\BaseController;
use App\Models\DriverBillingConfigModel;
use App\Models\DriverModel;
use App\Models\OrderModel;
use App\Models\OrderStatusLogModel;
use App\Services\NotificationService;
use App\Services\WalletService;
use App\Traits\ApiResponseTrait;

class DriverApiController extends BaseController
{
    use ApiResponseTrait;

    protected DriverModel $driverModel;
    protected OrderModel $orderModel;

    public function __construct()
    {
        $this->driverModel = new DriverModel();
        $this->orderModel = new OrderModel();
    }

    /**
     * Get available trips for the driver's client
     */
    public function availableTrips()
    {
        $userData = $this->request->jwtPayload;
        $driver = $this->driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        if (empty($driver['is_active'])) {
            return $this->respondError('You must be online to view available trips.', [], 403);
        }

        $orders = $this->orderModel->where('status', 'publicado')
                                   ->where('client_id', $driver['client_id'])
                                   ->findAll();

        return $this->respondSuccess('Available trips retrieved.', $orders);
    }

    /**
     * Accept a trip
     */
    public function acceptTrip($id)
    {
        $userData = $this->request->jwtPayload;
        $driver = $this->driverModel
            ->select('drivers.*, users.name as driver_name')
            ->join('users', 'users.id = drivers.user_id')
            ->where('drivers.user_id', $userData['id'])
            ->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        if (empty($driver['is_active'])) {
            return $this->respondError('You must be online to accept trips.', [], 403);
        }

        $order = $this->orderModel->find($id);

        if (!$order) {
            return $this->respondError('Trip not found.', [], 404);
        }

        if ($order['status'] !== 'publicado') {
            return $this->respondError('Trip is no longer available.');
        }

        if ($order['client_id'] != $driver['client_id']) {
            return $this->respondError('Unauthorized: This trip belongs to another client.');
        }

        // ── Validar saldo de garantía (esquema crédito) ───────────────────────
        $billingModel = new DriverBillingConfigModel();
        $billing      = $billingModel->getByClient($driver['client_id']);

        if ($billing && $billing['tipo_esquema'] === 'credito') {
            $walletService    = new WalletService();
            $guaranteeBalance = $walletService->getGuaranteeBalance($driver['id']);
            $precioPorViaje   = (float)($billing['precio_credito'] ?? 0);

            if ($guaranteeBalance < $precioPorViaje) {
                return $this->respondError(
                    "Sin saldo suficiente. Necesitas \${$precioPorViaje} de garantía para aceptar este viaje. " .
                    "Tu saldo actual es \${$guaranteeBalance}."
                );
            }
        }

        $db = \Config\Database::connect();
        $db->transStart();

        // Update order
        $this->orderModel->update($id, [
            'driver_id' => $driver['id'],
            'status'    => 'tomado'
        ]);

        // Log status change
        $statusLogModel = new OrderStatusLogModel();
        $statusLogModel->insert([
            'order_id'        => $id,
            'previous_status' => 'publicado',
            'new_status'      => 'tomado'
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Failed to accept trip.');
        }

        // Notificar al receptor que el conductor fue asignado
        if (!empty($order['receiver_phone'])) {
            $notification = new NotificationService();
            $notification->sendNotification(
                $order['receiver_phone'],
                "Hola {$order['receiver_name']}, tu conductor {$driver['driver_name']} ya fue asignado y va en camino a recoger tu pedido 🚗"
            );
        } else {
            log_message('warning', "[DriverApiController] Orden #{$id} aceptada sin receiver_phone — SMS omitido.");
        }

        return $this->respondSuccess('Trip accepted successfully.', $this->orderModel->find($id));
    }

    /**
     * Update trip status
     */
    public function updateStatus($id)
    {
        $userData = $this->request->jwtPayload;
        $driver = $this->driverModel
            ->select('drivers.*, users.name as driver_name')
            ->join('users', 'users.id = drivers.user_id')
            ->where('drivers.user_id', $userData['id'])
            ->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        $order = $this->orderModel->find($id);

        if (!$order || $order['driver_id'] != $driver['id']) {
            return $this->respondError('Trip not found or not assigned to you.', [], 404);
        }

        $input = $this->request->getJSON(true) ?? $this->request->getPost();
        $newStatus = $input['status'] ?? null;

        $allowedTransitions = [
            'tomado' => ['arribado'],
            'arribado' => ['en_camino'],
            'en_camino' => ['entregado'],
        ];

        if (!isset($allowedTransitions[$order['status']]) || !in_array($newStatus, $allowedTransitions[$order['status']], true)) {
            return $this->respondError('Invalid status update.');
        }

        $previousStatus = $order['status'];

        $db = \Config\Database::connect();
        $db->transBegin();

        try {
            $updateData = ['status' => $newStatus];
            if ($newStatus === 'entregado') {
                $updateData['paid'] = 1;
            }

            $db->table('orders')
                ->where('id', $id)
                ->where('driver_id', $driver['id'])
                ->where('status', $previousStatus)
                ->update($updateData);

            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                return $this->respondError('Trip status was already updated from another request.', [], 409);
            }

            $statusLogModel = new OrderStatusLogModel();
            $statusLogModel->insert([
                'order_id'        => $id,
                'previous_status' => $previousStatus,
                'new_status'      => $newStatus
            ]);

            if ($newStatus === 'entregado') {
                $walletService = new WalletService();

                // Registrar ingreso por cobro en efectivo (earnings)
                if ((float)($order['total_to_collect'] ?? 0) > 0) {
                    $walletService->addIncomeFromTrip(
                        $driver['id'],
                        (int)$id,
                        (float)$order['total_to_collect']
                    );
                }

                // Descontar crédito de garantía si el esquema es "credito"
                $walletService->deductGuaranteeForTrip(
                    $driver['id'],
                    (int)$id,
                    $driver['client_id']
                );
            }

            if ($db->transStatus() === false) {
                throw new \RuntimeException('Failed to update trip status.');
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->respondError($e->getMessage());
        }

        return $this->respondSuccess('Trip status updated to ' . $newStatus);
    }

    /**
     * Update driver location
     */
    public function updateLocation()
    {
        $userData = $this->request->jwtPayload;
        $driver = $this->driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        $input = $this->request->getJSON(true) ?? $this->request->getPost();

        if (!isset($input['lat']) || !isset($input['lng'])) {
            return $this->respondError('Latitude and longitude are required.');
        }

        $this->driverModel->update($driver['id'], [
            'current_lat' => $input['lat'],
            'current_lng' => $input['lng']
        ]);

        return $this->respondSuccess('Location updated successfully.');
    }
    /**
     * Get current assigned trip
     */
    public function getCurrentTrip()
    {
        $userData = $this->request->jwtPayload;
        $driver = $this->driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        $order = $this->orderModel->where('driver_id', $driver['id'])
                                   ->whereIn('status', ['tomado', 'arribado', 'en_camino'])
                                   ->first();

        return $this->respondSuccess('Current trip retrieved.', $order ?? []);
    }
}
