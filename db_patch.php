<?php
$mysqli = new mysqli("localhost", "root", "", "deliveryci");

if ($mysqli->connect_error) {
  die("Connection failed: " . $mysqli->connect_error);
}

// Check if table exists
$res = $mysqli->query("SHOW TABLES LIKE 'pricing_zones'");
if ($res->num_rows > 0) {
    echo "Table exists\n";
} else {
    // Create it manually
    $sql = "CREATE TABLE `pricing_zones` (
        `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
        `client_id` int(11) unsigned NOT NULL,
        `name` varchar(100) NOT NULL,
        `polygon_coordinates` json NOT NULL,
        `base_price` decimal(10,2) NOT NULL DEFAULT '0.00',
        `created_at` datetime DEFAULT NULL,
        `updated_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        KEY `pricing_zones_client_id_foreign` (`client_id`),
        CONSTRAINT `pricing_zones_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    if ($mysqli->query($sql) === TRUE) {
        echo "Table created successfully\n";
    } else {
        echo "Error creating table: " . $mysqli->error . "\n";
    }
}

// Verify clients columns
$res = $mysqli->query("SHOW COLUMNS FROM clients LIKE 'pricing_mode'");
if ($res->num_rows > 0) {
    echo "pricing_mode exists\n";
} else {
    $mysqli->query("ALTER TABLE clients ADD COLUMN pricing_mode VARCHAR(50) DEFAULT 'distance' AFTER cost_per_trip");
    $mysqli->query("ALTER TABLE clients ADD COLUMN base_fare DECIMAL(10,2) DEFAULT 0.00 AFTER pricing_mode");
    $mysqli->query("ALTER TABLE clients ADD COLUMN price_per_km DECIMAL(10,2) DEFAULT 0.00 AFTER base_fare");
    echo "columns added\n";
}

$mysqli->close();
