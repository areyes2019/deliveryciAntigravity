<?php

namespace App\Services;

use App\Models\ClientModel;
use App\Models\PricingZoneModel;
use App\Helpers\GeoHelper;

class PricingService
{
    private ClientModel $clientModel;
    private PricingZoneModel $zoneModel;

    public function __construct()
    {
        $this->clientModel = new ClientModel();
        $this->zoneModel = new PricingZoneModel();
    }

    /**
     * Calculates the price of an order based on client's configuration
     */
    public function calculatePrice(int $clientId, float $originLat, float $originLng, float $destLat, float $destLng, float $distanceKm): array
    {
        $client = $this->clientModel->find($clientId);

        if (!$client) {
            return ['status' => false, 'message' => 'Client not found'];
        }

        $pricingMode = $client['pricing_mode'] ?? 'distance';

        if ($pricingMode === 'distance') {
            $baseFare = (float)($client['base_fare'] ?? 0);
            $pricePerKm = (float)($client['price_per_km'] ?? $client['cost_per_trip'] ?? 0);

            $total = $baseFare + ($distanceKm * $pricePerKm);
            
            return [
                'status' => true,
                'price' => round($total, 2),
                'breakdown' => [
                    'mode' => 'distance',
                    'base_fare' => $baseFare,
                    'distance_km' => $distanceKm,
                    'price_per_km' => $pricePerKm
                ]
            ];
        }

        if ($pricingMode === 'zone') {
            $zones = $this->zoneModel->where('client_id', $clientId)->findAll();
            
            $originZone = $this->findZoneForPoint($originLat, $originLng, $zones);
            $destZone = $this->findZoneForPoint($destLat, $destLng, $zones);

            if (!$originZone || !$destZone) {
                return [
                    'status' => false, 
                    'message' => 'Fuera de cobertura: No hay zona definida para uno o ambos puntos de la ruta.'
                ];
            }

            if ($originZone['id'] === $destZone['id']) {
                $price = (float)$originZone['base_price'];
                return [
                    'status' => true,
                    'price' => round($price, 2),
                    'breakdown' => [
                        'mode' => 'zone',
                        'type' => 'same_zone',
                        'zone_name' => $originZone['name']
                    ]
                ];
            }

            // Cross-zone
            $price = (float)$originZone['base_price'] + (float)$destZone['base_price'];
            return [
                'status' => true,
                'price' => round($price, 2),
                'breakdown' => [
                    'mode' => 'zone',
                    'type' => 'cross_zone',
                    'origin_zone' => $originZone['name'],
                    'dest_zone' => $destZone['name']
                ]
            ];
        }

        return ['status' => false, 'message' => 'Invalid pricing mode'];
    }

    private function findZoneForPoint(float $lat, float $lng, array $zones)
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
