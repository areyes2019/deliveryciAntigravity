<?php

namespace App\Services;

use App\Models\ClientModel;
use App\Models\PricingZoneModel;
use App\Helpers\GeoHelper;

/**
 * PricingService
 *
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │  ZONE MODE — pricing rules                                          │
 * │                                                                     │
 * │  Each zone carries two prices:                                      │
 * │    base_price      → charged for the ORIGIN zone only              │
 * │    increment_price → added for each SUBSEQUENT zone crossed        │
 * │                                                                     │
 * │  Rule 1 — Short trip  (distance ≤ crossZoneThresholdKm):           │
 * │    price = origin_zone.base_price                                   │
 * │                                                                     │
 * │  Rule 2 — Long trip   (distance >  crossZoneThresholdKm):          │
 * │    price = origin_zone.base_price                                   │
 * │          + SUM( zone.increment_price  for each unique zone         │
 * │                 entered AFTER the origin, in traversal order )     │
 * └─────────────────────────────────────────────────────────────────────┘
 *
 * Performance notes:
 *   - Zone records and decoded polygon arrays are memoised in static
 *     class-level arrays for the lifetime of a single HTTP request.
 *     This eliminates duplicate DB queries and repeated json_decode calls
 *     when calculatePrice() is invoked more than once per request.
 *   - For cross-request caching the zone list can be stored in CI4's
 *     Cache service (e.g. cache()->remember("zones_{$clientId}", 300, …))
 *     since zone geometry changes rarely. The TTL should be invalidated
 *     whenever a zone is created or deleted (call cache()->delete()).
 */
class PricingService
{
    private ClientModel      $clientModel;
    private PricingZoneModel $zoneModel;

    /**
     * Intra-request zone list cache.
     * [ clientId (int) => zone rows (array) ]
     */
    private static array $_zoneCache = [];

    /**
     * Intra-request polygon decode cache.
     * [ zoneId (int) => decoded coordinate array ]
     */
    private static array $_polygonCache = [];

