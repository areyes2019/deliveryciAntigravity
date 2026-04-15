<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de la matriz de precios entre zonas.
 *
 * Almacena el precio específico para cada combinación de zona origen → zona destino
 * dentro de un mismo cliente. Permite tarifas asimétricas: el viaje de A→B puede
 * costar diferente que B→A.
 *
 * Cada fila representa: "para el cliente X, un viaje desde la zona Y hacia la zona Z
 * cuesta $precio".
 *
 * Esta tabla es consultada por el PricingService cuando el cliente usa
 * `pricing_mode = 'zones'` y tiene configurada una matriz explícita.
 * Si no existe entrada para una combinación, el sistema usa el precio base
 * de la zona origen como fallback.
 *
 * La combinación (client_id, origin_zone_id, destination_zone_id) es única
 * en la base de datos (índice UNIQUE `zone_pair`).
 *
 * Métodos propios:
 * - `lookupEntry()`: busca el precio para una combinación específica
 *                    cliente + zona origen + zona destino.
 */
class ZoneMatrixModel extends Model
{
    protected $table            = 'zone_pricing_matrix';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'client_id',
        'origin_zone_id',
        'destination_zone_id',
        'price',
    ];

    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';

    /**
     * Look up the matrix entry for a specific origin → destination pair.
     * Returns the row or null if not found.
     */
    public function lookupEntry(int $clientId, int $originZoneId, int $destZoneId): ?array
    {
        return $this
            ->where('client_id', $clientId)
            ->where('origin_zone_id', $originZoneId)
            ->where('destination_zone_id', $destZoneId)
            ->first();
    }
}
