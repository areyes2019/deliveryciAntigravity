<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class OrderStatusLog extends Migration
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
            'order_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'previous_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
                'null'       => true,
            ],
            'new_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'log_time' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('order_id', 'orders', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('order_status_log', true);
    }

    public function down()
    {
        $this->forge->dropTable('order_status_log', true);
    }
}
