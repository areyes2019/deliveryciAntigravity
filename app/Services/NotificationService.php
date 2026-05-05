<?php

namespace App\Services;

/**
 * NotificationService
 *
 * Capa de abstracción de canales de notificación.
 * Hoy solo usa SMS. En el futuro puede soportar WhatsApp, Push, Email, etc.
 *
 * Responsabilidades:
 *  - Delegar el envío al canal correcto según $channel
 *  - No romper el flujo del sistema si la notificación falla
 */
class NotificationService
{
    private SmsService $smsService;

    public function __construct()
    {
        $this->smsService = new SmsService();
    }

    /**
     * Envía una notificación al receptor de un viaje.
     *
     * @param  string $phone    Número del receptor
     * @param  string $message  Texto del mensaje
     * @param  string $channel  Canal: 'sms' | 'whatsapp' | 'push' (futuro)
     * @return bool   true si se envió correctamente
     */
    public function sendNotification(
        string $phone,
        string $message,
        string $channel = 'sms'
    ): bool {
        switch ($channel) {
            case 'sms':
                $result = $this->smsService->send($phone, $message);
                return $result['success'];

            // case 'whatsapp':
            //     return $this->whatsappService->send($phone, $message);

            default:
                log_message('warning', "[NotificationService] Canal '{$channel}' no soportado.");
                return false;
        }
    }
}
