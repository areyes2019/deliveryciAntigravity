<?php

namespace App\Models;

use CodeIgniter\Model;

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
