<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMinDistanceKmToClients extends Migration
{
    public function up()
    {
        $fields = [
            'min_distance_km' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
                'after'      => 'price_per_km',
            ],
        ];
        $this->forge->addColumn('clients', $fields);
    }

    public function down()
    {
        $this->forge->dropColumn('clients', ['min_distance_km']);
    }
}
