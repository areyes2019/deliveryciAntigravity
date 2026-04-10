<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsSuspendedToUsers extends Migration
{
    public function up()
    {
        // Add is_suspended field to users table (for admin suspension of users)
        if (!$this->db->fieldExists('is_suspended', 'users')) {
            $this->forge->addColumn('users', [
                'is_suspended' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                    'after'      => 'is_active',
                    'comment'    => 'Admin suspension status: 1=suspended, 0=active'
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('is_suspended', 'users')) {
            $this->forge->dropColumn('users', 'is_suspended');
        }
    }
}
