<?php

namespace App\Services;

class MockDistanceMatrixService
{
    /**
     * Mocks a call to Google Maps Distance Matrix API.
     * Generates a random distance between 1km and 20km to simulate real conditions.
     */
    public function getDistanceInKm(string $origin, string $destination): float
    {
        /** 
         * Simple mock random generation for development 
         * E.g., returns values between 1.0 and 20.0 km
         */
        return mt_rand(10, 200) / 10;
    }
}
