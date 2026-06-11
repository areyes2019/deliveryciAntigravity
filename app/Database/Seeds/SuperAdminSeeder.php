<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use App\Models\UserModel;

class SuperAdminSeeder extends Seeder
{
    public function run()
    {
        $userModel = new UserModel();

        $users = [
            [
                'name'     => 'Super Admin',
                'email'    => 'admin@delivery.com',
                'password' => '12345678',
                'role'     => 'superadmin',
            ],
            [
                'name'     => 'Abdias Reyes',
                'email'    => 'reyesabdias@gmail.com',
                'password' => '12345678',
                'role'     => 'superadmin',
            ],
        ];

        foreach ($users as $data) {
            if (!$userModel->where('email', $data['email'])->first()) {
                $userModel->insert($data);
                echo "Usuario creado: {$data['email']}\n";
            } else {
                echo "Ya existe: {$data['email']}\n";
            }
        }
    }
}
