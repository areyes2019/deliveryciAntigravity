<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class DropIsActiveFromUsers extends Migration
{
    public function up()
    {
        // Drop is_active column from users table
        if ($this->db->fieldExists('is_active', 'users')) {
            $this->forge->dropColumn('users', 'is_active');
        }
    }

    public function down()
    {
        // Restore is_active (for rollback)
        if (!$this->db->fieldExists('is_active', 'users')) {
            $this->forge->addColumn('users', [
                'is_active' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                    'after'      => 'role',
                ],
            ]);
        }
    }
}
