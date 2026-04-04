<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\ClientModel;
use App\Traits\ApiResponseTrait;
use Config\Database;

class ClientController extends BaseController
{
    use ApiResponseTrait;
    
    public function index()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $clientModel = new ClientModel();
        // Join with users table to get the name and email of the admin for each client
        $clients = $clientModel->select('clients.*, users.name as admin_name, users.email as admin_email')
                               ->join('users', 'users.id = clients.user_id')
                               ->findAll();

        return $this->respondSuccess('Clients retrieved successfully.', $clients);
    }

    public function show($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $clientModel = new ClientModel();
        $client = $clientModel->select('clients.*, users.name as admin_name, users.email as admin_email')
                              ->join('users', 'users.id = clients.user_id')
                              ->find($id);

        if (!$client) {
            return $this->respondError('Client not found.', [], 404);
        }

        return $this->respondSuccess('Client retrieved successfully.', $client);
    }

    public function create()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $rules = [
            'name'          => 'required',
            'email'         => 'required|valid_email|is_unique[users.email]',
            'password'      => 'required|min_length[6]',
            'business_name' => 'required',
            'cost_per_km'   => 'required|decimal'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $db = Database::connect();
        $db->transStart();

        $userModel = new UserModel();
        $userId = $userModel->insert([
            'name'      => $this->request->getVar('name'),
            'email'     => $this->request->getVar('email'),
            'password'  => $this->request->getVar('password'),
            'role'      => 'client_admin',
            'is_active' => 1
        ]);

        if (!$userId) {
            $db->transRollback();
            return $this->respondError('Error creating user profile.');
        }

        $clientModel = new ClientModel();
        $clientId = $clientModel->insert([
            'user_id'         => $userId,
            'business_name'   => $this->request->getVar('business_name'),
            'credits_balance' => 0,
            'cost_per_km'     => $this->request->getVar('cost_per_km')
        ]);

        if (!$clientId) {
            $db->transRollback();
            return $this->respondError('Error creating client profile.');
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Database transaction failed.');
        }

        $clientRecord = $clientModel->find($clientId);
        return $this->respondSuccess('Client created successfully.', $clientRecord, 201);
    }

    public function addCredits($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $rules = [
            'amount' => 'required|is_natural_no_zero'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $clientModel = new ClientModel();
        $client = $clientModel->find($id);

        if (!$client) {
            return $this->respondError('Client not found.', [], 404);
        }

        $amount = (int) $this->request->getVar('amount');
        
        $newBalance = $client['credits_balance'] + $amount;
        $clientModel->update($id, ['credits_balance' => $newBalance]);

        $transactionModel = new \App\Models\CreditTransactionModel();
        $transactionModel->insert([
            'client_id'        => $id,
            'order_id'         => null,
            'amount'           => $amount,
            'transaction_type' => 'recharge',
            'description'      => 'Credits assigned by SuperAdmin',
            'created_at'       => date('Y-m-d H:i:s')
        ]);

        return $this->respondSuccess('Credits added successfully.', ['new_balance' => $newBalance]);
    }

    public function update($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $clientModel = new ClientModel();
        $client = $clientModel->find($id);

        if (!$client) {
            return $this->respondError('Client not found.', [], 404);
        }

        $rules = [
            'business_name' => 'required',
            'cost_per_km'   => 'required|decimal'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $data = [
            'business_name' => $this->request->getVar('business_name'),
            'cost_per_km'   => $this->request->getVar('cost_per_km')
        ];

        $clientModel->update($id, $data);

        // Also update user name if provided
        if ($this->request->getVar('name')) {
            $userModel = new UserModel();
            $userModel->update($client['user_id'], ['name' => $this->request->getVar('name')]);
        }

        return $this->respondSuccess('Client updated successfully.', $clientModel->find($id));
    }

    public function delete($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $clientModel = new ClientModel();
        $client = $clientModel->find($id);

        if (!$client) {
            return $this->respondError('Client not found.', [], 404);
        }

        $db = Database::connect();
        $db->transStart();

        $clientModel->delete($id);
        
        $userModel = new UserModel();
        $userModel->delete($client['user_id']);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Database transaction failed.');
        }

        return $this->respondSuccess('Client deleted successfully.');
    }
}
