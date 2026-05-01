<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class DropIsSuspendedFromDrivers extends Migration
{
    public function up()
    {
        // Already handled by 20260410000002_AddIsActiveToDrivers — no-op kept for history integrity
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
