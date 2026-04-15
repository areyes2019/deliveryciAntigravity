<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de conductores (repartidores).
 *
 * Cada conductor pertenece a un cliente específico (`client_id`) y está
 * vinculado a un usuario con rol `driver` a través de `user_id`.
 *
 * Campos de estado y disponibilidad:
 * - `is_active`    : 1 = conductor conectado y disponible para recibir viajes,
 *                    0 = desconectado voluntariamente (toggle desde su app).
 *
 * Campos de ubicación GPS en tiempo real:
 * - `current_lat`  : última latitud conocida del conductor.
 * - `current_lng`  : última longitud conocida del conductor.
 *   Estos campos se actualizan cada vez que el conductor envía su posición
 *   desde la app mediante el endpoint POST /api/v1/driver/location.
 *
 * Nota: la suspensión administrativa se maneja en la tabla `users` mediante
 * el campo `is_suspended`, no aquí. `is_active` es el estado voluntario
 * del conductor; `is_suspended` es el bloqueo impuesto por el administrador.
 *
 * Comportamientos automáticos:
 * - Genera un UUID único antes de cada INSERT.
 */
class DriverModel extends Model
{
    protected $table            = 'drivers';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['uuid', 'user_id', 'client_id', 'phone', 'vehicle_details', 'is_active', 'current_lat', 'current_lng'];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    // Callbacks
    protected $allowCallbacks = true;
    protected $beforeInsert   = ['generateUuid'];

    protected function generateUuid(array $data)
    {
        if (!isset($data['data']['uuid'])) {
            $data['data']['uuid'] = sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
                mt_rand( 0, 0xffff ),
                mt_rand( 0, 0x0fff ) | 0x4000,
                mt_rand( 0, 0x3fff ) | 0x8000,
                mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
            );
        }
        return $data;
    }
}
