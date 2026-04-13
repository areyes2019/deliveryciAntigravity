<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Libraries\JwtLibrary;
use App\Traits\ApiResponseTrait;

class Auth extends BaseController
{
    use ApiResponseTrait;

    public function login()
    {
        $rules = [
            'email'    => 'required|valid_email',
            'password' => 'required'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $email = $this->request->getVar('email');
        $password = $this->request->getVar('password');

        $userModel = new UserModel();
        $user = $userModel->where('email', $email)->first();

        if (!$user) {
            return $this->respondError('Invalid email or password', [], 401);
        }

        if ($user['is_suspended'] == 1) {
            return $this->respondError('Your account has been suspended. Please contact support.', [], 403);
        }

        if (!password_verify($password, $user['password'])) {
            return $this->respondError('Invalid email or password', [], 401);
        }

        $jwtLib = new JwtLibrary();
        $token = $jwtLib->generate([
            'id'    => $user['id'],
            'uuid'  => $user['uuid'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ]);

        // Attach profile data
        $profile = $this->getUserProfile($user);
        $user = array_merge($user, $profile);

        unset($user['id']);
        unset($user['password']);

        return $this->respondSuccess('Login successful', [
            'token' => $token,
            'user'  => $user
        ]);
    }

    public function me()
    {
        $userData = $this->request->jwtPayload ?? null;
        
        if (!$userData) {
            return $this->respondUnauthorized('Token context not found');
        }

        $userModel = new UserModel();
        $user = $userModel->where('uuid', $userData['uuid'])->first();
        
        if (!$user) {
            return $this->respondError('User not found', [], 404);
        }

        $profile = $this->getUserProfile($user);
        $user = array_merge($user, $profile);

        unset($user['id']);
        unset($user['password']);

        return $this->respondSuccess('User details', $user);
    }

    /**
     * Helper to fetch role-specific profile data
     */
    private function getUserProfile(array $user): array
    {
        $data = [];
        if ($user['role'] === 'client_admin') {
            $clientModel = new \App\Models\ClientModel();
            $client = $clientModel->where('user_id', $user['id'])->first();
            if ($client) {
                $data['client'] = $client;
                $data['client_balance'] = $client['credits_balance'];
                $data['cost_per_trip'] = $client['cost_per_trip'];
            }
        } elseif ($user['role'] === 'driver') {
            $driverModel = new \App\Models\DriverModel();
            $driver = $driverModel->where('user_id', $user['id'])->first();
            if ($driver) {
                $data['driver'] = $driver;
            }
        }
        return $data;
    }
}
