<?php
require_once __DIR__ . '/../app/Config/Paths.php';
require_once __DIR__ . '/../system/bootstrap.php';

$db = \Config\Database::connect();

// 1. Encontrar al usuario abran@gmail.com
$user = $db->table('users')->where('email', 'abran@gmail.com')->get()->getRow();

if (!$user) {
    die("Usuario abran@gmail.com no encontrado.");
}

// 2. Determinar su client_id
$client = $db->table('clients')->where('admin_user_id', $user->id)->get()->getRow();

if (!$client) {
    die("No hay un cliente vinculado a este usuario.");
}

$clientId = $client->id;

// 3. Contar Conductores
$drivers = $db->table('drivers')->where('client_id', $clientId)->countAllResults();
$orders = $db->table('orders')->where('client_id', $clientId)->where('status', 'publicado')->countAllResults();

echo "CLIENT_ID: $clientId\n";
echo "DRIVERS_COUNT: $drivers\n";
echo "ORDERS_COUNT: $orders\n";

// Listar posiciones para debug
$driverList = $db->table('drivers')->where('client_id', $clientId)->get()->getResult();
foreach($driverList as $d) {
    echo "DRIVER #{$d->id}: {$d->name} at ({$d->current_lat}, {$d->current_lng})\n";
}
?>
