<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWalletMovements extends Migration
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
            'driver_id' => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => ['ingreso', 'retiro', 'ajuste', 'comision'],
            ],
            'amount' => [
                'type'       => 'DECIMAL',
                'constraint' => '12,2',
            ],
            'reference_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
            ],
            'reference_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'description' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addKey('driver_id');
        $this->forge->addKey('created_at');
        $this->forge->addKey(['driver_id', 'created_at'], false, false, 'idx_wallet_driver_created');
        $this->forge->addKey(['reference_type', 'reference_id'], false, false, 'idx_wallet_reference');
        $this->forge->addUniqueKey(['driver_id', 'type', 'reference_type', 'reference_id'], 'uq_wallet_driver_type_reference');
        
        // Foreign Key to Drivers
        $this->forge->addForeignKey('driver_id', 'drivers', 'id', 'CASCADE', 'CASCADE');
        
        $this->forge->createTable('wallet_movements');
    }

    public function down()
    {
        $this->forge->dropTable('wallet_movements');
    }
}
