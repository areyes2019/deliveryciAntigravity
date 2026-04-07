<?php

namespace App\Helpers;

class GeoHelper
{
    /**
     * Determine if a point is inside a polygon using the Ray-Casting algorithm.
     *
     * @param array $point ['lat' => float, 'lng' => float]
     * @param array $polygon Array of points [['lat' => float, 'lng' => float], ...]
     * @return bool
     */
    public static function isPointInPolygon(array $point, array $polygon): bool
    {
        $x = (float) $point['lng'];
        $y = (float) $point['lat'];
        
        $inside = false;
        
        $count = count($polygon);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $polygon[$i]['lng'];
            $yi = (float) $polygon[$i]['lat'];
            
            $xj = (float) $polygon[$j]['lng'];
            $yj = (float) $polygon[$j]['lat'];
            
            $intersect = (($yi > $y) != ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);
                
            if ($intersect) {
                $inside = !$inside;
            }
        }
        
        return $inside;
    }
}
