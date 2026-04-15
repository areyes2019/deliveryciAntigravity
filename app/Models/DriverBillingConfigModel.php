<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de configuración de facturación al conductor.
 *
 * Define cómo se le cobra a cada conductor por los viajes que realiza.
 * Existe una configuración por cliente (`client_id`), es decir, todos los
 * conductores de un mismo cliente comparten el mismo esquema de cobro.
 *
 * Esquemas disponibles (`tipo_esquema`):
 *
 * - `credito`: el conductor compra créditos por adelantado (garantía).
 *   Cada viaje que acepta descuenta `precio_credito` de su saldo de garantía.
 *   El campo `viajes_disponibles` en DriverController muestra cuántos viajes
 *   puede tomar aún con su saldo actual.
 *
 * - `porcentaje`: el conductor no paga por adelantado.
 *   Al completar un viaje, el sistema retiene `porcentaje_comision`% del valor
 *   del viaje y deposita el resto en la billetera de ganancias del conductor.
 *
 * Métodos propios:
 * - `getByClient()`: obtiene la configuración activa de un cliente por su ID.
 */
class DriverBillingConfigModel extends Model
{
    protected $table            = 'driver_billing_config';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'client_id',
        'tipo_esquema',
        'precio_credito',
        'porcentaje_comision',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    protected $validationRules = [
        'client_id'    => 'required|integer|greater_than[0]',
        'tipo_esquema' => 'required|in_list[credito,porcentaje]',
    ];

    public function getByClient(int $clientId): ?array
    {
        return $this->where('client_id', $clientId)->first();
    }
}
