<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexWalletMovementsWalletType extends Migration
{
    public function up(): void
    {
        // Acelera: WHERE driver_id = ? AND wallet_type = ?
        // Usado en getGuaranteeBalance(), getEarningsBalance() y el JOIN de saldo en DriverController
        $this->db->query(
            'ALTER TABLE wallet_movements ADD INDEX idx_wm_driver_wallet_type (driver_id, wallet_type)'
        );
    }

    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE wallet_movements DROP INDEX idx_wm_driver_wallet_type'
        );
    }
}
