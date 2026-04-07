<?php

namespace App\Controllers\Api\V1\Driver;

use App\Controllers\BaseController;
use App\Models\DriverModel;
use App\Models\OrderModel;
use App\Models\OrderStatusLogModel;
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
        $driver = $this->driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
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

        return $this->respondSuccess('Trip accepted successfully.', $this->orderModel->find($id));
    }

    /**
     * Update trip status
     */
    public function updateStatus($id)
    {
        $userData = $this->request->jwtPayload;
        $driver = $this->driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        $order = $this->orderModel->find($id);

        if (!$order || $order['driver_id'] != $driver['id']) {
            return $this->respondError('Trip not found or not assigned to you.', [], 404);
        }

        $input = $this->request->getJSON(true) ?? $this->request->getPost();
        $newStatus = $input['status'] ?? null;

        $allowedStatuses = ['en_camino', 'entregado'];
        if (!in_array($newStatus, $allowedStatuses)) {
            return $this->respondError('Invalid status update.');
        }

        $previousStatus = $order['status'];

        $db = \Config\Database::connect();
        $db->transStart();

        $this->orderModel->update($id, ['status' => $newStatus]);

        $statusLogModel = new OrderStatusLogModel();
        $statusLogModel->insert([
            'order_id'        => $id,
            'previous_status' => $previousStatus,
            'new_status'      => $newStatus
        ]);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Failed to update trip status.');
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
}
