<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RenameCostColumn extends Migration
{
    public function up()
    {
        $fields = [
            'cost_per_km' => [
                'name' => 'cost_per_trip',
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => 0.00,
            ],
        ];
        $this->forge->modifyColumn('clients', $fields);
    }

    public function down()
    {
        $fields = [
            'cost_per_trip' => [
                'name' => 'cost_per_km',
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => 0.00,
            ],
        ];
        $this->forge->modifyColumn('clients', $fields);
    }
}
