<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexesToUsersAndClients extends Migration
{
    public function up(): void
    {
        // Acelera: WHERE uuid = ? en Auth::me() — búsqueda por UUID del token JWT
        $this->db->query('ALTER TABLE users ADD INDEX idx_users_uuid (uuid)');

        // Acelera: WHERE user_id = ? en clients — usado en TODOS los endpoints de client_admin
        $this->db->query('ALTER TABLE clients ADD INDEX idx_clients_user_id (user_id)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE users DROP INDEX idx_users_uuid');
        $this->db->query('ALTER TABLE clients DROP INDEX idx_clients_user_id');
    }
}
