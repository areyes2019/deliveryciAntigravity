<?php

namespace App\Services;

use App\Models\DriverBillingConfigModel;
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
        ?string $description = null,
        string $walletType = WalletMovementModel::WALLET_EARNINGS
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
            'wallet_type'    => $walletType,
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
            "Ingreso por viaje finalizado #{$tripId}",
            WalletMovementModel::WALLET_EARNINGS
        );
    }

    /**
     * Admin loads guarantee credits onto a driver's prepaid balance.
     */
    public function addGuaranteeRecharge(int $driverId, float $amount, ?string $description = 'Recarga de saldo'): int
    {
        $amount = round(abs($amount), 2);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Recharge amount must be greater than zero.');
        }

        return $this->addMovement(
            $driverId,
            WalletMovementModel::TYPE_ADJUSTMENT,
            $amount,
            null,
            'recarga',
            $description,
            WalletMovementModel::WALLET_GUARANTEE
        );
    }

    /**
     * Deduct one trip cost from the guarantee balance.
     * Only acts when the client's billing scheme is "credito".
     * Uses precio_credito from driver_billing_config as the deduction amount.
     * Returns 0 (no-op) when scheme is not "credito" or config is missing.
     */
    public function deductGuaranteeForTrip(int $driverId, int $tripId, int $clientId): int
    {
        $billingModel = new DriverBillingConfigModel();
        $billing      = $billingModel->getByClient($clientId);

        if (!$billing || $billing['tipo_esquema'] !== 'credito') {
            return 0; // esquema porcentaje u otro: no descontar garantía
        }

        $precio = round((float)($billing['precio_credito'] ?? 0), 2);

        if ($precio <= 0) {
            return 0;
        }

        return $this->addMovement(
            $driverId,
            WalletMovementModel::TYPE_COMMISSION,
            -$precio,
            $tripId,
            'viaje',
            "Consumo de crédito por viaje #{$tripId}",
            WalletMovementModel::WALLET_GUARANTEE
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

    public function getEarningsBalance(int $driverId): float
    {
        return $this->walletModel->getEarningsBalance($driverId);
    }

    public function getGuaranteeBalance(int $driverId): float
    {
        return $this->walletModel->getGuaranteeBalance($driverId);
    }

    public function getMovements(int $driverId, int $limit = 50): array
    {
        return $this->walletModel->getMovementsByDriver($driverId, $limit);
    }

    public function getTodayStats(int $driverId): array
    {
        return $this->walletModel->getTodayStats($driverId);
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
