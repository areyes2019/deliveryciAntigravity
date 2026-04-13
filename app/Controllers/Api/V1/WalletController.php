<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\DriverBillingConfigModel;
use App\Models\DriverModel;
use App\Services\WalletService;
use App\Traits\ApiResponseTrait;
use Config\Database;

class WalletController extends BaseController
{
    use ApiResponseTrait;

    private $walletService;
    private $driverModel;

    public function __construct()
    {
        $this->walletService = new WalletService();
        $this->driverModel = new DriverModel();
    }

    /**
     * POST /api/v1/wallet/withdraw
     * (Admin only)
     */
    public function withdraw()
    {
        $rules = [
            'driver_id' => 'required|numeric',
            'amount'    => 'required|numeric|greater_than[0]',
            'description' => 'permit_empty|string'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $input = $this->request->getJSON(true) ?? $this->request->getPost();

        try {
            $this->walletService->addWithdrawal(
                (int)$input['driver_id'],
                (float)$input['amount'],
                $input['description'] ?? 'Retiro de efectivo / Liquidación'
            );
            return $this->respondSuccess('Withdrawal recorded successfully.');
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    /**
     * POST /api/v1/wallet/add-income
     * (Admin only)
     */
    public function addIncome()
    {
        $rules = [
            'driver_id' => 'required|numeric',
            'amount'    => 'required|numeric|greater_than[0]',
            'description' => 'required|string'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $input = $this->request->getJSON(true) ?? $this->request->getPost();

        try {
            $this->walletService->addMovement(
                (int)$input['driver_id'],
                'ajuste',
                (float)$input['amount'],
                null,
                'manual',
                $input['description']
            );
            return $this->respondSuccess('Income adjustment recorded successfully.');
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    /**
     * GET /api/v1/wallet/balance/{driver_id}
     */
    public function getBalance($driverId)
    {
        $userData = $this->request->jwtPayload;

        // Security: If the requester is a driver, verify they are requesting THEIR OWN balance
        if ($userData['role'] === 'driver') {
            $driver = $this->driverModel->where('user_id', $userData['id'])->first();
            if (!$driver || $driver['id'] != $driverId) {
                return $this->respondUnauthorized('Access denied. You can only view your own balance.');
            }
        }

        try {
            $balance = $this->walletService->getBalance((int)$driverId);
            return $this->respondSuccess('Balance retrieved.', ['balance' => $balance]);
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    /**
     * POST /api/v1/wallet/recharge
     * Client admin carga saldo a uno de sus conductores.
     *
     * Valida que el admin tenga suficiente saldo en clients.credits_balance
     * antes de asignar créditos al conductor, y descuenta el monto del pool
     * del admin en la misma transacción.
     */
    public function recharge()
    {
        $userData = $this->request->jwtPayload;
        if ($userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $input    = $this->request->getJSON(true) ?? $this->request->getPost();
        $amount   = round((float)($input['amount'] ?? 0), 2);
        $driverId = (int)($input['driver_id'] ?? 0);

        if (!$driverId || $amount <= 0) {
            return $this->respondError('driver_id y amount (> 0) son requeridos.');
        }

        $clientModel = new ClientModel();
        $client      = $clientModel->where('user_id', $userData['id'])->first();

        if (!$client) {
            return $this->respondError('Perfil de empresa no encontrado.');
        }

        // Verificar que el conductor pertenece a esta empresa
        $driver = $this->driverModel->find($driverId);
        if (!$driver || $driver['client_id'] != $client['id']) {
            return $this->respondUnauthorized('El conductor no pertenece a tu empresa.');
        }

        // ── Validación de saldo del admin ─────────────────────────────────────
        $adminBalance = (float)$client['credits_balance'];
        $billingModel = new DriverBillingConfigModel();
        $billing      = $billingModel->getByClient($client['id']);

        if ($billing && $billing['tipo_esquema'] === 'credito') {
            $precioPorViaje  = (float)($billing['precio_credito'] ?? 0);
            $viajesAsignados = $precioPorViaje > 0 ? floor($amount / $precioPorViaje) : 0;
            $viajesAdmin     = $precioPorViaje > 0 ? floor($adminBalance / $precioPorViaje) : 0;

            if ($viajesAsignados > $viajesAdmin) {
                return $this->respondError(
                    "Saldo insuficiente. Intentas asignar {$viajesAsignados} viajes " .
                    "pero solo tienes {$viajesAdmin} disponibles " .
                    "(\${$adminBalance} a \${$precioPorViaje}/viaje)."
                );
            }
        } else {
            // Esquema porcentaje u otro: validar directamente en pesos
            if ($amount > $adminBalance) {
                return $this->respondError(
                    "Saldo insuficiente del admin. Disponible: \${$adminBalance}, solicitado: \${$amount}."
                );
            }
        }

        // ── Operación atómica: recargar driver + descontar admin ──────────────
        $db = Database::connect();
        $db->transStart();

        try {
            $this->walletService->addGuaranteeRecharge(
                $driverId,
                $amount,
                $input['description'] ?? 'Recarga de saldo'
            );

            $clientModel->update($client['id'], [
                'credits_balance' => round($adminBalance - $amount, 2),
            ]);
        } catch (\Exception $e) {
            $db->transRollback();
            return $this->respondError($e->getMessage());
        }

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Error en la transacción. Intenta de nuevo.');
        }

        return $this->respondSuccess('Saldo cargado correctamente.', [
            'guarantee_balance' => $this->walletService->getGuaranteeBalance($driverId),
            'admin_balance'     => round($adminBalance - $amount, 2),
        ]);
    }

    /**
     * GET /api/v1/wallet/today/{driver_id}
     */
    public function getTodayStats($driverId)
    {
        $userData = $this->request->jwtPayload;

        if ($userData['role'] === 'driver') {
            $driver = $this->driverModel->where('user_id', $userData['id'])->first();
            if (!$driver || $driver['id'] != $driverId) {
                return $this->respondUnauthorized('Access denied.');
            }
        }

        try {
            $stats = $this->walletService->getTodayStats((int)$driverId);

            $guaranteeBalance = $this->walletService->getGuaranteeBalance((int)$driverId);
            $stats['guarantee_balance'] = $guaranteeBalance;

            // Calcular viajes disponibles según el esquema del cliente
            $driver  = $this->driverModel->find((int)$driverId);
            $billing = $driver
                ? (new \App\Models\DriverBillingConfigModel())->getByClient($driver['client_id'])
                : null;

            if ($billing && $billing['tipo_esquema'] === 'credito') {
                $precio = (float)($billing['precio_credito'] ?? 0);
                $stats['viajes_disponibles'] = $precio > 0 ? (int)floor($guaranteeBalance / $precio) : 0;
            } else {
                $stats['viajes_disponibles'] = null; // esquema porcentaje: no aplica
            }

            return $this->respondSuccess('Today stats retrieved.', $stats);
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }

    /**
     * GET /api/v1/wallet/movements/{driver_id}
     */
    public function getMovements($driverId)
    {
        $userData = $this->request->jwtPayload;

        // Security: If requester is a driver, verify it's for themselves
        if ($userData['role'] === 'driver') {
            $driver = $this->driverModel->where('user_id', $userData['id'])->first();
            if (!$driver || $driver['id'] != $driverId) {
                return $this->respondUnauthorized('Access denied. You can only view your own movements.');
            }
        }

        try {
            $movements = $this->walletService->getMovements((int)$driverId);
            return $this->respondSuccess('Movements retrieved.', ['movements' => $movements]);
        } catch (\Exception $e) {
            return $this->respondError($e->getMessage());
        }
    }
}
