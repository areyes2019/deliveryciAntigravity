<?php

namespace App\Services;

use App\Models\DriverBillingConfigModel;
use App\Models\DriverModel;
use App\Models\WalletMovementModel;

/**
 * Servicio de billetera del conductor.
 *
 * Gestiona todos los movimientos económicos asociados a un conductor.
 * La billetera tiene dos bolsillos independientes:
 *
 * - `guarantee` (garantía): saldo precargado por el administrador.
 *   Se descuenta con cada viaje en el esquema `credito`.
 * - `earnings`  (ganancias): dinero ganado al completar viajes.
 *   Se acumula en el esquema `porcentaje`.
 *
 * Todas las operaciones pasan por `addMovement()` que valida tipos,
 * signos de montos y evita duplicados en ingresos por viaje.
 *
 * Es invocado principalmente por DriverApiController al completar viajes
 * y por WalletController para recargas, retiros y consultas.
 */
class WalletService
{
    private WalletMovementModel $walletModel;
    private DriverModel $driverModel;

    public function __construct()
    {
        $this->walletModel = new WalletMovementModel();
        $this->driverModel = new DriverModel();
    }

    /**
     * Registra un movimiento genérico en la billetera del conductor.
     *
     * Es el método base al que llaman todos los demás. Aplica validaciones:
     * - El monto no puede ser cero.
     * - El conductor debe existir.
     * - Los ingresos (`ingreso`) deben ser positivos.
     * - Los retiros (`retiro`) deben ser negativos.
     * - Si es un ingreso por viaje, verifica que no exista ya un registro
     *   para ese viaje (idempotencia — evita cobros dobles).
     *
     * @param  int         $driverId      ID del conductor.
     * @param  string      $type          Tipo: 'ingreso', 'retiro', 'ajuste', 'comision'.
     * @param  float       $amount        Monto (positivo o negativo según el tipo).
     * @param  int|null    $referenceId   ID de la entidad origen (ej. ID de la orden).
     * @param  string      $referenceType Tipo de entidad origen (ej. 'viaje', 'manual').
     * @param  string|null $description   Descripción libre del movimiento.
     * @param  string      $walletType    Bolsillo destino: 'earnings' o 'guarantee'.
     * @return int         ID del registro insertado (o existente si ya estaba duplicado).
     * @throws \InvalidArgumentException Si el monto es cero o el signo no coincide con el tipo.
     * @throws \RuntimeException         Si el conductor no existe o falla la inserción.
     */
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

    /**
     * Registra el ingreso del conductor al completar un viaje.
     *
     * Deposita el monto en el bolsillo `earnings`. Es idempotente:
     * si ya existe un ingreso para ese tripId, retorna el ID existente
     * sin crear un duplicado.
     *
     * @param  int   $driverId ID del conductor.
     * @param  int   $tripId   ID de la orden completada.
     * @param  float $amount   Monto ganado (debe ser > 0).
     * @return int   ID del movimiento registrado.
     * @throws \InvalidArgumentException Si el monto es 0 o negativo.
     */
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
    /**
     * Descuenta el costo de un viaje del saldo de garantía del conductor.
     *
     * Solo actúa si el cliente tiene configurado el esquema `credito`.
     * En el esquema `porcentaje` este método es un no-op (retorna 0).
     * El monto descontado es `precio_credito` de la configuración de billing.
     *
     * @param  int $driverId ID del conductor.
     * @param  int $tripId   ID del viaje aceptado.
     * @param  int $clientId ID del cliente (para leer la configuración de billing).
     * @return int ID del movimiento insertado, o 0 si no aplica el descuento.
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
     * Registra un retiro o liquidación del conductor.
     *
     * El monto se convierte automáticamente a negativo (salida de dinero).
     * Se registra en el bolsillo `earnings` por defecto.
     * Usado cuando el administrador liquida al conductor en efectivo.
     *
     * @param  int         $driverId    ID del conductor.
     * @param  float       $amount      Monto a retirar (se convierte a negativo internamente).
     * @param  string|null $description Descripción del retiro.
     * @return int         ID del movimiento registrado.
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

    /**
     * Valida que el signo del monto sea coherente con el tipo de movimiento.
     * Los ingresos deben ser positivos; los retiros, negativos.
     *
     * @throws \InvalidArgumentException Si el signo no coincide con el tipo.
     */
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
