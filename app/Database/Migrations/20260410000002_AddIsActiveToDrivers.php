<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsActiveToDrivers extends Migration
{
    public function up()
    {
        // Remove is_suspended from drivers table (will be managed by users.is_suspended)
        if ($this->db->fieldExists('is_suspended', 'drivers')) {
            $this->forge->dropColumn('drivers', 'is_suspended');
        }
        
        // Add is_active field to drivers table (for voluntary connection/disconnection)
        if (!$this->db->fieldExists('is_active', 'drivers')) {
            $this->forge->addColumn('drivers', [
                'is_active' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                    'comment'    => 'Driver voluntary online status: 1=online, 0=offline'
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->fieldExists('is_active', 'drivers')) {
            $this->forge->dropColumn('drivers', 'is_active');
        }
        
        // Restore is_suspended
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
