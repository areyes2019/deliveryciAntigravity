<?php

namespace App\Services;

use App\Models\ClientModel;
use App\Models\CreditTransactionModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Servicio de créditos del cliente.
 *
 * Gestiona el saldo de créditos (`credits_balance`) de cada cliente.
 * Cada operación actualiza el saldo en la tabla `clients` y deja
 * trazabilidad en la tabla `credit_transactions`.
 *
 * Este servicio es llamado exclusivamente por OrderService durante
 * la creación y cancelación de órdenes.
 */
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

    /**
     * Descuenta créditos del cliente al crear una orden.
     *
     * Resta `cost_per_trip` del saldo actual del cliente y registra
     * la transacción como tipo `deduction` en credit_transactions.
     *
     * @param  int    $clientId    ID del cliente al que se le descuentan créditos.
     * @param  int    $orderId     ID de la orden asociada al descuento.
     * @param  string $description Motivo del descuento (para auditoría).
     * @return bool   false si el cliente no existe o no tiene saldo suficiente.
     */
    public function deductCredit(int $clientId, int $orderId, string $description = 'Credit deducted for new order'): bool
    {
        $client = $this->clientModel->find($clientId);
        $costPerTrip = (float)($client['cost_per_trip'] ?? 1);
        
        if (!$client || $client['credits_balance'] < $costPerTrip) {
            return false;
        }

        $newBalance = $client['credits_balance'] - $costPerTrip;
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

    /**
     * Devuelve créditos al cliente al cancelar o rechazar una orden.
     *
     * Suma `cost_per_trip` de vuelta al saldo del cliente y registra
     * la transacción como tipo `refund` en credit_transactions.
     *
     * @param  int    $clientId    ID del cliente al que se le devuelven créditos.
     * @param  int    $orderId     ID de la orden asociada al reembolso.
     * @param  string $description Motivo del reembolso (para auditoría).
     * @return bool   false si el cliente no existe.
     */
    public function refundCredit(int $clientId, int $orderId, string $description = 'Credit refunded for canceled/rejected order'): bool
    {
        $client = $this->clientModel->find($clientId);
        if (!$client) {
            return false;
        }
        
        $costPerTrip = (float)($client['cost_per_trip'] ?? 1);
        $newBalance = $client['credits_balance'] + $costPerTrip;
        $this->clientModel->update($clientId, ['credits_balance' => $newBalance]);

        $this->transactionModel->insert([
            'client_id'        => $clientId,
            'order_id'         => $orderId,
            'amount'           => $costPerTrip,
            'transaction_type' => 'refund',
            'description'      => $description,
            'created_at'       => date('Y-m-d H:i:s')
        ]);

        return true;
    }
}
