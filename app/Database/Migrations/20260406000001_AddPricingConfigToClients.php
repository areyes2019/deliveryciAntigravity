<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPricingConfigToClients extends Migration
{
    public function up()
    {
        $fields = [
            'pricing_mode' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'default'    => 'distance',
                'after'      => 'cost_per_trip'
            ],
            'base_fare' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'pricing_mode'
            ],
            'price_per_km' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'base_fare'
            ],
        ];
        $this->forge->addColumn('clients', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('clients', ['pricing_mode', 'base_fare', 'price_per_km']);
    }
}
