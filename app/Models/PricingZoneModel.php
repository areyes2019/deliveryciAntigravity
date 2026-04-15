<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de zonas geográficas de precios.
 *
 * Cada zona es un polígono geográfico definido por coordenadas GPS que
 * delimita un área del mapa con una tarifa asociada. Solo aplica cuando
 * el cliente tiene `pricing_mode = 'zones'`.
 *
 * Cada cliente puede tener múltiples zonas; cada zona pertenece a un
 * solo cliente (`client_id`).
 *
 * Campos:
 * - `name`                 : nombre descriptivo de la zona (ej. "Centro", "Norte").
 * - `polygon_coordinates`  : array JSON de puntos {lat, lng} que forman el polígono.
 *                            Es procesado por GeoHelper para determinar si un punto
 *                            de pickup o drop cae dentro de esta zona.
 * - `base_price`           : precio base del viaje cuando el origen está en esta zona.
 * - `increment_price`      : precio adicional que se suma al cruzar hacia otra zona.
 *
 * El cálculo del precio final usando zonas se delega al PricingService,
 * que combina este modelo con ZoneMatrixModel y GeoHelper.
 */
class PricingZoneModel extends Model
{
    protected $table            = 'pricing_zones';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['client_id', 'name', 'polygon_coordinates', 'base_price', 'increment_price'];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
}
