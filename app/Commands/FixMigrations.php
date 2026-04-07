<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class FixMigrations extends BaseCommand
{
    protected $group       = 'custom';
    protected $name        = 'fix:migrations';
    protected $description = 'Creates zone_pricing_matrix table and registers legacy migrations.';

    public function run(array $params)
    {
        $db = \Config\Database::connect();

        // 1. Create the table directly
        CLI::write('Creating zone_pricing_matrix table...', 'yellow');
        $db->query("CREATE TABLE IF NOT EXISTS `zone_pricing_matrix` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_id` INT(11) UNSIGNED NOT NULL,
            `origin_zone_id` INT(11) UNSIGNED NOT NULL,
            `destination_zone_id` INT(11) UNSIGNED NOT NULL,
            `price` DECIMAL(10,2) NULL DEFAULT NULL,
            `created_at` DATETIME NULL,
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `zone_pair` (`client_id`, `origin_zone_id`, `destination_zone_id`),
            CONSTRAINT `fk_zm_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_zm_origin` FOREIGN KEY (`origin_zone_id`) REFERENCES `pricing_zones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT `fk_zm_dest` FOREIGN KEY (`destination_zone_id`) REFERENCES `pricing_zones` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        CLI::write('✅ Table ready.', 'green');

        // 2. Register all pending migrations with correct class names
        $migrations = [
            '20240404000010' => 'App\Database\Migrations\RenameCostColumn',
            '20260406000001' => 'App\Database\Migrations\AddPricingConfigToClients',
            '20260406000002' => 'App\Database\Migrations\CreatePricingZones',
            '20260407000001' => 'App\Database\Migrations\CreateZoneMatrix',
        ];

        foreach ($migrations as $version => $class) {
            $existing = $db->query("SELECT id FROM migrations WHERE version = ? LIMIT 1", [$version])->getRowArray();
            if ($existing) {
                $db->query("UPDATE migrations SET class = ?, batch = 3 WHERE version = ?", [$class, $version]);
                CLI::write("🔄 Updated: $version → $class", 'cyan');
            } else {
                $db->query(
                    "INSERT INTO migrations (version, class, `group`, namespace, time, batch) VALUES (?, ?, 'default', 'App', ?, 3)",
                    [$version, $class, time()]
                );
                CLI::write("✅ Inserted: $version → $class", 'green');
            }
        }

        CLI::write("\n✅ All done! Run `php spark migrate:status` to verify.", 'green');
    }
}
