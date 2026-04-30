<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de geocercas (geofences).
 *
 * Define el área de operación geográfica de un cliente mediante un polígono.
 * Solo existe una geofence por cliente (UNIQUE client_id).
 *
 * `polygon_coordinates` almacena un JSON con la forma:
 *   [{"lat": 20.523, "lng": -100.815}, ...]
 *
 * Esta tabla es independiente de pricing_zones: no contiene precios ni tarifas.
 * Su único propósito es delimitar la zona donde el cliente puede operar.
 */
class GeofenceModel extends Model
{
    protected $table            = 'geofences';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['client_id', 'name', 'polygon_coordinates'];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
