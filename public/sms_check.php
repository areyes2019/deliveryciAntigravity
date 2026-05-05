<?php
// Diagnóstico SMS directo — TEMPORAL, eliminar después de verificar
header('Content-Type: application/json; charset=utf-8');

// Cargar autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Leer .env manualmente
$envPath = dirname(__DIR__) . '/.env';
$env = [];
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}

$sid    = $env['TWILIO_SID']           ?? '';
$token  = $env['TWILIO_TOKEN']         ?? '';
$msgSid = $env['TWILIO_MESSAGING_SID'] ?? '';

$result = [
    'env_file_found' => file_exists($envPath),
    'TWILIO_SID'     => $sid   ? substr($sid, 0, 8) . '...' : 'VACIO',
    'TWILIO_TOKEN'   => $token ? substr($token, 0, 6) . '...' : 'VACIO',
    'TWILIO_MSG_SID' => $msgSid ?: 'VACIO',
    'php_version'    => PHP_VERSION,
    'twilio_sdk'     => class_exists('\Twilio\Rest\Client') ? 'OK' : 'NO ENCONTRADO',
];

// Test de envío si se pasa ?send=1
if (isset($_GET['send']) && $_GET['send'] === '1' && $sid && $token && $msgSid) {
    $to = $_GET['to'] ?? '4612901439';
    // Formatear número
    $digits = preg_replace('/\D/', '', $to);
    if (strlen($digits) === 10) $formatted = '+52' . $digits;
    elseif (strlen($digits) === 12 && substr($digits, 0, 2) === '52') $formatted = '+' . $digits;
    else $formatted = null;

    if ($formatted) {
        try {
            $client = new \Twilio\Rest\Client($sid, $token);
            $msg = $client->messages->create($formatted, [
                'messagingServiceSid' => $msgSid,
                'body' => 'Test SelloProunto desde servidor remoto',
            ]);
            $result['sms_enviado'] = ['success' => true, 'sid' => $msg->sid, 'status' => $msg->status, 'to' => $formatted];
        } catch (\Throwable $e) {
            $result['sms_enviado'] = ['success' => false, 'error' => $e->getMessage()];
        }
    } else {
        $result['sms_enviado'] = ['success' => false, 'error' => 'Numero invalido: ' . $to];
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
