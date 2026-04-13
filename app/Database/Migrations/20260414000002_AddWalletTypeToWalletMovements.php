<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddWalletTypeToWalletMovements extends Migration
{
    public function up()
    {
        // 1. Add column with safe default so existing rows get 'earnings'
        $this->forge->addColumn('wallet_movements', [
            'wallet_type' => [
                'type'       => 'ENUM',
                'constraint' => ['earnings', 'guarantee'],
                'default'    => 'earnings',
                'null'       => false,
                'after'      => 'type',
            ],
        ]);

        // 2. Reclassify existing recharges to 'guarantee'
        $this->db->query(
            "UPDATE wallet_movements SET wallet_type = 'guarantee' WHERE reference_type = 'recarga'"
        );
    }

    public function down()
    {
        $this->forge->dropColumn('wallet_movements', 'wallet_type');
    }
}
