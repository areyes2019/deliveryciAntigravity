<?php

namespace App\Helpers;

class GeoHelper
{
    private const EARTH_RADIUS_METERS = 6371000;

    /**
     * Determine if a point is inside a polygon using the Ray-Casting algorithm.
     *
     * @param array $point   ['lat' => float, 'lng' => float]
     * @param array $polygon [['lat' => float, 'lng' => float], ...]
     */
    public static function isPointInPolygon(array $point, array $polygon): bool
    {
        $x = (float) $point['lng'];
        $y = (float) $point['lat'];

        $inside = false;
        $count  = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $polygon[$i]['lng'];
            $yi = (float) $polygon[$i]['lat'];
            $xj = (float) $polygon[$j]['lng'];
            $yj = (float) $polygon[$j]['lat'];

            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    /**
     * Haversine formula — distance in metres between two lat/lng points.
     *
     * @param array $a ['lat' => float, 'lng' => float]
     * @param array $b ['lat' => float, 'lng' => float]
     */
    public static function haversineDistance(array $a, array $b): float
    {
        $lat1 = deg2rad((float) $a['lat']);
        $lat2 = deg2rad((float) $b['lat']);
        $dLat = deg2rad((float) $b['lat'] - (float) $a['lat']);
        $dLng = deg2rad((float) $b['lng'] - (float) $a['lng']);

        $h = sin($dLat / 2) ** 2
           + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_METERS * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    /**
     * Minimum distance in metres from a point to a single polygon edge (line segment).
     *
     * Uses a flat-earth (local Cartesian) approximation — accurate to < 0.1 %
     * for city-scale distances (< 20 km).
     *
     * @param array $point ['lat' => float, 'lng' => float]
     * @param array $segA  ['lat' => float, 'lng' => float]  segment start
     * @param array $segB  ['lat' => float, 'lng' => float]  segment end
     */
    public static function distanceToSegmentMeters(array $point, array $segA, array $segB): float
    {
        $R = self::EARTH_RADIUS_METERS;

        // Reference latitude for longitude → metre scaling (average of all three points)
        $refLat = deg2rad(((float) $point['lat'] + (float) $segA['lat'] + (float) $segB['lat']) / 3);
        $scale  = cos($refLat);

        // Translate to local Cartesian metres with segA as origin
        $px = deg2rad((float) $point['lng'] - (float) $segA['lng']) * $scale * $R;
        $py = deg2rad((float) $point['lat'] - (float) $segA['lat'])         * $R;
        $bx = deg2rad((float) $segB['lng']  - (float) $segA['lng']) * $scale * $R;
        $by = deg2rad((float) $segB['lat']  - (float) $segA['lat'])         * $R;

        $lenSq = $bx * $bx + $by * $by;

        // Degenerate segment (both endpoints identical) → distance to the point itself
        if ($lenSq < 1e-10) {
            return sqrt($px * $px + $py * $py);
        }

        // Project P onto the segment and clamp to [0, 1]
        $t  = max(0.0, min(1.0, ($px * $bx + $py * $by) / $lenSq));
        $dx = $px - $t * $bx;
        $dy = $py - $t * $by;

        return sqrt($dx * $dx + $dy * $dy);
    }

    /**
     * Minimum distance in metres from a point to any edge of a polygon.
     * Returns 0.0 if the point is inside the polygon.
     *
     * @param array $point   ['lat' => float, 'lng' => float]
     * @param array $polygon [['lat' => float, 'lng' => float], ...]
     */
    public static function distanceToPolygon(array $point, array $polygon): float
    {
        // Points inside the polygon are at distance 0 from its interior
        if (self::isPointInPolygon($point, $polygon)) {
            return 0.0;
        }

        $min   = PHP_FLOAT_MAX;
        $count = count($polygon);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $dist = self::distanceToSegmentMeters($point, $polygon[$j], $polygon[$i]);
            if ($dist < $min) {
                $min = $dist;
            }
        }

        return $min;
    }

    /**
     * Return all zones whose polygon is within $radiusMeters of $point.
     *
     * Zones that contain the point (boundary_distance = 0) are always included.
     * Each entry in the returned array carries the zone record and the distance
     * to its boundary, so callers can sort or filter as needed.
     *
     * @param array $point        ['lat' => float, 'lng' => float]
     * @param array $zones        Raw zone records from the DB (must have polygon_coordinates)
     * @param float $radiusMeters Boundary tolerance radius in metres (e.g. 100)
     * @return array              [['zone' => [...], 'boundary_distance' => float], ...]
     */
    public static function getNearbyZones(array $point, array $zones, float $radiusMeters): array
    {
        $result = [];

        foreach ($zones as $zone) {
            $polygon = json_decode($zone['polygon_coordinates'], true);

            if (!is_array($polygon) || count($polygon) < 3) {
                continue;
            }

            $dist = self::distanceToPolygon($point, $polygon);

            if ($dist <= $radiusMeters) {
                $result[] = [
                    'zone'              => $zone,
                    'boundary_distance' => $dist,
                ];
            }
        }

        return $result;
    }
}
