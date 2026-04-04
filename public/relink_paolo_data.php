<?php
require_once __DIR__ . '/../app/Config/Paths.php';
require_once __DIR__ . '/../system/bootstrap.php';

$db = \Config\Database::connect();

// 1. Encontrar al usuario paolo@gmail.com
$user = $db->table('users')->where('email', 'paolo@gmail.com')->get()->getRow();

if (!$user) {
    die("Usuario paolo@gmail.com no encontrado.");
}

// 2. Determinar su client_id
$client = $db->table('clients')->where('admin_user_id', $user->id)->get()->getRow();

if (!$client) {
    die("No hay un cliente vinculado a Paolo.");
}

$clientId = $client->id;
echo "CLIENT_ID: $clientId\n";

// 3. Crear 3 Conductores en Celaya para Paolo
$db->table('drivers')->where('client_id', $clientId)->delete();
$drivers = [
    ['name' => 'Ricardo Mata (ID: 1)', 'lat' => 20.5235, 'lng' => -100.8157],
    ['name' => 'Driver Celaya Norte', 'lat' => 20.5400, 'lng' => -100.8100],
    ['name' => 'Driver Celaya Sur', 'lat' => 20.5100, 'lng' => -100.8200]
];

foreach ($drivers as $d) {
    $db->table('drivers')->insert([
        'client_id' => $clientId,
        'name' => $d['name'],
        'vehicle_details' => 'Moto Paolo Demo',
        'current_lat' => $d['lat'],
        'current_lng' => $d['lng'],
        'status' => 'disponible',
        'is_active' => 1
    ]);
}

// 4. Crear 1 Pedido Activo
$db->table('orders')->where('client_id', $clientId)->where('status', 'publicado')->delete();
$db->table('orders')->insert([
    'client_id' => $clientId,
    'pickup_address' => 'Centro de Celaya',
    'pickup_lat' => 20.5215,
    'pickup_lng' => -100.8130,
    'drop_address' => 'Zona Industrial',
    'drop_lat' => 20.5500,
    'drop_lng' => -100.7800,
    'status' => 'publicado',
    'credits_cost' => 1,
    'created_at' => date('Y-m-d H:i:s')
]);

echo "¡Éxito! Paolo ahora tiene 3 conductores y 1 pedido. Refresca el panel.";
?>
