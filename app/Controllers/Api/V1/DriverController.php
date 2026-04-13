<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\ClientModel;
use App\Models\DriverBillingConfigModel;
use App\Models\DriverModel;
use App\Models\WalletMovementModel;
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

        $driverModel  = new DriverModel();
        $billingModel = new DriverBillingConfigModel();

        // Subquery: only guarantee wallet
        $guaranteeSubquery = "(SELECT COALESCE(SUM(amount), 0) FROM wallet_movements
                               WHERE driver_id = drivers.id
                               AND wallet_type = 'guarantee') as saldo_garantia";

        if ($userData['role'] === 'client_admin') {
            $clientModel = new ClientModel();
            $client      = $clientModel->where('user_id', $userData['id'])->first();
            $billing     = $billingModel->getByClient($client['id']);

            $drivers = $driverModel->select("drivers.*, users.name, users.email, $guaranteeSubquery")
                                   ->join('users', 'users.id = drivers.user_id')
                                   ->where('drivers.client_id', $client['id'])
                                   ->findAll();

            $drivers = $this->enrichDrivers($drivers, $billing);

        } else {
            // Superadmin: fetch all billing configs indexed by client_id
            $allBillings = [];
            foreach ($billingModel->findAll() as $b) {
                $allBillings[$b['client_id']] = $b;
            }

            $drivers = $driverModel->select("drivers.*, users.name, users.email, clients.business_name, $guaranteeSubquery")
                                   ->join('users', 'users.id = drivers.user_id')
                                   ->join('clients', 'clients.id = drivers.client_id')
                                   ->findAll();

            $drivers = $this->enrichDrivers($drivers, null, $allBillings);
        }

        return $this->respondSuccess('Drivers retrieved successfully.', $drivers);
    }

    /**
     * Adds saldo_garantia, viajes_disponibles and tipo_esquema to each driver row.
     *
     * @param array      $drivers     Raw driver rows from DB
     * @param array|null $billing     Billing config for a single client (client_admin)
     * @param array      $allBillings Billing configs indexed by client_id (superadmin)
     */
    private function enrichDrivers(array $drivers, ?array $billing, array $allBillings = []): array
    {
        foreach ($drivers as &$driver) {
            $b = $billing ?? ($allBillings[$driver['client_id']] ?? null);

            $saldo          = (float)($driver['saldo_garantia'] ?? 0);
            $tipoEsquema    = $b['tipo_esquema'] ?? null;
            $viajesDisp     = null;

            if ($tipoEsquema === 'credito') {
                $precio     = (float)($b['precio_credito'] ?? 0);
                $viajesDisp = $precio > 0 ? (int)floor($saldo / $precio) : 0;
            }
            // porcentaje scheme: viajes_disponibles no aplica → null (UI mostrará "—")

            $driver['saldo_garantia']     = $saldo;
            $driver['tipo_esquema']       = $tipoEsquema;
            $driver['viajes_disponibles'] = $viajesDisp;

            // Keep legacy field so other parts of the system don't break
            $driver['balance'] = $saldo;
        }

        return $drivers;
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
            'role'      => 'driver'
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
            'is_active'       => 1
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
            'vehicle_details' => $input['vehicle_details'] ?? $driver['vehicle_details']
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

    public function goOffline()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'driver') {
            return $this->respondUnauthorized('Only drivers can update their availability.');
        }

        $driverModel = new DriverModel();
        $driver = $driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        $driverModel->update($driver['id'], ['is_active' => 0]);

        return $this->respondSuccess('Te has desconectado exitosamente.', [
            'is_active' => 0,
            'status'    => 'offline'
        ]);
    }

    public function toggleAvailability()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'driver') {
            return $this->respondUnauthorized('Only drivers can toggle their availability.');
        }

        $userModel = new UserModel();
        $user = $userModel->find($userData['id']);
        
        if (!$user) {
            return $this->respondError('User profile not found.', [], 404);
        }

        // Check if user account is administratively suspended
        if ($user['is_suspended'] == 1) {
            return $this->respondError('Your account has been suspended by an administrator. Please contact support.', [], 403);
        }

        $driverModel = new DriverModel();
        $driver = $driverModel->where('user_id', $userData['id'])->first();

        if (!$driver) {
            return $this->respondError('Driver profile not found.', [], 404);
        }

        // Toggle voluntary connection: is_active = 1 means online, 0 means offline
        $newStatus = $driver['is_active'] == 0 ? 1 : 0;
        
        $driverModel->update($driver['id'], [
            'is_active' => $newStatus
        ]);

        $updatedDriver = $driverModel->find($driver['id']);
        
        $message = $newStatus == 0 ? 'Te has desconectado exitosamente.' : 'Te has conectado exitosamente.';
        
        return $this->respondSuccess($message, [
            'is_active' => $newStatus,
            'status' => $newStatus == 1 ? 'online' : 'offline'
        ]);
    }
}
