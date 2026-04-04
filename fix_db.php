<?php

// Raw MySQL Connection (Standard XAMPP)
$host = "localhost";
$user = "root";
$pass = "";
$dbName = "deliveryci";

$conn = new mysqli($host, $user, $pass, $dbName);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error . "\n");
}

echo "Conectado directamente a MariaDB/MySQL...\n";

// Check column
$sql = "SHOW COLUMNS FROM clients LIKE 'cost_per_km'";
$res = $conn->query($sql);

if ($res->num_rows > 0) {
    echo "Renombrando columna cost_per_km a cost_per_trip...\n";
    $sql_rename = "ALTER TABLE clients CHANGE cost_per_km cost_per_trip DECIMAL(10,2) DEFAULT 0.00";
    if ($conn->query($sql_rename) === TRUE) {
        echo "Exito: Columna renombrada.\n";
    } else {
        echo "Error al renombrar: " . $conn->error . "\n";
    }
} else {
    echo "La columna cost_per_km no se encuentra (quizás ya fue renombrada).\n";
}

$conn->close();
echo "Conexión cerrada.\n";
