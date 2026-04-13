<?php
$mysqli = new mysqli("localhost", "root", "", "deliveryci");

if ($mysqli->connect_errno) {
    echo "Failed to connect to MySQL: " . $mysqli->connect_error;
    exit();
}

echo "Updating orders.status ENUM...\n";
$alterQuery = "ALTER TABLE orders MODIFY COLUMN status ENUM('pendiente', 'publicado', 'tomado', 'arribado', 'en_camino', 'entregado', 'rechazado', 'cancelado') NOT NULL DEFAULT 'pendiente'";

if ($mysqli->query($alterQuery)) {
    echo "Successfully updated orders.status ENUM to include 'arribado'.\n";
} else {
    echo "Error updating ENUM: " . $mysqli->error . "\n";
}

// Clean up any empty status trips (though user said they deleted #23/24)
// If any exist, set them to something valid or just a safe state
$mysqli->query("UPDATE orders SET status = 'cancelado' WHERE status = '' OR status IS NULL");

$mysqli->close();
echo "Patch complete.\n";
