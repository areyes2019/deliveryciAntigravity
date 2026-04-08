<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'deliveryci';

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

$result = $mysqli->query("SELECT id, name, email, role FROM users WHERE role = 'driver'");

$drivers = [];
while ($row = $result->fetch_assoc()) {
    $drivers[] = $row;
}

header('Content-Type: application/json');
echo json_encode($drivers, JSON_PRETTY_PRINT);
$mysqli->close();
