<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddReceiverFieldsToOrders extends Migration
{
    public function up()
    {
        $this->forge->addColumn('orders', [
            'receiver_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'drop_address',
            ],
            'receiver_phone' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
                'after'      => 'receiver_name',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('orders', ['receiver_name', 'receiver_phone']);
    }
}
