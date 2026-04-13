<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\DriverBillingConfigModel;
use App\Traits\ApiResponseTrait;

class DriverBillingConfigController extends BaseController
{
    use ApiResponseTrait;

    private ClientModel $clientModel;
    private DriverBillingConfigModel $configModel;

    public function __construct()
    {
        $this->clientModel = new ClientModel();
        $this->configModel = new DriverBillingConfigModel();
    }

    /**
     * GET /api/v1/driver-billing
     * Devuelve la configuración de facturación de la empresa autenticada.
     */
    public function getConfig()
    {
        $client = $this->resolveClient();
        if ($client === null) {
            return $this->respondUnauthorized();
        }

        $config = $this->configModel->getByClient($client['id']);

        return $this->respondSuccess('Config retrieved.', $config ?? []);
    }

    /**
     * PUT /api/v1/driver-billing
     * Crea o actualiza la configuración de facturación.
     */
    public function saveConfig()
    {
        $client = $this->resolveClient();
        if ($client === null) {
            return $this->respondUnauthorized();
        }

        $data = $this->request->getJSON(true) ?? $this->request->getPost();

        $tipo = trim($data['tipo_esquema'] ?? '');

        if (!in_array($tipo, ['credito', 'porcentaje'], true)) {
            return $this->respondError('El campo tipo_esquema debe ser "credito" o "porcentaje".');
        }

        if ($tipo === 'credito') {
            if (!isset($data['precio_credito']) || !is_numeric($data['precio_credito']) || (float)$data['precio_credito'] <= 0) {
                return $this->respondError('precio_credito es requerido y debe ser mayor a 0.');
            }
            $payload = [
                'tipo_esquema'        => 'credito',
                'precio_credito'      => round((float)$data['precio_credito'], 2),
                'porcentaje_comision' => null,
            ];
        } else {
            if (!isset($data['porcentaje_comision']) || !is_numeric($data['porcentaje_comision'])
                || (float)$data['porcentaje_comision'] <= 0 || (float)$data['porcentaje_comision'] > 100) {
                return $this->respondError('porcentaje_comision es requerido y debe estar entre 0 y 100.');
            }
            $payload = [
                'tipo_esquema'        => 'porcentaje',
                'precio_credito'      => null,
                'porcentaje_comision' => round((float)$data['porcentaje_comision'], 2),
            ];
        }

        $existing = $this->configModel->getByClient($client['id']);

        if ($existing) {
            $this->configModel->update($existing['id'], $payload);
        } else {
            $this->configModel->insert(array_merge(['client_id' => $client['id']], $payload));
        }

        return $this->respondSuccess('Configuración guardada correctamente.');
    }

    // -------------------------------------------------------------------------

    private function resolveClient(): ?array
    {
        $userData = $this->request->jwtPayload ?? null;
        if (!$userData || $userData['role'] !== 'client_admin') {
            return null;
        }

        return $this->clientModel->where('user_id', $userData['id'])->first() ?: null;
    }
}
