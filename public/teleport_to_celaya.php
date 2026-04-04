<?php
require_once __DIR__ . '/../app/Config/Paths.php';
require_once __DIR__ . '/../system/bootstrap.php';

$db = \Config\Database::connect();

echo "🛰️ Iniciando teletransportación a Celaya...\n";

// 1. Corregir a Ricardo Mata (ID: 1)
$db->table('drivers')->where('id', 1)->update([
    'current_lat' => 20.5235,
    'current_lng' => -100.8157,
    'status' => 'disponible'
]);
echo "✅ Ricardo Mata (ID: 1) movido al Centro de Celaya.\n";

// 2. Corregir Pedido #1 (o el primero que encuentre de Paolo)
$user = $db->table('users')->where('email', 'paolo@gmail.com')->get()->getRow();
$client = $db->table('clients')->where('admin_user_id', $user->id)->get()->getRow();

if ($client) {
    $db->table('orders')->where('client_id', $client->id)->update([
        'pickup_lat' => 20.5215,
        'pickup_lng' => -100.8130,
        'status' => 'publicado'
    ]);
    echo "✅ Pedidos de Paolo (Client ID: {$client->id}) movidos a Celaya.\n";
}

echo "🚀 Proceso completado. Refresca tu panel en Brave.";
?>
