<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPaymentFieldsToOrders extends Migration
{
    public function up()
    {
        $this->forge->addColumn('orders', [
            'product_amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => true,
                'default'    => null,
                'after'      => 'cost',
            ],
            'total_to_collect' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'product_amount',
            ],
            'paid' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'total_to_collect',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('orders', ['product_amount', 'total_to_collect', 'paid']);
    }
}