    public function __construct()
    {
        $this->clientModel = new ClientModel();
        $this->zoneModel   = new PricingZoneModel();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Public API
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Calculate the price for an order.
     *
     * @param int        $clientId
     * @param float      $originLat
     * @param float      $originLng
     * @param float      $destLat
     * @param float      $destLng
     * @param float      $distanceKm   Total trip distance in kilometres
     * @param array|null $polyline     Route polyline: [['lat'=>float,'lng'=>float], …]
     *                                 Pass null when unavailable; a two-point fallback
     *                                 (origin → destination) will be used instead.
     * @return array  { status: bool, price?: float, breakdown?: array, message?: string }
     */
    public function calculatePrice(
        int    $clientId,
        float  $originLat,
        float  $originLng,
        float  $destLat,
        float  $destLng,
        float  $distanceKm,
        ?array $polyline = null
    ): array {
        $client = $this->clientModel->find($clientId);

        if (!$client) {
            return ['status' => false, 'message' => 'Client not found'];
        }

        $pricingMode = $client['pricing_mode'] ?? 'distance';

        // ── DISTANCE MODE ────────────────────────────────────────────────────
        if ($pricingMode === 'distance') {
            return $this->calcDistancePrice($client, $distanceKm);
        }

        // ── ZONE MODE ────────────────────────────────────────────────────────
        if ($pricingMode === 'zone') {
            return $this->calcZonePrice(
                $clientId, $originLat, $originLng,
                $destLat, $destLng, $distanceKm, $polyline
            );
        }

        return ['status' => false, 'message' => 'Invalid pricing mode'];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Distance mode
    // ══════════════════════════════════════════════════════════════════════

    private function calcDistancePrice(array $client, float $distanceKm): array
    {
        $baseFare      = (float)($client['base_fare']       ?? 0);
        $pricePerKm    = (float)($client['price_per_km']    ?? $client['cost_per_trip'] ?? 0);
        $minDistanceKm = (float)($client['min_distance_km'] ?? 0);

        $billableKm = max(0.0, $distanceKm - $minDistanceKm);
        $total      = $baseFare + ($billableKm * $pricePerKm);

        return [
            'status' => true,
            'price'  => (int) ceil($total),
            'breakdown' => [
                'mode'            => 'distance',
                'base_fare'       => $baseFare,
                'min_distance_km' => $minDistanceKm,
                'distance_km'     => $distanceKm,
                'billable_km'     => round($billableKm, 3),
                'price_per_km'    => $pricePerKm,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Zone mode — main entry
    // ══════════════════════════════════════════════════════════════════════

    private function calcZonePrice(
        int    $clientId,
        float  $originLat,
        float  $originLng,
        float  $destLat,
        float  $destLng,
        float  $distanceKm,
        ?array $polyline
    ): array {
        /** @var \Config\Pricing $cfg */
        $cfg   = config('Pricing');
        $zones = $this->getZones($clientId);

        // No zones configured → fall back to distance pricing (no geographic restriction)
        if (empty($zones)) {
            $clientModel = new \App\Models\ClientModel();
            $client      = $clientModel->find($clientId);
            return $this->calcDistancePrice($client, $distanceKm);
        }

        $originPoint = ['lat' => $originLat, 'lng' => $originLng];

        // Locate the origin zone (required for both rules)
        $originZone = $this->findZoneForPoint($originLat, $originLng, $zones);

        if ($originZone === null) {
            // Boundary tolerance fallback: pick the closest zone within 100 m
            $result = $this->findBestZoneForPoint($originPoint, $zones, $cfg->boundaryToleranceMeters);
            if ($result === null) {
                return [
                    'status'  => false,
                    'message' => 'Fuera de cobertura: el punto de recogida está fuera de las zonas definidas.',
                ];
            }
            $originZone = $result['zone'];
        }

        // ── RULE 1: short trip ───────────────────────────────────────────────
        if ($distanceKm <= $cfg->crossZoneThresholdKm) {
            // Short trips always charge the highest base_price across ALL zones,
            // regardless of which zone the pickup/drop falls in.
            // This ensures A→B, B→A, and B→B all cost the same flat rate.
            $shortTripPrice = 0.0;
            $shortTripZone  = $originZone['name'];
            foreach ($zones as $z) {
                $p = (float) $z['base_price'];
                if ($p > $shortTripPrice) {
                    $shortTripPrice = $p;
                    $shortTripZone  = $z['name'];
                }
            }

            return [
                'status' => true,
                'price'  => (int) ceil($shortTripPrice),
                'breakdown' => [
                    'mode'         => 'zone',
                    'type'         => 'short_trip',
                    'origin_zone'  => $originZone['name'],
                    'rate_zone'    => $shortTripZone,
                    'base_price'   => round($shortTripPrice, 2),
                    'distance_km'  => $distanceKm,
                    'threshold_km' => $cfg->crossZoneThresholdKm,
                ],
            ];
        }

        // ── RULE 2: long trip — detect zones and sum increments ──────────────
        $zonesInOrder = $this->detectZonesInOrder(
            $polyline, $originLat, $originLng, $destLat, $destLng, $zones
        );

        return $this->buildIncrementalPrice($zonesInOrder, $zones, $distanceKm, $cfg->crossZoneThresholdKm);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Zone traversal detection
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Returns the ordered list of unique zone IDs the route passes through.
     *
     * When a polyline is available every road segment is inspected via its
     * midpoint, giving an accurate zone-crossing sequence.
     *
     * When no polyline is available only the origin and destination are
     * checked — a simple two-zone worst-case approximation.
     *
     * @return int[]  Zone IDs in traversal order (no duplicates)
     */
    private function detectZonesInOrder(
        ?array $polyline,
        float  $originLat,
        float  $originLng,
        float  $destLat,
        float  $destLng,
        array  $zones
    ): array {
        if (!empty($polyline) && count($polyline) >= 2) {
            return $this->traversePolyline($polyline, $zones);
        }

        // Fallback: inspect only the two endpoints
        return $this->traverseTwoPoints(
            $originLat, $originLng,
            $destLat,   $destLng,
            $zones
        );
    }

    /**
     * Walk every polyline segment (via midpoint) and collect unique zone IDs
     * in the order they are first entered.
     *
     * Using midpoints gives a good approximation for the typical short
     * segment lengths produced by the Google Directions API polyline
     * (~50–100 m per step). Accuracy degrades for very long segments but
     * those are uncommon in urban routing.
     *
     * @param  array $polyline [['lat'=>float,'lng'=>float], …]  min 2 points
     * @param  array $zones    Raw zone records from the DB
     * @return int[]
     */
    private function traversePolyline(array $polyline, array $zones): array
    {
        $zonesInOrder = [];
        $seen         = [];   // zone_id => true

        $count = count($polyline);
        for ($i = 0; $i < $count - 1; $i++) {
            $pA = $polyline[$i];
            $pB = $polyline[$i + 1];

            $midLat = ((float) $pA['lat'] + (float) $pB['lat']) / 2.0;
            $midLng = ((float) $pA['lng'] + (float) $pB['lng']) / 2.0;

            $zone = $this->findZoneForPoint($midLat, $midLng, $zones);
            if ($zone === null) {
                continue;
            }

            $zoneId = (int) $zone['id'];
            if (!isset($seen[$zoneId])) {
                $zonesInOrder[] = $zoneId;
                $seen[$zoneId]  = true;
            }
        }

        return $zonesInOrder;
    }

    /**
     * Fallback when no polyline is available: check only the origin and
     * destination points and return their zone IDs (deduplicated).
     *
     * @return int[]
     */
    private function traverseTwoPoints(
        float $originLat, float $originLng,
        float $destLat,   float $destLng,
        array $zones
    ): array {
        $zonesInOrder = [];
        $seen         = [];

        $originZone = $this->findZoneForPoint($originLat, $originLng, $zones);
        if ($originZone) {
            $zonesInOrder[] = (int) $originZone['id'];
            $seen[(int) $originZone['id']] = true;
        }

        $destZone = $this->findZoneForPoint($destLat, $destLng, $zones);
        if ($destZone && !isset($seen[(int) $destZone['id']])) {
            $zonesInOrder[] = (int) $destZone['id'];
        }

        return $zonesInOrder;
    }

    // ══════════════════════════════════════════════════════════════════════
    // Incremental price calculation
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Compute the final price from an ordered list of zone IDs.
     *
     *   price = base_price(zones[0])
     *         + increment_price(zones[1])
     *         + increment_price(zones[2])
     *         + …
     *
     * @param  int[]  $zonesInOrder  Unique zone IDs in traversal order
     * @param  array  $zones         All zone records for the client
     * @param  float  $distanceKm
     * @param  float  $thresholdKm
     */
    private function buildIncrementalPrice(
        array $zonesInOrder,
        array $zones,
        float $distanceKm,
        float $thresholdKm
    ): array {
        if (empty($zonesInOrder)) {
            return [
                'status'  => false,
                'message' => 'La ruta no atraviesa ninguna zona definida.',
            ];
        }

        // Index zones by ID for O(1) lookup
        $zoneIndex = array_column($zones, null, 'id');

        $originZone = $zoneIndex[$zonesInOrder[0]] ?? null;
        if ($originZone === null) {
            return ['status' => false, 'message' => 'Zone resolution failed.'];
        }

        $price      = (float) $originZone['base_price'];
        $increments = [];

        foreach (array_slice($zonesInOrder, 1) as $zoneId) {
            $z = $zoneIndex[$zoneId] ?? null;
            if ($z === null) {
                continue;
            }
            $inc    = (float) $z['increment_price'];
            $price += $inc;
            $increments[] = [
                'zone'            => $z['name'],
                'increment_price' => round($inc, 2),
            ];
        }

        $isCrossZone = count($zonesInOrder) > 1;

        return [
            'status' => true,
            'price'  => (int) ceil($price),
            'breakdown' => [
                'mode'          => 'zone',
                'type'          => $isCrossZone ? 'cross_zone' : 'same_zone',
                'origin_zone'   => $originZone['name'],
                'base_price'    => round((float) $originZone['base_price'], 2),
                'increments'    => $increments,
                'distance_km'   => $distanceKm,
                'threshold_km'  => $thresholdKm,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════
    // Zone lookup helpers
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Load zones for a client, using the intra-request static cache.
     *
     * Only one DB query is issued per client per request, regardless of how
     * many times calculatePrice() or internal helpers call this method.
     *
     * For cross-request persistence wrap this with CI4's cache helper:
     *   cache()->remember("zones_{$clientId}", 300, fn() => …findAll())
     * and call cache()->delete("zones_{$clientId}") on zone create/delete.
     */
    private function getZones(int $clientId): array
    {
        if (!isset(self::$_zoneCache[$clientId])) {
            self::$_zoneCache[$clientId] = $this->zoneModel
                ->where('client_id', $clientId)
                ->findAll();
        }
        return self::$_zoneCache[$clientId];
    }

    /**
     * Point-in-polygon lookup.
     *
     * Decoded polygon coordinates are memoised per zone ID so that
     * json_decode() runs at most once per zone per request, even when
     * a long polyline causes hundreds of midpoint checks.
     */
    private function findZoneForPoint(float $lat, float $lng, array $zones): ?array
    {
        $point = ['lat' => $lat, 'lng' => $lng];

        foreach ($zones as $zone) {
            $zoneId = (int) $zone['id'];

            // Decode and cache the polygon for this zone
            if (!isset(self::$_polygonCache[$zoneId])) {
                $decoded = json_decode($zone['polygon_coordinates'], true);
                self::$_polygonCache[$zoneId] = is_array($decoded) ? $decoded : [];
            }

            $polygon = self::$_polygonCache[$zoneId];
            if (count($polygon) < 3) {
                continue;
            }

            if (GeoHelper::isPointInPolygon($point, $polygon)) {
                return $zone;
            }
        }

        return null;
    }

    /**
     * Find the best zone for a point applying boundary tolerance.
     * Used when the point falls just outside every polygon (snapping).
     *
     * @return array{zone: array, snapped: bool}|null
     */
    private function findBestZoneForPoint(array $point, array $zones, float $toleranceMeters): ?array
    {
        $candidates = GeoHelper::getNearbyZones($point, $zones, $toleranceMeters);

        if (empty($candidates)) {
            return null;
        }

        // Prefer the zone whose boundary is closest (smallest boundary_distance)
        usort($candidates, fn($a, $b) =>
            $a['boundary_distance'] <=> $b['boundary_distance']
        );

        $best = $candidates[0];

        return [
            'zone'    => $best['zone'],
            'snapped' => $best['boundary_distance'] > 0.0,
        ];
    }
}
