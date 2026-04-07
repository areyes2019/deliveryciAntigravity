<?php

$mysqli = new mysqli("localhost", "root", "", "deliveryci");
if ($mysqli->connect_error) { die("Connection failed: " . $mysqli->connect_error); }

$class1 = 'App\Database\Migrations\AddPricingConfigToClients';
$class2 = 'App\Database\Migrations\CreatePricingZones';

$stmt = $mysqli->prepare("SELECT id FROM migrations WHERE class = ?");
$stmt->bind_param("s", $class1);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows == 0) {
    $mysqli->query("INSERT INTO migrations (version, class, `group`, namespace, time, batch) VALUES ('20260406000001', '$class1', 'default', 'App', UNIX_TIMESTAMP(), 2)");
}

$stmt = $mysqli->prepare("SELECT id FROM migrations WHERE class = ?");
$stmt->bind_param("s", $class2);
$stmt->execute();
$res2 = $stmt->get_result();

if ($res2->num_rows == 0) {
    $mysqli->query("INSERT INTO migrations (version, class, `group`, namespace, time, batch) VALUES ('20260406000002', '$class2', 'default', 'App', UNIX_TIMESTAMP(), 2)");
}

echo "done";
$mysqli->close();
