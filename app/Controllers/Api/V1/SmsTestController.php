<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Services\SmsService;
use App\Services\NotificationService;
use App\Models\ClientModel;
use App\Models\OrderModel;
use App\Traits\ApiResponseTrait;

/**
 * Controlador de diagnóstico SMS — TEMPORAL, eliminar en producción
 */
class SmsTestController extends BaseController
{
    use ApiResponseTrait;

    public function diagnose()
    {
        $result = [];

        // 1. Variables de entorno
        $sid    = env('TWILIO_SID', '');
        $token  = env('TWILIO_TOKEN', '');
        $msgSid = env('TWILIO_MESSAGING_SID', '');

        $result['env'] = [
            'TWILIO_SID'           => $sid   ? substr($sid, 0, 8) . '...' : '❌ vacío',
            'TWILIO_TOKEN'         => $token ? substr($token, 0, 6) . '...' : '❌ vacío',
            'TWILIO_MESSAGING_SID' => $msgSid ? $msgSid : '❌ vacío',
            'CI_ENVIRONMENT'       => ENVIRONMENT,
        ];

        // 2. Estado del cliente
        $clientModel = new ClientModel();
        $client      = $clientModel->find(1);
        $result['client_1'] = $client ? [
            'business_name' => $client['business_name'],
            'sms_enabled'   => $client['sms_enabled'],
        ] : '❌ Cliente 1 no encontrado';

        // 3. Última orden con receiver_phone
        $orderModel = new OrderModel();
        $lastOrder  = $orderModel
            ->where('client_id', 1)
            ->where('receiver_phone !=', '')
            ->orderBy('id', 'DESC')
            ->first();

        $result['last_order_with_phone'] = $lastOrder ? [
            'id'             => $lastOrder['id'],
            'receiver_phone' => $lastOrder['receiver_phone'],
            'status'         => $lastOrder['status'],
        ] : '❌ Ninguna orden con receiver_phone';

        // 4. Órdenes publicadas (disponibles para aceptar)
        $published = $orderModel
            ->where('client_id', 1)
            ->where('status', 'publicado')
            ->countAllResults();
        $result['ordenes_publicadas'] = $published;

        // 5. Test directo de Twilio (solo si se pasa ?send=1&to=XXXXXXXXXX)
        $doSend = $this->request->getGet('send');
        $toNum  = $this->request->getGet('to') ?: '4612901439';

        if ($doSend === '1') {
            $sms    = new SmsService();
            $sent   = $sms->send($toNum, 'Diagnóstico SelloProunto: Twilio funciona ✅');
            $result['sms_test'] = $sent;
        } else {
            $result['sms_test'] = 'Agrega ?send=1&to=NUMERO para enviar SMS de prueba';
        }

        return $this->respondSuccess('Diagnóstico SMS', $result);
    }
}
