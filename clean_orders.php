<?php
$db = new mysqli('localhost', 'root', '', 'delivery_app'); // Assuming standard XAMPP setup
if ($db->connect_error) die("Connection failed");

$db->query("UPDATE orders SET status = 'cancelado' WHERE status IN ('publicado', 'tomado', 'en_camino')");
echo "Cleaned " . $db->affected_rows . " ghost orders.\n";
