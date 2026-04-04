<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\ClientModel;
use App\Models\DriverModel;
use App\Traits\ApiResponseTrait;
use Config\Database;

class DriverController extends BaseController
{
    use ApiResponseTrait;

    public function index()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || ($userData['role'] !== 'client_admin' && $userData['role'] !== 'superadmin')) {
            return $this->respondUnauthorized('Access denied.');
        }

        $driverModel = new DriverModel();
        
        if ($userData['role'] === 'client_admin') {
            // Get client profile for the logged in user
            $clientModel = new ClientModel();
            $client = $clientModel->where('user_id', $userData['id'])->first();
            
            if (!$client) {
                return $this->respondError('Client profile not found.', [], 404);
            }
            
            $drivers = $driverModel->select('drivers.*, users.name, users.email')
                                   ->join('users', 'users.id = drivers.user_id')
                                   ->where('drivers.client_id', $client['id'])
                                   ->findAll();
        } else {
            // SuperAdmin can see all drivers
            $drivers = $driverModel->select('drivers.*, users.name, users.email, clients.business_name')
                                   ->join('users', 'users.id = drivers.user_id')
                                   ->join('clients', 'clients.id = drivers.client_id')
                                   ->findAll();
        }

        return $this->respondSuccess('Drivers retrieved successfully.', $drivers);
    }

    public function create()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized('Access denied. Client Admin privileges required.');
        }

        $input = $this->request->getPost();
        if (empty($input)) {
            $input = $this->request->getJSON(true);
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        if (!$client) {
            return $this->respondError('Client profile not found.', [], 404);
        }

        $rules = [
            'name'            => 'required',
            'email'           => 'required|valid_email|is_unique[users.email]',
            'password'        => 'required|min_length[6]',
            'phone'           => 'required',
            'vehicle_details' => 'required'
        ];

        if (!$this->validateData($input ?? [], $rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $db = Database::connect();
        $db->transStart();

        $userModel = new UserModel();
        $userId = $userModel->insert([
            'name'      => $input['name'],
            'email'     => $input['email'],
            'password'  => $input['password'],
            'role'      => 'driver',
            'is_active' => 1
        ]);

        if (!$userId) {
            $db->transRollback();
            return $this->respondError('Error creating user profile.');
        }

        $driverModel = new DriverModel();
        $driverId = $driverModel->insert([
            'user_id'         => $userId,
            'client_id'       => $client['id'],
            'phone'           => $input['phone'],
            'vehicle_details' => $input['vehicle_details'],
            'is_suspended'    => 0
        ]);

        if (!$driverId) {
            $db->transRollback();
            return $this->respondError('Error creating driver profile.');
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Database transaction failed.');
        }

        $driverRecord = $driverModel->find($driverId);
        return $this->respondSuccess('Driver created successfully.', $driverRecord, 201);
    }

    public function update($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized('Access denied.');
        }

        $input = $this->request->getPost();
        if (empty($input)) {
            $input = $this->request->getJSON(true);
        }

        $driverModel = new DriverModel();
        $driver = $driverModel->find($id);

        if (!$driver) {
            return $this->respondError('Driver not found.', [], 404);
        }

        // Verify that the driver belongs to this client
        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();
        if ($driver['client_id'] != $client['id']) {
            return $this->respondUnauthorized('Access denied. This driver does not belong to your business.');
        }

        $rules = [
            'name'            => 'required',
            'phone'           => 'required',
            'vehicle_details' => 'required'
        ];

        if (!$this->validateData($input ?? [], $rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $data = [
            'phone'           => $input['phone'] ?? $driver['phone'],
            'vehicle_details' => $input['vehicle_details'] ?? $driver['vehicle_details'],
            'is_suspended'    => $input['is_suspended'] ?? $driver['is_suspended']
        ];

        $driverModel->update($id, $data);

        // Update user name
        if (isset($input['name'])) {
            $userModel = new UserModel();
            $userModel->update($driver['user_id'], ['name' => $input['name']]);
        }

        return $this->respondSuccess('Driver updated successfully.', $driverModel->find($id));
    }

    public function delete($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized('Access denied.');
        }

        $driverModel = new DriverModel();
        $driver = $driverModel->find($id);

        if (!$driver) {
            return $this->respondError('Driver not found.', [], 404);
        }

        // Verify that the driver belongs to this client
        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();
        if ($driver['client_id'] != $client['id']) {
            return $this->respondUnauthorized('Access denied.');
        }

        $db = Database::connect();
        $db->transStart();

        $driverModel->delete($id);
        
        $userModel = new UserModel();
        $userModel->delete($driver['user_id']);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Database transaction failed.');
        }

        return $this->respondSuccess('Driver deleted successfully.');
    }
}
