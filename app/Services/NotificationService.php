<?php

namespace App\Services;

use App\Models\ClientModel;

/**
 * NotificationService
 *
 * Capa de abstracción de canales de notificación.
 * Hoy solo usa SMS. En el futuro puede soportar WhatsApp, Push, Email, etc.
 *
 * Responsabilidades:
 *  - Verificar que la empresa tiene SMS habilitado antes de enviar
 *  - Delegar el envío al canal correcto según $channel
 *  - No romper el flujo del sistema si la notificación falla
 */
class NotificationService
{
    private SmsService  $smsService;
    private ClientModel $clientModel;

    public function __construct()
    {
        $this->smsService  = new SmsService();
        $this->clientModel = new ClientModel();
    }

    /**
     * Envía una notificación al receptor de un viaje.
     *
     * @param  int    $clientId  ID de la empresa (para verificar sms_enabled)
     * @param  string $phone     Número del receptor
     * @param  string $message   Texto del mensaje
     * @param  string $channel   Canal: 'sms' | 'whatsapp' | 'push' (futuro)
     * @return bool   true si se envió correctamente
     */
    public function sendNotification(
        int    $clientId,
        string $phone,
        string $message,
        string $channel = 'sms'
    ): bool {
        $client = $this->clientModel->find($clientId);

        if (!$client) {
            log_message('warning', "[NotificationService] Cliente {$clientId} no encontrado. Notificación cancelada.");
            return false;
        }

        if (empty($client['sms_enabled'])) {
            log_message('warning', "[NotificationService] SMS desactivado para cliente {$clientId}. Notificación no enviada.");
            return false;
        }

        switch ($channel) {
            case 'sms':
                $result = $this->smsService->send($phone, $message);
                return $result['success'];

            // Placeholder para canales futuros
            // case 'whatsapp':
            //     return $this->whatsappService->send($phone, $message);

            default:
                log_message('warning', "[NotificationService] Canal '{$channel}' no soportado.");
                return false;
        }
    }
}
