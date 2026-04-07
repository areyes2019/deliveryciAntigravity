<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use Config\Database;

class Patch extends Controller
{
    public function index()
    {
        $db = Database::connect();
        
        $log = [];

        // Check clients table
        if ($db->fieldExists('pricing_mode', 'clients')) {
            $log[] = "clients.pricing_mode exists";
        } else {
            $db->query("ALTER TABLE clients ADD COLUMN pricing_mode VARCHAR(50) DEFAULT 'distance' AFTER cost_per_trip");
            $db->query("ALTER TABLE clients ADD COLUMN base_fare DECIMAL(10,2) DEFAULT 0.00 AFTER pricing_mode");
            $db->query("ALTER TABLE clients ADD COLUMN price_per_km DECIMAL(10,2) DEFAULT 0.00 AFTER base_fare");
            $log[] = "clients columns added";
        }

        // Check pricing_zones table
        if ($db->tableExists('pricing_zones')) {
            $log[] = "pricing_zones table exists";
        } else {
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
            $db->query($sql);
            $log[] = "pricing_zones table created";
        }

        // Add dummy migration record to avoid warnings
        // if not exists
        return $this->response->setJSON(['status' => 'ok', 'log' => $log]);
    }
}
