<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de órdenes de entrega.
 *
 * Es la entidad central del sistema. Representa un viaje de delivery desde
 * un punto de recogida (pickup) hasta un punto de entrega (drop).
 *
 * Ciclo de vida — campo `status`:
 *   pendiente → publicado → tomado → arribado → en_camino → entregado
 *                                                          → rechazado
 *                        → cancelado
 *
 * Campos de ubicación:
 * - `pickup_lat/lng/address` : coordenadas y dirección de recogida.
 * - `drop_lat/lng/address`   : coordenadas y dirección de entrega.
 *
 * Campos del destinatario:
 * - `receiver_name`  : nombre de quien recibe el paquete.
 * - `receiver_phone` : teléfono del destinatario (usado para SMS si está habilitado).
 *
 * Campos financieros:
 * - `payment_type`    : 'prepaid' (el cliente paga con créditos), 'cash_on_delivery'
 *                       (el driver cobra solo el servicio en efectivo) o 'cash_full'
 *                       (el driver cobra servicio + valor del producto).
 * - `cost`            : costo del servicio de delivery calculado al crear la orden.
 * - `distance_km`     : distancia en kilómetros entre pickup y drop.
 * - `product_amount`  : valor del producto (solo para payment_type = cash_full).
 * - `total_to_collect`: total que el conductor debe cobrar al entregar.
 * - `paid`            : indica si la orden ya fue liquidada.
 *
 * Comportamientos automáticos:
 * - Genera un UUID único antes de cada INSERT.
 */
class OrderModel extends Model
{
    protected $table            = 'orders';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'uuid', 'client_id', 'driver_id', 'pickup_lat', 'pickup_lng', 'pickup_address',
        'drop_lat', 'drop_lng', 'drop_address', 'receiver_name', 'receiver_phone',
        'description', 'scheduled_at', 'status', 'payment_type', 'cost', 'distance_km', 'product_amount',
        'total_to_collect', 'paid'
    ];

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
