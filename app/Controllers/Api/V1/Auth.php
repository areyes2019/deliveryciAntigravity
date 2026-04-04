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

        if (!$user['is_active']) {
            return $this->respondError('User account is inactive', [], 403);
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

        return $this->respondSuccess('Login successful', [
            'token' => $token,
            'user'  => [
                'uuid'  => $user['uuid'],
                'name'  => $user['name'],
                'email' => $user['email'],
                'role'  => $user['role']
            ]
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

        unset($user['id']);
        unset($user['password']);

        return $this->respondSuccess('User details', $user);
    }
}
