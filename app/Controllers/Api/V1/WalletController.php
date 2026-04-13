<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\DriverModel;
use App\Services\WalletService;
use App\Traits\ApiResponseTrait;

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
