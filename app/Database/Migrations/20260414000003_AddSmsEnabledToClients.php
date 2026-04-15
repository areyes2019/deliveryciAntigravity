<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSmsEnabledToClients extends Migration
{
    public function up()
    {
        $this->forge->addColumn('clients', [
            'sms_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'default'    => 0,
                'after'      => 'business_name',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('clients', 'sms_enabled');
    }
}
