<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de auditoría de cambios de estado en órdenes.
 *
 * Cada vez que una orden cambia de estado (ej. de 'publicado' a 'tomado'),
 * se inserta una fila en esta tabla registrando el estado anterior y el nuevo.
 * Esto permite reconstruir el historial completo de una orden en cualquier momento.
 *
 * Campos:
 * - `order_id`        : ID de la orden que cambió de estado.
 * - `previous_status` : estado anterior de la orden antes del cambio.
 * - `new_status`      : estado nuevo al que pasó la orden.
 * - `log_time`        : timestamp exacto del cambio.
 *
 * Comportamientos automáticos:
 * - Si no se provee `log_time` al insertar, el callback `setLogTime`
 *   lo rellena automáticamente con la fecha y hora actual del servidor.
 *
 * Este modelo es de solo escritura en la práctica: los registros nunca
 * se modifican ni se eliminan, solo se consultan para auditoría.
 */
class OrderStatusLogModel extends Model
{
    protected $table            = 'order_status_log';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['order_id', 'previous_status', 'new_status', 'log_time'];

    // Callbacks para asegurar que log_time siempre tenga valor
    protected $allowCallbacks = true;
    protected $beforeInsert   = ['setLogTime'];

    /**
     * Rellena automáticamente `log_time` con la fecha/hora actual
     * si no fue proporcionado al insertar el registro.
     */
    protected function setLogTime(array $data)
    {
        if (!isset($data['data']['log_time'])) {
            $data['data']['log_time'] = date('Y-m-d H:i:s');
        }
        return $data;
    }
}
