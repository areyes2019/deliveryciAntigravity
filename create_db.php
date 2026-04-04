<?php
$mysqli = new mysqli("localhost", "root", "");

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$sql = "CREATE DATABASE IF NOT EXISTS deliveryci";
if ($mysqli->query($sql) === TRUE) {
    echo "Database created successfully";
} else {
    echo "Error creating database: " . $mysqli->error;
}
$mysqli->close();
