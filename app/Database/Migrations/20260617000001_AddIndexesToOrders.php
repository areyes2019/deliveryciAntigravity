<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexesToOrders extends Migration
{
    public function up(): void
    {
        // Acelera: WHERE client_id = ? (query principal del dashboard)
        $this->db->query('ALTER TABLE orders ADD INDEX idx_orders_client_id (client_id)');

        // Acelera: WHERE status = 'pendiente' AND scheduled_at <= ? (publishDueOrders)
        $this->db->query('ALTER TABLE orders ADD INDEX idx_orders_status_scheduled (status, scheduled_at)');
    }

    public function down(): void
    {
        $this->db->query('ALTER TABLE orders DROP INDEX idx_orders_client_id');
        $this->db->query('ALTER TABLE orders DROP INDEX idx_orders_status_scheduled');
    }
}
