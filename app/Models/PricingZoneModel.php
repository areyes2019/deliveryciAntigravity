<?php

namespace App\Models;

use CodeIgniter\Model;

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
