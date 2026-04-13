<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateDriverBillingConfig extends Migration
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
            'tipo_esquema' => [
                'type'       => 'ENUM',
                'constraint' => ['credito', 'porcentaje'],
            ],
            'precio_credito' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,2',
                'null'       => true,
                'default'    => null,
            ],
            'porcentaje_comision' => [
                'type'       => 'DECIMAL',
                'constraint' => '5,2',
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
        $this->forge->addUniqueKey('client_id', 'uq_billing_client');
        $this->forge->addForeignKey('client_id', 'clients', 'id', 'CASCADE', 'CASCADE');

        $this->forge->createTable('driver_billing_config');
    }

    public function down()
    {
        $this->forge->dropTable('driver_billing_config');
    }
}
