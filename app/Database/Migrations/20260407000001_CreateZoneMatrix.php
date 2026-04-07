<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateZoneMatrix extends Migration
{
    public function up()
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'client_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'origin_zone_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'destination_zone_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            // NULL = use auto rule (max), NOT NULL = manual override
            'price' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => true,
                'default'    => null,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['client_id', 'origin_zone_id', 'destination_zone_id']);
        $this->forge->addForeignKey('client_id', 'clients', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('origin_zone_id', 'pricing_zones', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('destination_zone_id', 'pricing_zones', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('zone_pricing_matrix', true);
    }

    public function down()
    {
        $this->forge->dropTable('zone_pricing_matrix', true);
    }
}
