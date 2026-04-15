<?php

namespace App\Services;

use Twilio\Rest\Client;

/**
 * SmsService
 *
 * Wrapper sobre el SDK de Twilio.
 * Responsabilidades:
 *  - Formatear el número a E.164 (+52XXXXXXXXXX)
 *  - Enviar el SMS
 *  - Loggear éxito y errores
 *  - Nunca lanzar excepciones al caller (errores quedan en el log)
 */
class SmsService
{
    private string $sid;
    private string $token;
    private string $from;

    public function __construct()
    {
        $this->sid   = (string) env('TWILIO_SID',   '');
        $this->token = (string) env('TWILIO_TOKEN', '');
        $this->from  = (string) env('TWILIO_FROM',  '');
    }

    /**
     * Envía un SMS.
     *
     * @param  string $to      Número destino (10 dígitos, o ya en formato E.164)
     * @param  string $message Texto del mensaje
     * @return array  { success: bool, sid?: string, error?: string }
     */
    public function send(string $to, string $message): array
    {
        if (!$this->isConfigured()) {
            log_message('warning', '[SmsService] Credenciales Twilio no configuradas. SMS no enviado.');
            return ['success' => false, 'error' => 'SMS service not configured.'];
        }

        $formatted = $this->formatPhone($to);

        if ($formatted === null) {
            log_message('warning', "[SmsService] Número inválido descartado: {$to}");
            return ['success' => false, 'error' => "Invalid phone number: {$to}"];
        }

        try {
            $client = new Client($this->sid, $this->token);
            $msg    = $client->messages->create($formatted, [
                'from' => $this->from,
                'body' => $message,
            ]);

            log_message('info', "[SmsService] SMS enviado a {$formatted}. Twilio SID: {$msg->sid}");
            return ['success' => true, 'sid' => $msg->sid];

        } catch (\Throwable $e) {
            log_message('error', "[SmsService] Error al enviar SMS a {$formatted}: {$e->getMessage()}");
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function isConfigured(): bool
    {
        return $this->sid !== '' && $this->token !== '' && $this->from !== '';
    }

    /**
     * Convierte el número a formato E.164 para México (+52XXXXXXXXXX).
     *
     * Casos soportados:
     *   "5512345678"   → "+5512345678"   (ya incluye lada 55, 10 dígitos)
     *   "5512345678"   → "+525512345678" NO — 10 dígitos sin prefijo de país
     *   "5212345678XX" → "+5212345678XX" (ya viene con prefijo 52)
     *
     * Regla sencilla:
     *   - 10 dígitos  → +52 + número
     *   - 12 dígitos comenzando con 52 → + + número
     *   - Cualquier otro caso → null (inválido)
     */
    private function formatPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 10) {
            return '+52' . $digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return '+' . $digits;
        }

        return null;
    }
}
