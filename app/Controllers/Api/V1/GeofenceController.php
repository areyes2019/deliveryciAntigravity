<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\GeofenceModel;
use App\Services\OrderService;
use App\Traits\ApiResponseTrait;

class GeofenceController extends BaseController
{
    use ApiResponseTrait;

    /**
     * GET /api/v1/geofences
     * Devuelve la geocerca del cliente (array con 0 o 1 elemento).
     */
    public function index()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $client = (new ClientModel())->where('user_id', $userData['id'])->first();
        if (!$client) {
            return $this->respondError('Perfil de cliente no encontrado.', [], 404);
        }

        $geofences = (new GeofenceModel())->where('client_id', $client['id'])->findAll();
        return $this->respondSuccess('Geofences retrieved', $geofences ?? []);
    }

    /**
     * POST /api/v1/geofences
     * Crea o reemplaza la geocerca del cliente (upsert — solo 1 por cliente).
     * Body: { name?, polygon_coordinates: [{lat, lng}, ...] }
     */
    public function store()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        $polygonCoords = $data['polygon_coordinates'] ?? null;
        if (empty($polygonCoords) || !is_array($polygonCoords) || count($polygonCoords) < 3) {
            return $this->respondError('Se requieren al menos 3 puntos en polygon_coordinates.', [], 422);
        }

        $client = (new ClientModel())->where('user_id', $userData['id'])->first();
        if (!$client) {
            return $this->respondError('Perfil de cliente no encontrado.', [], 404);
        }

        $geofenceModel = new GeofenceModel();
        $existing      = $geofenceModel->where('client_id', $client['id'])->first();

        $payload = [
            'client_id'           => $client['id'],
            'name'                => $data['name'] ?? 'Zona de Operación',
            'polygon_coordinates' => json_encode($polygonCoords),
        ];

        if ($existing) {
            $geofenceModel->update($existing['id'], $payload);
            $saved = $geofenceModel->find($existing['id']);
            return $this->respondSuccess('Geocerca actualizada.', $saved);
        }

        $id    = $geofenceModel->insert($payload);
        $saved = $geofenceModel->find($id);
        return $this->respondSuccess('Geocerca creada.', $saved);
    }

    /**
     * DELETE /api/v1/geofences/{id}
     * Elimina la geocerca del cliente (solo puede eliminar la propia).
     */
    public function destroy(int $id)
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $client = (new ClientModel())->where('user_id', $userData['id'])->first();
        if (!$client) {
            return $this->respondError('Perfil de cliente no encontrado.', [], 404);
        }

        $geofenceModel = new GeofenceModel();
        $geofence      = $geofenceModel->find($id);

        if (!$geofence || $geofence['client_id'] != $client['id']) {
            return $this->respondError('Geocerca no encontrada.', [], 404);
        }

        $geofenceModel->delete($id);
        return $this->respondSuccess('Geocerca eliminada.');
    }

    /**
     * POST /api/v1/validate-geofence
     * Valida que pickup y drop estén dentro del área de operación del cliente.
     * Body: { pickup_lat, pickup_lng, drop_lat, drop_lng }
     */
    public function checkPoints()
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return $this->respondUnauthorized();
        }

        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        $pickupLat = isset($data['pickup_lat']) ? (float)$data['pickup_lat'] : null;
        $pickupLng = isset($data['pickup_lng']) ? (float)$data['pickup_lng'] : null;
        $dropLat   = isset($data['drop_lat'])   ? (float)$data['drop_lat']   : null;
        $dropLng   = isset($data['drop_lng'])   ? (float)$data['drop_lng']   : null;

        if ($pickupLat === null || $pickupLng === null || $dropLat === null || $dropLng === null) {
            return $this->respondError('Se requieren pickup_lat, pickup_lng, drop_lat y drop_lng.', [], 422);
        }

        $client = (new ClientModel())->where('user_id', $userData['id'])->first();
        if (!$client) {
            return $this->respondError('Perfil de cliente no encontrado.', [], 404);
        }

        $result = (new OrderService())->validateGeofencePoints(
            (int)$client['id'],
            $pickupLat, $pickupLng,
            $dropLat,   $dropLng
        );

        if (!$result['status']) {
            return $this->respondError($result['message'], [], 422);
        }

        return $this->respondSuccess('Puntos dentro del área de operación.');
    }
}
