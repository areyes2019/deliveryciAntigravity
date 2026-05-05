<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/plain; charset=utf-8');

echo "=== PASO 1: PHP funciona ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Directorio actual: " . __DIR__ . "\n\n";

// Paso 2: vendor/autoload.php
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
echo "=== PASO 2: Vendor ===\n";
echo "Buscando: " . $autoload . "\n";
if (!file_exists($autoload)) {
    echo "ERROR: vendor/autoload.php NO EXISTE\n";
    echo "Solución: correr 'composer install' en el servidor\n";
    exit;
}
echo "OK: vendor existe\n\n";
require_once $autoload;

// Paso 3: .env
$envPath = dirname(__DIR__) . '/.env';
echo "=== PASO 3: .env ===\n";
echo "Buscando: " . $envPath . "\n";
if (!file_exists($envPath)) {
    echo "ERROR: .env NO EXISTE en el servidor remoto\n";
    exit;
}
echo "OK: .env existe\n\n";

// Leer credenciales
$env = [];
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}

$sid    = $env['TWILIO_SID']           ?? '';
$token  = $env['TWILIO_TOKEN']         ?? '';
$msgSid = $env['TWILIO_MESSAGING_SID'] ?? '';

echo "=== PASO 4: Credenciales Twilio ===\n";
echo "TWILIO_SID:           " . ($sid   ? substr($sid,0,8).'...' : 'VACIO ❌') . "\n";
echo "TWILIO_TOKEN:         " . ($token ? substr($token,0,6).'...' : 'VACIO ❌') . "\n";
echo "TWILIO_MESSAGING_SID: " . ($msgSid ?: 'VACIO ❌') . "\n\n";

// Paso 5: Twilio SDK
echo "=== PASO 5: Twilio SDK ===\n";
if (!class_exists('\Twilio\Rest\Client')) {
    echo "ERROR: SDK de Twilio no encontrado\n";
    exit;
}
echo "OK: SDK disponible\n\n";

// Paso 6: Envío si se solicita
if (isset($_GET['send'])) {
    echo "=== PASO 6: Envío SMS ===\n";
    if (!$sid || !$token || !$msgSid) {
        echo "ERROR: Faltan credenciales\n";
        exit;
    }
    $to = preg_replace('/\D/', '', $_GET['to'] ?? '4612901439');
    $formatted = strlen($to) === 10 ? '+52'.$to : (strlen($to) === 12 && substr($to,0,2)==='52' ? '+'.$to : null);
    if (!$formatted) { echo "ERROR: Número inválido\n"; exit; }
    echo "Enviando a: $formatted\n";
    try {
        $client = new \Twilio\Rest\Client($sid, $token);
        $msg = $client->messages->create($formatted, [
            'messagingServiceSid' => $msgSid,
            'body' => 'Prueba SelloProunto desde servidor remoto'
        ]);
        echo "EXITO: SID=" . $msg->sid . " Status=" . $msg->status . "\n";
    } catch (\Throwable $e) {
        echo "FALLO: " . $e->getMessage() . "\n";
    }
}
