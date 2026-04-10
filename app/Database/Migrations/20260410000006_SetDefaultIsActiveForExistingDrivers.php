<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SetDefaultIsActiveForExistingDrivers extends Migration
{
    public function up()
    {
        // Set is_active = 1 for all existing drivers (default to online)
        $this->db->query("UPDATE drivers SET is_active = 1 WHERE is_active IS NULL OR is_active = 0");
    }

    public function down()
    {
        // No need to revert this data migration
    }
}
