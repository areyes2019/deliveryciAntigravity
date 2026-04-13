<?php

namespace App\Models;

use CodeIgniter\Model;

class WalletMovementModel extends Model
{
    public const TYPE_INCOME = 'ingreso';
    public const TYPE_WITHDRAWAL = 'retiro';
    public const TYPE_ADJUSTMENT = 'ajuste';
    public const TYPE_COMMISSION = 'comision';

    protected $table            = 'wallet_movements';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'driver_id', 'type', 'amount', 'reference_id', 
        'reference_type', 'description'
    ];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $validationRules = [
        'driver_id'      => 'required|integer|greater_than[0]',
        'type'           => 'required|in_list[ingreso,retiro,ajuste,comision]',
        'amount'         => 'required|decimal',
        'reference_id'   => 'permit_empty|integer|greater_than[0]',
        'reference_type' => 'permit_empty|max_length[50]',
        'description'    => 'permit_empty|string',
    ];

    public function findTripIncome(int $driverId, int $tripId): ?array
    {
        $movement = $this->where([
                'driver_id'      => $driverId,
                'type'           => self::TYPE_INCOME,
                'reference_type' => 'viaje',
                'reference_id'   => $tripId,
            ])
            ->first();

        return $movement ?: null;
    }

    /**
     * Get real-time balance for a driver by summing amounts
     */
    public function getBalance(int $driverId): float
    {
        $result = $this->builder()
            ->select('COALESCE(SUM(amount), 0) AS total_balance', false)
            ->where('driver_id', $driverId)
            ->get()
            ->getRowArray();
                       
        return (float)($result['total_balance'] ?? 0.00);
    }

    /**
     * Get all movements for a driver, ordered by most recent
     */
    public function getMovementsByDriver(int $driverId, int $limit = 50)
    {
        $limit = max(1, min($limit, 200));

        return $this->where('driver_id', $driverId)
                    ->orderBy('created_at', 'DESC')
                    ->findAll($limit);
    }
}
