<?php

namespace App\Controllers\Api\V1\Driver;

use App\Controllers\BaseController;
use App\Models\DriverBillingConfigModel;
use App\Models\DriverModel;
use App\Models\OrderModel;
use App\Models\OrderStatusLogModel;
use App\Services\NotificationService;
use App\Services\PusherService;
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

        $orders = $this->orderModel
            ->where('status', 'publicado')
            ->where('client_id', $driver['client_id'])
            ->where('scheduled_at <=', date_create('now', new \DateTimeZone('America/Mexico_City'))->format('Y-m-d H:i:s'))
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
        $db->transBegin();

        try {
            // Asignación atómica: la fila solo se toca si aún está en 'publicado'.
            // MySQL garantiza que exactamente un UPDATE ganará cuando hay concurrencia.
            $db->table('orders')
                ->where('id', $id)
                ->where('status', 'publicado')
                ->update([
                    'driver_id'  => $driver['id'],
                    'status'     => 'tomado',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            if ($db->affectedRows() !== 1) {
                $db->transRollback();
                return $this->respondError('Este viaje ya fue tomado por otro conductor.', [], 409);
            }

            $statusLogModel = new OrderStatusLogModel();
            $statusLogModel->insert([
                'order_id'        => $id,
                'previous_status' => 'publicado',
                'new_status'      => 'tomado'
            ]);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            return $this->respondError('Error al aceptar el viaje.');
        }

        // Notificar en tiempo real a los demás drivers de la misma flota.
        // Se emite DESPUÉS del commit para garantizar consistencia en DB.
        // Si Pusher falla, el viaje igual queda asignado; el polling de fallback lo sincronizará.
        PusherService::trigger(
            'trips.' . $order['client_id'],
            'trip-taken',
            [
                'trip_id'   => (int) $id,
                'driver_id' => $driver['id'],
                'status'    => 'tomado',
            ]
        );

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
     * GET /api/v1/driver/today
     * Ganancias del día para el driver autenticado (sin pasar ID en la URL).
     */
    public function todayStats()
    {
        $userData = $this->request->jwtPayload;
        $driver   = $this->driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        $walletService = new WalletService();
        $stats         = $walletService->getTodayStats($driver['id']);
        $guarantee     = $walletService->getGuaranteeBalance($driver['id']);

        $billing           = (new \App\Models\DriverBillingConfigModel())->getByClient($driver['client_id']);
        $viajesDisponibles = null;
        if ($billing && $billing['tipo_esquema'] === 'credito') {
            $precio            = (float)($billing['precio_credito'] ?? 0);
            $viajesDisponibles = $precio > 0 ? (int) floor($guarantee / $precio) : 0;
        }

        return $this->respondSuccess('Today stats retrieved.', [
            'earnings'           => (float)$stats['earnings'],
            'trips'              => (int)$stats['trips'],
            'guarantee_balance'  => (float)$guarantee,
            'viajes_disponibles' => $viajesDisponibles,
        ]);
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

        $db           = \Config\Database::connect();
        $walletService = null;
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

        // Post-commit: notificar al driver con sus ganancias actualizadas en tiempo real
        if ($newStatus === 'entregado' && $walletService !== null) {
            try {
                $freshStats       = $walletService->getTodayStats($driver['id']);
                $guaranteeBalance = $walletService->getGuaranteeBalance($driver['id']);

                $billing           = (new DriverBillingConfigModel())->getByClient($driver['client_id']);
                $viajesDisponibles = null;
                if ($billing && $billing['tipo_esquema'] === 'credito') {
                    $precio            = (float)($billing['precio_credito'] ?? 0);
                    $viajesDisponibles = $precio > 0 ? (int) floor($guaranteeBalance / $precio) : 0;
                }

                PusherService::trigger('driver.' . $driver['id'], 'wallet-updated', [
                    'earnings'           => (float)$freshStats['earnings'],
                    'trips'              => (int)$freshStats['trips'],
                    'guarantee_balance'  => (float)$guaranteeBalance,
                    'viajes_disponibles' => $viajesDisponibles,
                ]);
            } catch (\Throwable $e) {
                log_message('error', '[DriverApiController] wallet-updated trigger failed: ' . $e->getMessage());
            }
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

        if (!$order) {
            return $this->respondSuccess('No active trip.', []);
        }

        return $this->respondSuccess('Current trip retrieved.', $order);
    }
}
