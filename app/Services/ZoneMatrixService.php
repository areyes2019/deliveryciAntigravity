<?php

namespace App\Services;

use App\Models\PricingZoneModel;
use App\Models\ZoneMatrixModel;

/**
 * ZoneMatrixService
 *
 * Manages automatic generation and maintenance of the
 * zone-to-zone pricing matrix for each client_admin.
 *
 * Rules:
 *  - Symmetric: price(A→B) == price(B→A)
 *  - Default price = max(origin.base_price, destination.base_price)
 *  - Manual overrides (price IS NOT NULL) are preserved on rebuild
 */
class ZoneMatrixService
{
    private PricingZoneModel $zoneModel;
    private ZoneMatrixModel  $matrixModel;

    public function __construct()
    {
        $this->zoneModel   = new PricingZoneModel();
        $this->matrixModel = new ZoneMatrixModel();
    }

    /**
     * Rebuild the full NxN matrix for a client.
     * - Inserts missing entries with the auto price rule.
     * - Leaves existing rows untouched (preserves overrides).
     * - Deletes orphaned rows where a zone no longer exists.
     */
    public function rebuildMatrix(int $clientId): void
    {
        $zones = $this->zoneModel->where('client_id', $clientId)->findAll();

        if (count($zones) < 1) {
            // No zones left → clean up the entire matrix for this client
            $this->matrixModel->where('client_id', $clientId)->delete();
            return;
        }

        // Build the set of valid zone IDs for orphan cleanup
        $validZoneIds = array_column($zones, 'id');

        // Remove orphaned rows where either zone was deleted
        $this->matrixModel
            ->where('client_id', $clientId)
            ->whereNotIn('origin_zone_id', $validZoneIds)
            ->delete();

        $this->matrixModel
            ->where('client_id', $clientId)
            ->whereNotIn('destination_zone_id', $validZoneIds)
            ->delete();

        $db = \Config\Database::connect();

        // Generate all NxN combinations (including same-zone A→A)
        foreach ($zones as $origin) {
            foreach ($zones as $destination) {

                // Default price rule: max of both zone base prices (symmetric)
                $autoPrice = max(
                    (float)$origin['base_price'],
                    (float)$destination['base_price']
                );

                // INSERT IGNORE preserves any existing manual override
                $db->query(
                    "INSERT IGNORE INTO zone_pricing_matrix
                     (client_id, origin_zone_id, destination_zone_id, price, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())",
                    [
                        $clientId,
                        $origin['id'],
                        $destination['id'],
                        $autoPrice
                    ]
                );
            }
        }
    }

    /**
     * Resolve the price for a specific origin → destination pair.
     * Returns the price (float) or null if no entry exists.
     */
    public function resolvePrice(int $clientId, int $originZoneId, int $destZoneId): ?float
    {
        $entry = $this->matrixModel->lookupEntry($clientId, $originZoneId, $destZoneId);

        if (!$entry) {
            return null;
        }

        // If manual override is set, use it; otherwise use stored auto price
        return $entry['price'] !== null ? (float)$entry['price'] : null;
    }
}
