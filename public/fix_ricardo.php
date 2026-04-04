<?php
require_once __DIR__ . '/../app/Config/Paths.php';
require_once __DIR__ . '/../system/bootstrap.php';

$db = \Config\Database::connect();

// 1. Verificar a Ricardo Mata (ID: 1)
$d = $db->table('drivers')->where('id', 1)->get()->getRow();

if (!$d) {
    echo "NO_DRIVER_1\n";
    die();
}

echo "DRIVER_ID: 1\n";
echo "NAME: {$d->name}\n";
echo "LAT: {$d->current_lat}\n";
echo "LNG: {$d->current_lng}\n";

// 2. Si las coordenadas son 0 o NULL, ponerlo en Celaya Centro
if (empty($d->current_lat) || $d->current_lat == 0) {
    echo "FIXING_COORDS_TO_CELAYA\n";
    $db->table('drivers')->where('id', 1)->update([
        'current_lat' => 20.5235,
        'current_lng' => -100.8157
    ]);
}
?>
