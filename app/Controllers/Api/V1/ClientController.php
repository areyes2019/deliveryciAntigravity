<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\UserModel;
use App\Models\ClientModel;
use App\Traits\ApiResponseTrait;
use Config\Database;

class ClientController extends BaseController
{
    use ApiResponseTrait;
    
    /**
     * Devuelve la lista completa de clientes registrados en el sistema.
     *
     * Solo accesible para usuarios con rol `superadmin`. Realiza un JOIN con
     * la tabla `users` para incluir el nombre y correo del administrador
     * asociado a cada cliente.
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function index()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $clientModel = new ClientModel();
        // Join con la tabla users para obtener nombre y correo del admin de cada cliente
        $clients = $clientModel->select('clients.*, users.name as admin_name, users.email as admin_email')
                               ->join('users', 'users.id = clients.user_id')
                               ->findAll();

        return $this->respondSuccess('Clients retrieved successfully.', $clients);
    }

    /**
     * Devuelve los datos de un cliente específico por su ID.
     *
     * Solo accesible para `superadmin`. Incluye el nombre y correo del usuario
     * administrador vinculado al cliente mediante un JOIN con `users`.
     * Retorna 404 si el cliente no existe.
     *
     * @param int|null $id ID del cliente a consultar.
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function show($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $clientModel = new ClientModel();
        $client = $clientModel->select('clients.*, users.name as admin_name, users.email as admin_email')
                              ->join('users', 'users.id = clients.user_id')
                              ->find($id);

        if (!$client) {
            return $this->respondError('Client not found.', [], 404);
        }

        return $this->respondSuccess('Client retrieved successfully.', $client);
    }

    /**
     * Crea un nuevo cliente junto con su usuario administrador asociado.
     *
     * Solo accesible para `superadmin`. El proceso se ejecuta dentro de una
     * transacción de base de datos: primero crea el registro en `users` con
     * rol `client_admin`, y luego crea el registro en `clients` vinculado a
     * ese usuario. Si cualquier paso falla, se hace rollback completo.
     *
     * Campos requeridos: `name`, `email`, `password`, `business_name`, `cost_per_trip`.
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function create()
    {
        try {
            $userData = $this->request->jwtPayload ?? null;
            if (!$userData || $userData['role'] !== 'superadmin') {
                return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
            }

            $input = $this->request->getPost();
            if (empty($input)) {
                $input = $this->request->getJSON(true);
            }

            $rules = [
                'name'          => 'required',
                'email'         => 'required|valid_email|is_unique[users.email]',
                'password'      => 'required|min_length[6]',
                'business_name' => 'required',
                'cost_per_trip'   => 'required|numeric'
            ];

            if (!$this->validateData($input ?? [], $rules)) {
                return $this->respondError('Validation failed', $this->validator->getErrors());
            }

            $db = Database::connect();
            $db->transStart();

            $userModel = new UserModel();
            $userId = $userModel->insert([
                'name'      => $input['name'],
                'email'     => $input['email'],
                'password'  => $input['password'],
                'role'      => 'client_admin'
            ]);

            if (!$userId) {
                $db->transRollback();
                return $this->respondError('Error creating user profile in database.');
            }

            $clientModel = new ClientModel();
            $clientId = $clientModel->insert([
                'user_id'         => $userId,
                'business_name'   => $input['business_name'],
                'credits_balance' => 0,
                'cost_per_trip'     => $input['cost_per_trip']
            ]);

            if (!$clientId) {
                $db->transRollback();
                return $this->respondError('Error creating client profile in database.');
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                return $this->respondError('Database transaction failed.');
            }

            $clientRecord = $clientModel->find($clientId);
            return $this->respondSuccess('Client created successfully.', $clientRecord, 201);
        } catch (\Throwable $e) {
            return $this->respondError('System Error: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Agrega créditos al saldo de un cliente existente.
     *
     * Solo accesible para `superadmin`. Suma el `amount` indicado al
     * `credits_balance` actual del cliente y registra la operación en la
     * tabla `credit_transactions` como una recarga (`recharge`).
     * El monto debe ser un número entero positivo mayor a cero.
     *
     * @param int|null $id ID del cliente al que se le asignan los créditos.
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function addCredits($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $rules = [
            'amount' => 'required|is_natural_no_zero'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $clientModel = new ClientModel();
        $client = $clientModel->find($id);

        if (!$client) {
            return $this->respondError('Client not found.', [], 404);
        }

        $amount = (int) $this->request->getVar('amount');
        
        $newBalance = $client['credits_balance'] + $amount;
        $clientModel->update($id, ['credits_balance' => $newBalance]);

        $transactionModel = new \App\Models\CreditTransactionModel();
        $transactionModel->insert([
            'client_id'        => $id,
            'order_id'         => null,
            'amount'           => $amount,
            'transaction_type' => 'recharge',
            'description'      => 'Credits assigned by SuperAdmin',
            'created_at'       => date('Y-m-d H:i:s')
        ]);

        return $this->respondSuccess('Credits added successfully.', ['new_balance' => $newBalance]);
    }

    /**
     * Actualiza los datos de un cliente existente.
     *
     * Solo accesible para `superadmin`. Permite modificar `business_name` y
     * `cost_per_trip` en la tabla `clients`. Si se incluye el campo `name`
     * en el payload, también actualiza el nombre del usuario asociado en la
     * tabla `users`. Retorna 404 si el cliente no existe.
     *
     * Campos requeridos: `business_name`, `cost_per_trip`.
     * Campo opcional: `name` (nombre del usuario administrador).
     *
     * @param int|null $id ID del cliente a actualizar.
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function update($id = null)
    {
        try {
            $userData = $this->request->jwtPayload ?? null;
            if (!$userData || $userData['role'] !== 'superadmin') {
                return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
            }

            $input = $this->request->getPost();
            if (empty($input)) {
                $input = $this->request->getJSON(true);
            }

            $clientModel = new ClientModel();
            $client = $clientModel->find($id);

            if (!$client) {
                return $this->respondError('Client not found.', [], 404);
            }

            $rules = [
                'business_name' => 'required',
                'cost_per_trip'   => 'required|numeric'
            ];

            if (!$this->validateData($input ?? [], $rules)) {
                return $this->respondError('Validation failed', $this->validator->getErrors());
            }

            $data = [
                'business_name' => $input['business_name'],
                'cost_per_trip'   => $input['cost_per_trip']
            ];

            $clientModel->update($id, $data);

            // Also update user name if provided
            if (isset($input['name'])) {
                $userModel = new UserModel();
                $userModel->update($client['user_id'], ['name' => $input['name']]);
            }

            return $this->respondSuccess('Client updated successfully.', $clientModel->find($id));
        } catch (\Throwable $e) {
            return $this->respondError('System Error: ' . $e->getMessage(), [], 500);
        }
    }

    /**
     * Elimina un cliente y su usuario administrador asociado.
     *
     * Solo accesible para `superadmin`. La operación se ejecuta dentro de una
     * transacción: primero elimina el registro de `clients` y luego el de
     * `users` vinculado. Si la transacción falla, ninguno de los dos registros
     * es eliminado. Retorna 404 si el cliente no existe.
     *
     * @param int|null $id ID del cliente a eliminar.
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function delete($id = null)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'superadmin') {
            return $this->respondUnauthorized('Access denied. SuperAdmin privileges required.');
        }

        $clientModel = new ClientModel();
        $client = $clientModel->find($id);

        if (!$client) {
            return $this->respondError('Client not found.', [], 404);
        }

        $db = Database::connect();
        $db->transStart();

        $clientModel->delete($id);
        
        $userModel = new UserModel();
        $userModel->delete($client['user_id']);

        $db->transComplete();

        if ($db->transStatus() === false) {
            return $this->respondError('Database transaction failed.');
        }

        return $this->respondSuccess('Client deleted successfully.');
    }
}
