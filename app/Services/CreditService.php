<?php

namespace App\Services;

use App\Models\ClientModel;
use App\Models\CreditTransactionModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class CreditService
{
    private BaseConnection $db;
    private ClientModel $clientModel;
    private CreditTransactionModel $transactionModel;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->clientModel = new ClientModel();
        $this->transactionModel = new CreditTransactionModel();
    }

    public function deductCredit(int $clientId, int $orderId, string $description = 'Credit deducted for new order'): bool
    {
        $client = $this->clientModel->find($clientId);
        if (!$client || $client['credits_balance'] <= 0) {
            return false;
        }

        $newBalance = $client['credits_balance'] - 1;
        $this->clientModel->update($clientId, ['credits_balance' => $newBalance]);

        $this->transactionModel->insert([
            'client_id'        => $clientId,
            'order_id'         => $orderId,
            'amount'           => -1,
            'transaction_type' => 'deduction',
            'description'      => $description,
            'created_at'       => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    public function refundCredit(int $clientId, int $orderId, string $description = 'Credit refunded for canceled/rejected order'): bool
    {
        $client = $this->clientModel->find($clientId);
        if (!$client) {
            return false;
        }

        $newBalance = $client['credits_balance'] + 1;
        $this->clientModel->update($clientId, ['credits_balance' => $newBalance]);

        $this->transactionModel->insert([
            'client_id'        => $clientId,
            'order_id'         => $orderId,
            'amount'           => 1,
            'transaction_type' => 'refund',
            'description'      => $description,
            'created_at'       => date('Y-m-d H:i:s')
        ]);

        return true;
    }
}
