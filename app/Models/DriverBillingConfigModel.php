<?php

namespace App\Models;

use CodeIgniter\Model;

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
