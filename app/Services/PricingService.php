<?php

namespace App\Services;

use App\Models\ClientModel;
use App\Models\PricingZoneModel;
use App\Helpers\GeoHelper;

class PricingService
{
    private ClientModel      $clientModel;
    private PricingZoneModel $zoneModel;
    private ZoneMatrixService $matrixService;

    public function __construct()
    {
        $this->clientModel   = new ClientModel();
        $this->zoneModel     = new PricingZoneModel();
        $this->matrixService = new ZoneMatrixService();
    }

    /**
     * Calculates the price of an order based on client's configuration.
     */
    public function calculatePrice(
        int   $clientId,
        float $originLat,
        float $originLng,
        float $destLat,
        float $destLng,
        float $distanceKm
    ): array {
        $client = $this->clientModel->find($clientId);

        if (!$client) {
            return ['status' => false, 'message' => 'Client not found'];
        }

        $pricingMode = $client['pricing_mode'] ?? 'distance';

        // ── DISTANCE MODE ────────────────────────────────────────────────────
        if ($pricingMode === 'distance') {
            $baseFare   = (float)($client['base_fare']    ?? 0);
            $pricePerKm = (float)($client['price_per_km'] ?? $client['cost_per_trip'] ?? 0);
            $total      = $baseFare + ($distanceKm * $pricePerKm);

            return [
                'status' => true,
                'price'  => round($total, 2),
                'breakdown' => [
                    'mode'         => 'distance',
                    'base_fare'    => $baseFare,
                    'distance_km'  => $distanceKm,
                    'price_per_km' => $pricePerKm,
                ],
            ];
        }

        // ── ZONE MODE ────────────────────────────────────────────────────────
        if ($pricingMode === 'zone') {
            $zones = $this->zoneModel->where('client_id', $clientId)->findAll();

            $originZone = $this->findZoneForPoint($originLat, $originLng, $zones);
            $destZone   = $this->findZoneForPoint($destLat, $destLng, $zones);

            // Both points must be inside a defined zone
            if (!$originZone) {
                return [
                    'status'  => false,
                    'message' => 'Fuera de cobertura: el punto de recogida está fuera de las zonas definidas.',
                ];
            }

            if (!$destZone) {
                return [
                    'status'  => false,
                    'message' => 'Fuera de cobertura: el punto de entrega está fuera de las zonas definidas.',
                ];
            }

            // ── Look up matrix price ─────────────────────────────────────────
            $matrixPrice = $this->matrixService->resolvePrice(
                $clientId,
                (int)$originZone['id'],
                (int)$destZone['id']
            );

            if ($matrixPrice !== null) {
                // Matrix entry found (manual override or auto-generated)
                $isSameZone = $originZone['id'] === $destZone['id'];
                return [
                    'status' => true,
                    'price'  => round($matrixPrice, 2),
                    'breakdown' => [
                        'mode'             => 'zone',
                        'type'             => $isSameZone ? 'same_zone' : 'cross_zone',
                        'origin_zone'      => $originZone['name'],
                        'destination_zone' => $destZone['name'],
                        'price_source'     => 'matrix',
                    ],
                ];
            }

            // ── Fallback: matrix not yet generated, use max() rule directly ──
            $fallbackPrice = max(
                (float)$originZone['base_price'],
                (float)$destZone['base_price']
            );

            return [
                'status' => true,
                'price'  => round($fallbackPrice, 2),
                'breakdown' => [
                    'mode'             => 'zone',
                    'type'             => 'cross_zone_fallback',
                    'origin_zone'      => $originZone['name'],
                    'destination_zone' => $destZone['name'],
                    'price_source'     => 'auto_max',
                ],
            ];
        }

        return ['status' => false, 'message' => 'Invalid pricing mode'];
    }

    /**
     * Find the zone that contains a given lat/lng point using ray-casting.
     */
    private function findZoneForPoint(float $lat, float $lng, array $zones): ?array
    {
        $point = ['lat' => $lat, 'lng' => $lng];
        foreach ($zones as $zone) {
            $polygon = json_decode($zone['polygon_coordinates'], true);
            if (is_array($polygon) && GeoHelper::isPointInPolygon($point, $polygon)) {
                return $zone;
            }
        }
        return null;
    }
}
