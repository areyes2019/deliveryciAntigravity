<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Modelo de transacciones de créditos del cliente.
 *
 * Registra cada movimiento del saldo de créditos (`credits_balance`) de un cliente.
 * Funciona como un libro contable: cada fila explica por qué el saldo subió o bajó.
 *
 * Tipos de transacción (`transaction_type`):
 * - `recharge` : el superadmin asignó créditos al cliente manualmente.
 * - `deduction`: se descontaron créditos al crear o completar una orden.
 *
 * Campos:
 * - `client_id`        : cliente al que pertenece la transacción.
 * - `order_id`         : orden asociada al movimiento (null para recargas manuales).
 * - `amount`           : monto del movimiento (positivo = recarga, negativo = descuento).
 * - `transaction_type` : tipo de operación (ver arriba).
 * - `description`      : texto libre que explica el motivo del movimiento.
 *
 * Nota: este modelo solo tiene `created_at` (sin `updated_at`) porque las
 * transacciones son inmutables — nunca se modifican, solo se insertan.
 */
class CreditTransactionModel extends Model
{
    protected $table            = 'credit_transactions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = ['client_id', 'order_id', 'amount', 'transaction_type', 'description'];

    // Dates
    protected $useTimestamps = true;
    protected $dateFormat    = 'datetime';
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
}
