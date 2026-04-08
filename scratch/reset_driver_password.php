<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'deliveryci';

$mysqli = new mysqli($host, $user, $pass, $db);

if ($mysqli->connect_error) {
    die('Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error);
}

$id = 3; // The ID found in the previous step
$newPassword = '12345678';
$hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

$stmt = $mysqli->prepare("UPDATE users SET password = ? WHERE id = ?");
$stmt->bind_param("si", $hashedPassword, $id);

if ($stmt->execute()) {
    echo "Password updated successfully for user ID $id.\n";
} else {
    echo "Error updating password: " . $stmt->error . "\n";
}

$stmt->close();
$mysqli->close();
