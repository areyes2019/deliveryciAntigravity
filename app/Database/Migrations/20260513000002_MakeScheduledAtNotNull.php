<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Hace que scheduled_at sea NOT NULL con valor por defecto.
 * 
 * scheduled_at NUNCA debe quedar NULL.
 * Siempre debe contener una fecha/hora válida.
 * Si no se programa, se guarda la fecha/hora actual (envío inmediato).
 */
class MakeScheduledAtNotNull extends Migration
{
    public function up()
    {
        // Primero actualizar los registros existentes que tengan NULL
        $this->db->query('UPDATE orders SET scheduled_at = created_at WHERE scheduled_at IS NULL');

        // Luego modificar la columna para que sea NOT NULL con default
        $this->forge->modifyColumn('orders', [
            'scheduled_at' => [
                'type'    => 'DATETIME',
                'null'    => false,
                'default' => null, // No default en schema, se asigna desde la app
            ],
        ]);
    }

    public function down()
    {
        $this->forge->modifyColumn('orders', [
            'scheduled_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'default' => null,
            ],
        ]);
    }
}
