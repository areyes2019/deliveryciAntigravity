<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class DropIsSuspendedFromDrivers extends Migration
{
    public function up()
    {
        // Drop is_suspended column from drivers table
        if ($this->db->fieldExists('is_suspended', 'drivers')) {
            $this->forge->dropColumn('drivers', 'is_suspended');
        }
    }

    public function down()
    {
        // Restore is_suspended (for rollback)
        if (!$this->db->fieldExists('is_suspended', 'drivers')) {
            $this->forge->addColumn('drivers', [
                'is_suspended' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
            ]);
        }
    }
}
