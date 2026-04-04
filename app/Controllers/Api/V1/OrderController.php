<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Services\OrderService;
use App\Models\ClientModel;
use App\Traits\ApiResponseTrait;

class OrderController extends BaseController
{
    use ApiResponseTrait;

    protected OrderService $orderService;

    public function __construct()
    {
        $this->orderService = new OrderService();
    }

    public function create()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized('Only clients can create orders.');
        }

        $rules = [
            'pickup_lat'     => 'required|decimal',
            'pickup_lng'     => 'required|decimal',
            'pickup_address' => 'required',
            'drop_lat'       => 'required|decimal',
            'drop_lng'       => 'required|decimal',
            'drop_address'   => 'required',
            'payment_type'   => 'required|in_list[prepaid,cash_on_delivery,cash_full]'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        if (!$client) {
            return $this->respondError('Client profile not found for the user', [], 404);
        }

        $data = $this->request->getPost();
        if (empty($data)) {
             $data = $this->request->getJSON(true);
        }

        $result = $this->orderService->createOrder($client['id'], $data);

        if ($result['status']) {
            return $this->respondSuccess($result['message'], $result['data'], 201);
        }

        return $this->respondError($result['message']);
    }
}
