<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class EnableSmsForExistingClients extends Migration
{
    public function up()
    {
        $this->db->query('UPDATE clients SET sms_enabled = 1 WHERE sms_enabled = 0');
    }

    public function down()
    {
        $this->db->query('UPDATE clients SET sms_enabled = 0');
    }
}
