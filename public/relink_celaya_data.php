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
// Si es un admin_cliente, el client_id suele estar directamente en la tabla users o via admin_user_id en clients
$client = $db->table('clients')->where('admin_user_id', $user->id)->get()->getRow();

if (!$client) {
    echo "No hay un cliente vinculado a este usuario. Creando uno para la demo...\n";
    $db->table('clients')->insert([
        'business_name' => 'Abran Delivery Co.',
        'admin_user_id' => $user->id,
        'credits_balance' => 100,
        'status' => 'activo'
    ]);
    $clientId = $db->insertID();
} else {
    $clientId = $client->id;
}

echo "Viculando datos al Client ID: $clientId\n";

// 3. Limpiar y Crear 3 Conductores en Celaya
$db->table('drivers')->where('client_id', $clientId)->delete();
$drivers = [
    ['name' => 'Driver Celaya Centro', 'lat' => 20.5235, 'lng' => -100.8157],
    ['name' => 'Driver Celaya Norte', 'lat' => 20.5400, 'lng' => -100.8100],
    ['name' => 'Driver Celaya Sur', 'lat' => 20.5100, 'lng' => -100.8200]
];

foreach ($drivers as $d) {
    $db->table('drivers')->insert([
        'client_id' => $clientId,
        'name' => $d['name'],
        'vehicle_details' => 'Moto Demo',
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

echo "¡Éxito! 3 Conductores y 1 Pedido vinculados a abran@gmail.com. Refresca el panel.";
?>
