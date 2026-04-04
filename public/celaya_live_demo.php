<?php
require_once __DIR__ . '/../app/Config/Paths.php';
require_once __DIR__ . '/../system/bootstrap.php';

$db = \Config\Database::connect();

// 1. Obtener el cliente asociado a abran@gmail.com
$user = $db->table('users')->where('email', 'abran@gmail.com')->get()->getRow();
$client = $db->table('clients')->where('admin_user_id', $user->id)->get()->getRow();

if (!$client) {
    die("No se encontró el cliente para abran@gmail.com");
}

echo "Poblando datos para el cliente: " . $client->business_name . " (ID: " . $client->id . ")\n";

// 2. Crear/Actualizar 3 conductores para este cliente
$driversData = [
    ['name' => 'Carlos López', 'vehicle' => 'Moto Italika - Negro'],
    ['name' => 'Roberto Ruiz', 'vehicle' => 'Honda Cargo - Blanco'],
    ['name' => 'Juan Pérez', 'vehicle' => 'Chevrolet Spark - Azul']
];

// Coordenadas en Celaya
$coords = [
    [20.5235, -100.8157], // Centro
    [20.5186, -100.8015], // Cerca de Mercado
    [20.5367, -100.8251]  // Cerca de Parques
];

foreach ($driversData as $i => $data) {
    // Insertar conductor si no existe o actualizar uno viejo
    $db->table('drivers')->insert([
        'client_id' => $client->id,
        'name' => $data['name'],
        'vehicle_details' => $data['vehicle'],
        'current_lat' => $coords[$i][0],
        'current_lng' => $coords[$i][1],
        'status' => 'disponible',
        'is_active' => 1
    ]);
    echo "Conductor {$data['name']} posicionado en Celaya.\n";
}

// 3. Crear 1 pedido activo
$db->table('orders')->insert([
    'client_id' => $client->id,
    'pickup_address' => 'Restaurante El Tapatio, Centro Celaya',
    'pickup_lat' => 20.5222,
    'pickup_lng' => -100.8122,
    'drop_address' => 'Col. Alameda, Calle Veracruz 123',
    'drop_lat' => 20.5312,
    'drop_lng' => -100.8055,
    'status' => 'publicado',
    'credits_cost' => 1,
    'created_at' => date('Y-m-d H:i:s')
]);

echo "¡Mapa de Celaya ACTIVADO con 3 conductores y 1 pedido!";
?>
