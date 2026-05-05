<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de clientes (empresas) del sistema.
 *
 * Cada cliente representa una empresa que contrata el servicio de delivery.
 * Está vinculado a un usuario con rol `client_admin` a través de `user_id`.
 *
 * Campos de configuración de tarifas:
 * - `pricing_mode`    : modo de cobro ('distance' por km o 'zones' por zonas geográficas).
 * - `cost_per_trip`   : tarifa fija por viaje (modo distance).
 * - `base_fare`       : tarifa base (modo distance).
 * - `price_per_km`    : precio por kilómetro recorrido (modo distance).
 * - `min_distance_km` : distancia mínima cobrable; viajes más cortos pagan como si fueran este valor.
 *
 * Campos financieros:
 * - `credits_balance` : saldo de créditos disponibles para pagar viajes.
 * - `sms_enabled`     : indica si el cliente tiene activas las notificaciones SMS al destinatario.
 *
 * Comportamientos automáticos:
 * - Genera un UUID único antes de cada INSERT.
 */
class ClientModel extends Model
{
    protected $table            = 'clients';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['uuid', 'user_id', 'business_name', 'credits_balance', 'cost_per_trip', 'pricing_mode', 'base_fare', 'price_per_km', 'min_distance_km'];

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
