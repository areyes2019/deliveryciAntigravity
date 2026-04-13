<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIncrementPriceToPricingZones extends Migration
{
    public function up()
    {
        $this->forge->addColumn('pricing_zones', [
            'increment_price' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'base_price',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('pricing_zones', ['increment_price']);
    }
}
