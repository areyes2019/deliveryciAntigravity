<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexOrderStatusLogLogTime extends Migration
{
    public function up(): void
    {
        // Acelera: WHERE log_time >= ? AND log_time <= ? en WalletMovementModel::getTodayStats()
        $this->db->query(
            'ALTER TABLE order_status_log ADD INDEX idx_osl_log_time (log_time)'
        );
    }

    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE order_status_log DROP INDEX idx_osl_log_time'
        );
    }
}
