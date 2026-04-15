<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de movimientos de billetera del conductor.
 *
 * Registra cada transacción económica asociada a un conductor. La billetera
 * está dividida en dos bolsillos independientes controlados por `wallet_type`:
 *
 * - `guarantee` (garantía): saldo precargado por el administrador del cliente.
 *   En el esquema 'credito', cada viaje descuenta un monto fijo de aquí.
 * - `earnings` (ganancias): dinero ganado por el conductor al completar viajes.
 *   En el esquema 'porcentaje', el sistema deposita aquí la parte del conductor.
 *
 * Tipos de movimiento (`type`):
 * - `ingreso`   : entrada de dinero (pago por viaje completado).
 * - `retiro`    : salida de dinero (el conductor retira sus ganancias).
 * - `ajuste`    : corrección manual realizada por el administrador.
 * - `comision`  : descuento de comisión del sistema sobre una ganancia.
 *
 * Trazabilidad: cada movimiento puede referenciar el origen mediante
 * `reference_type` (ej. 'viaje') y `reference_id` (ID de la orden).
 *
 * Métodos propios:
 * - `getBalance()`          : saldo total sumando todos los movimientos del conductor.
 * - `getEarningsBalance()`  : saldo solo de la billetera de ganancias.
 * - `getGuaranteeBalance()` : saldo solo de la billetera de garantía.
 * - `getMovementsByDriver()`: historial de movimientos paginado (máx. 200).
 * - `getTodayStats()`       : ganancias y viajes completados en el día de hoy.
 * - `findTripIncome()`      : busca si ya existe un ingreso registrado para un viaje específico.
 */
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
    public const WALLET_EARNINGS  = 'earnings';
    public const WALLET_GUARANTEE = 'guarantee';

    protected $allowedFields    = [
        'driver_id', 'type', 'wallet_type', 'amount', 'reference_id',
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
     * Total balance across all wallet types (backward compat).
     */
    public function getBalance(int $driverId): float
    {
        $result = $this->builder()
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('driver_id', $driverId)
            ->get()
            ->getRowArray();

        return (float)($result['total'] ?? 0.0);
    }

    /**
     * Earnings balance — money the driver has actually earned from trips.
     */
    public function getEarningsBalance(int $driverId): float
    {
        $result = $this->builder()
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('driver_id', $driverId)
            ->where('wallet_type', self::WALLET_EARNINGS)
            ->get()
            ->getRowArray();

        return (float)($result['total'] ?? 0.0);
    }

    /**
     * Guarantee balance — prepaid credits loaded by the admin.
     */
    public function getGuaranteeBalance(int $driverId): float
    {
        $result = $this->builder()
            ->select('COALESCE(SUM(amount), 0) AS total', false)
            ->where('driver_id', $driverId)
            ->where('wallet_type', self::WALLET_GUARANTEE)
            ->get()
            ->getRowArray();

        return (float)($result['total'] ?? 0.0);
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

    /**
     * Get today's earnings and trip count for a driver (server-side date filter)
     */
    public function getTodayStats(int $driverId): array
    {
        $rows = $this->where('driver_id', $driverId)
                     ->where('type', self::TYPE_INCOME)
                     ->where('DATE(created_at)', date('Y-m-d'))
                     ->findAll();

        $earnings = array_reduce($rows, fn($sum, $m) => $sum + (float)$m['amount'], 0.0);

        return [
            'earnings' => round($earnings, 2),
            'trips'    => count($rows),
        ];
    }
}
