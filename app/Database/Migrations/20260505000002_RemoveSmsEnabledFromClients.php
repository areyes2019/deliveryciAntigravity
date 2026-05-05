<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RemoveSmsEnabledFromClients extends Migration
{
    public function up()
    {
        $this->forge->dropColumn('clients', 'sms_enabled');
    }

    public function down()
    {
        // Restaura la columna con default 1 (siempre habilitado) para no romper la lógica antigua
        $this->forge->addColumn('clients', [
            'sms_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'default'    => 1,
                'after'      => 'business_name',
            ],
        ]);
    }
}
