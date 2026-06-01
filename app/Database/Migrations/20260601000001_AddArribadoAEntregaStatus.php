<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddArribadoAEntregaStatus extends Migration
{
    public function up()
    {
        $this->db->query("
            ALTER TABLE orders
            MODIFY COLUMN status ENUM(
                'pendiente',
                'publicado',
                'tomado',
                'arribado',
                'en_camino',
                'arribado_a_entrega',
                'entregado',
                'rechazado',
                'cancelado'
            ) NOT NULL DEFAULT 'pendiente'
        ");
    }

    public function down()
    {
        $this->db->query("
            ALTER TABLE orders
            MODIFY COLUMN status ENUM(
                'pendiente',
                'publicado',
                'tomado',
                'arribado',
                'en_camino',
                'entregado',
                'rechazado',
                'cancelado'
            ) NOT NULL DEFAULT 'pendiente'
        ");
    }
}
