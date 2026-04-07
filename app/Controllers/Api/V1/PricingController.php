<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\PricingZoneModel;
use App\Services\PricingService;
use App\Traits\ApiResponseTrait;

class PricingController extends BaseController
{
    use ApiResponseTrait;

    public function updateConfig()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        if (!$client) {
            return $this->respondError('Client not found');
        }

        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        $allowedFields = ['pricing_mode', 'base_fare', 'price_per_km', 'cost_per_trip'];
        $updateData = [];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        if (!empty($updateData)) {
            $clientModel->update($client['id'], $updateData);
        }

        return $this->respondSuccess('Pricing configuration updated successfully');
    }

    public function getZones()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        if (!$client) {
            return $this->respondError('Client not found');
        }

        $zoneModel = new PricingZoneModel();
        $zones = $zoneModel->where('client_id', $client['id'])->findAll();

        return $this->respondSuccess('Zones retrieved', $zones);
    }

    public function createZone()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        $rules = [
            'name' => 'required',
            'polygon_coordinates' => 'required',
            'base_price' => 'required|decimal'
        ];

        if (!$this->validate($rules)) {
            return $this->respondError('Validation failed', $this->validator->getErrors());
        }

        $zoneModel = new PricingZoneModel();
        
        $insertData = [
            'client_id' => $client['id'],
            'name' => $data['name'],
            'polygon_coordinates' => is_array($data['polygon_coordinates']) ? json_encode($data['polygon_coordinates']) : $data['polygon_coordinates'],
            'base_price' => $data['base_price']
        ];

        $zoneModel->insert($insertData);

        return $this->respondSuccess('Zone created successfully');
    }

    public function deleteZone($id)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        $zoneModel = new PricingZoneModel();
        $zone = $zoneModel->find($id);

        if (!$zone || $zone['client_id'] != $client['id']) {
            return $this->respondError('Zone not found or unauthorized');
        }

        $zoneModel->delete($id);

        return $this->respondSuccess('Zone deleted');
    }

    public function calculatePreview()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $clientModel = new ClientModel();
        $client = $clientModel->where('user_id', $userData['id'])->first();

        $data = $this->request->getJSON(true) ?? $this->request->getPost();
        
        if (!isset($data['pickup_lat']) || !isset($data['pickup_lng']) || !isset($data['drop_lat']) || !isset($data['drop_lng'])) {
             return $this->respondError('Missing coordinates');
        }

        // Mock distance service for preview
        $distanceService = new \App\Services\MockDistanceMatrixService();
        $distanceKm = 0;
        if (isset($data['pickup_address']) && isset($data['drop_address'])) {
            $distanceKm = $distanceService->getDistanceInKm($data['pickup_address'], $data['drop_address']);
        } else {
             // Basic haversine if addresses not provided
             $distanceKm = 5; // fallback
        }

        $pricingService = new PricingService();
        $result = $pricingService->calculatePrice(
            $client['id'], 
            (float)$data['pickup_lat'], 
            (float)$data['pickup_lng'], 
            (float)$data['drop_lat'], 
            (float)$data['drop_lng'],
            $distanceKm
        );

        if (!$result['status']) {
            return $this->respondError($result['message']);
        }

        return $this->respondSuccess('Price calculated', $result);
    }
}
