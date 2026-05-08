<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddScheduledAtToOrders extends Migration
{
    public function up()
    {
        $this->forge->addColumn('orders', [
            'scheduled_at' => [
                'type'    => 'DATETIME',
                'null'    => true,
                'default' => null,
                'after'   => 'description',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('orders', 'scheduled_at');
    }
}
