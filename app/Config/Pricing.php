<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Pricing extends BaseConfig
{
    /**
     * Zone boundary tolerance in metres (zone mode only).
     *
     * If a pickup or drop point falls within this distance of a zone boundary,
     * that zone is included as a candidate. The cheapest touching zone wins.
     * Prevents abrupt price jumps when a point sits just across a border.
     *
     * Recommended range: 50–150 metres.
     */
    public float $boundaryToleranceMeters = 100.0;

    /**
     * Cross-zone distance threshold in km (zone mode only).
     *
     * Trips whose total distance is LESS THAN OR EQUAL to this value always
     * pay the flat base_price of the origin zone — regardless of how many
     * zone boundaries the route crosses.
     *
     * Trips LONGER than this threshold and that cross zones pay the SUM of
     * each traversed zone's base_price.
     *
     * Default: 10 km
     */
    public float $crossZoneThresholdKm = 3.0;
}
