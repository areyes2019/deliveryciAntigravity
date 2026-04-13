<?php
$mysqli = new mysqli("localhost", "root", "", "deliveryci");

if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: " . $mysqli->connect_error;
    exit();
}

$id = 24;
$result = $mysqli->query("SELECT * FROM orders WHERE id = $id");
$order = $result->fetch_assoc();

if ($order) {
    echo "ORDER #$id\n";
    print_r($order);
    
    echo "\nSTATUS LOGS:\n";
    $logResult = $mysqli->query("SELECT * FROM order_status_log WHERE order_id = $id ORDER BY log_time ASC");
    if ($logResult) {
        while ($log = $logResult->fetch_assoc()) {
            print_r($log);
        }
    } else {
        echo "Error in logs query: " . $mysqli->error . "\n";
    }
} else {
    echo "Order #$id not found.\n";
}

$mysqli->close();
