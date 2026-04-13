<?php
// Mock the JWT payload for a driver (user_id 3, which maps to driver_id 1 based on my previous check)
// Let's verify driver_id 1 's user_id.

$mysqli = new mysqli("localhost", "root", "", "deliveryci");
$res = $mysqli->query("SELECT user_id FROM drivers WHERE id = 1");
$driver = $res->fetch_assoc();
$userId = $driver['user_id'];
echo "Driver 1 has User ID: $userId\n";

// Now look for any active trips for this driver
$res = $mysqli->query("SELECT id, status FROM orders WHERE driver_id = 1 AND status IN ('tomado', 'arribado', 'en_camino')");
if ($row = $res->fetch_assoc()) {
    echo "Found active trip in DB: ID " . $row['id'] . " Status: [" . $row['status'] . "]\n";
} else {
    echo "No active trips found for Driver 1 in DB.\n";
}

$mysqli->close();
