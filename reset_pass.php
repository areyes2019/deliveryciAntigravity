<?php
$mysqli = new mysqli("localhost", "root", "", "deliveryci");
if ($mysqli->connect_error) { die("Connection failed"); }

$hash = password_hash('12345678', PASSWORD_DEFAULT);
$sql = "UPDATE users SET password = '$hash' WHERE email = 'admin@delivery.com'";
$mysqli->query($sql);
echo "Password reset to verified hash for 12345678!";
$mysqli->close();
