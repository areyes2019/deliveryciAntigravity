<?php
require_once __DIR__ . '/../app/Config/Paths.php';
require_once __DIR__ . '/../system/bootstrap.php';

$db = \Config\Database::connect();

// Coordenadas base de Celaya: 20.5222, -100.8122
$base_lat = 20.5222;
$base_lng = -100.8122;

$drivers = $db->table('drivers')->get()->getResult();

echo "Actualizando " . count($drivers) . " conductores...\n";

foreach ($drivers as $index => $driver) {
    // Añadir un pequeño offset aleatorio para que no estén todos en el mismo punto
    $lat_offset = (mt_rand(-10, 10) / 1000);
    $lng_offset = (mt_rand(-10, 10) / 1000);
    
    $db->table('drivers')
       ->where('id', $driver->id)
       ->update([
           'current_lat' => $base_lat + $lat_offset,
           'current_lng' => $base_lng + $lng_offset
       ]);
       
    echo "Conductor {$driver->id} ubicado en: " . ($base_lat + $lat_offset) . ", " . ($base_lng + $lng_offset) . "\n";
}

echo "¡Listo! Conductores posicionados en Celaya.";
?>
