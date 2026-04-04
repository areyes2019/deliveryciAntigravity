<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class Orders extends Migration
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
            'uuid' => [
                'type'       => 'VARCHAR',
                'constraint' => 36,
                'unique'     => true,
            ],
            'client_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'driver_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'pickup_lat' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,8',
            ],
            'pickup_lng' => [
                'type'       => 'DECIMAL',
                'constraint' => '11,8',
            ],
            'pickup_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'drop_lat' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,8',
            ],
            'drop_lng' => [
                'type'       => 'DECIMAL',
                'constraint' => '11,8',
            ],
            'drop_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pendiente', 'publicado', 'tomado', 'en_camino', 'entregado', 'rechazado', 'cancelado'],
                'default'    => 'pendiente',
            ],
            'payment_type' => [
                'type'       => 'ENUM',
                'constraint' => ['prepaid', 'cash_on_delivery', 'cash_full'],
                'default'    => 'prepaid',
            ],
            'cost' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'default'    => 0.00,
            ],
            'distance_km' => [
                'type'       => 'DECIMAL',
                'constraint' => '8,2',
                'default'    => 0.00,
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
        $this->forge->addForeignKey('client_id', 'clients', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('driver_id', 'drivers', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('orders', true);
    }

    public function down()
    {
        $this->forge->dropTable('orders', true);
    }
}
