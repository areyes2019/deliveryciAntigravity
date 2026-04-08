<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Models\UserModel;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        $userModel = new UserModel();
        
        $data = [
            'name'      => 'Super Admin',
            'email'     => 'admin@delivery.com',
            'password'  => '12345678',
            'role'      => 'superadmin',
            'is_active' => 1
        ];

        if (!$userModel->where('email', $data['email'])->first()) {
            $userModel->insert($data);
        }
    }
}
