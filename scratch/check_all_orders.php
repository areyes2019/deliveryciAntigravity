<?php
$mysqli = new mysqli("localhost", "root", "", "deliveryci");
$res = $mysqli->query("SELECT id, driver_id, status FROM orders WHERE status IN ('tomado', 'arribado', 'en_camino', 'publicado')");
echo "ALL RELEVANT ORDERS:\n";
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
$mysqli->close();
