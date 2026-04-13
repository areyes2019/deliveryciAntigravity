<?php
$mysqli = new mysqli("localhost", "root", "", "deliveryci");

if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: " . $mysqli->connect_error;
    exit();
}

echo "ORDERS WITH EMPTY STATUS:\n";
$result = $mysqli->query("SELECT id, status, updated_at FROM orders WHERE status = '' OR status IS NULL");
while ($row = $result->fetch_assoc()) {
    print_r($row);
}

echo "\nLAST 10 LOGS:\n";
$logResult = $mysqli->query("SELECT * FROM order_status_log ORDER BY id DESC LIMIT 10");
while ($log = $logResult->fetch_assoc()) {
    print_r($log);
}

$mysqli->close();
