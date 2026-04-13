<?php

namespace App\Services;

use App\Models\DriverModel;
use App\Models\WalletMovementModel;

class WalletService
{
    private WalletMovementModel $walletModel;
    private DriverModel $driverModel;

    public function __construct()
    {
        $this->walletModel = new WalletMovementModel();
        $this->driverModel = new DriverModel();
    }

    public function addMovement(
        int $driverId,
        string $type,
        float $amount,
        ?int $referenceId = null,
        string $referenceType = 'manual',
        ?string $description = null
    ): int {
        if ($amount === 0.0) {
            throw new \InvalidArgumentException('The amount cannot be zero.');
        }

        if (!$this->driverModel->find($driverId)) {
            throw new \RuntimeException("Driver with ID {$driverId} not found.");
        }

        $normalizedType = trim(mb_strtolower($type));
        $normalizedReferenceType = trim(mb_strtolower($referenceType));
        $normalizedAmount = round($amount, 2);

        $this->assertAmountMatchesType($normalizedType, $normalizedAmount);

        if (
            $normalizedType === WalletMovementModel::TYPE_INCOME
            && $normalizedReferenceType === 'viaje'
            && $referenceId !== null
        ) {
            $existing = $this->walletModel->findTripIncome($driverId, $referenceId);
            if ($existing) {
                return (int)$existing['id'];
            }
        }

        $insertId = $this->walletModel->insert([
            'driver_id'      => $driverId,
            'type'           => $normalizedType,
            'amount'         => $normalizedAmount,
            'reference_id'   => $referenceId,
            'reference_type' => $normalizedReferenceType,
            'description'    => $description,
        ]);

        if ($insertId === false) {
            throw new \RuntimeException(implode(' ', $this->walletModel->errors()));
        }

        return (int)$insertId;
    }

    public function addIncomeFromTrip(int $driverId, int $tripId, float $amount): int
    {
        $amount = round(abs($amount), 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException("Trip {$tripId} has no collectible amount to register in wallet.");
        }

        return $this->addMovement(
            $driverId,
            WalletMovementModel::TYPE_INCOME,
            $amount,
            $tripId,
            'viaje',
            "Ingreso por viaje finalizado #{$tripId}"
        );
    }

    /**
     * Specialized method for driver withdrawal (liquidation)
     */
    public function addWithdrawal(int $driverId, float $amount, ?string $description = 'Retiro de efectivo / Liquidación')
    {
        // Amount should be negative for withdrawals in a ledger system
        $amount = -abs($amount);
        
        return $this->addMovement(
            $driverId, 
            'retiro', 
            $amount, 
            null, 
            'manual', 
            $description
        );
    }

    public function getBalance(int $driverId): float
    {
        return $this->walletModel->getBalance($driverId);
    }

    public function getMovements(int $driverId, int $limit = 50): array
    {
        return $this->walletModel->getMovementsByDriver($driverId, $limit);
    }

    private function assertAmountMatchesType(string $type, float $amount): void
    {
        if ($type === WalletMovementModel::TYPE_INCOME && $amount < 0) {
            throw new \InvalidArgumentException('Income movements must be positive.');
        }

        if ($type === WalletMovementModel::TYPE_WITHDRAWAL && $amount > 0) {
            throw new \InvalidArgumentException('Withdrawal movements must be negative.');
        }
    }
}
