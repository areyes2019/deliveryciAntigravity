<?php

namespace App\Services;

/**
 * Simulador del servicio de distancias de Google Maps.
 *
 * Reemplaza temporalmente la llamada real a la Google Maps Distance Matrix API
 * durante el desarrollo y pruebas locales, evitando consumir cuota de la API.
 *
 * IMPORTANTE: Este servicio NO debe usarse en producción.
 * En producción, el frontend envía la distancia real calculada por Google Maps
 * directamente en el payload de la orden (`distance_km`), por lo que este
 * servicio ya no es necesario en ese flujo.
 *
 * Si en el futuro se necesita calcular distancias server-side, reemplazar
 * este mock por una implementación real que llame a:
 * https://maps.googleapis.com/maps/api/distancematrix/json
 */
class MockDistanceMatrixService
{
    /**
     * Simula una llamada a Google Maps Distance Matrix API.
     *
     * Genera una distancia aleatoria entre 1.0 y 20.0 km para simular
     * condiciones reales durante el desarrollo.
     *
     * @param  string $origin      Punto de origen (no usado en el mock).
     * @param  string $destination Punto de destino (no usado en el mock).
     * @return float  Distancia simulada en kilómetros.
     */
    public function getDistanceInKm(string $origin, string $destination): float
    {
        return mt_rand(10, 200) / 10;
    }
}
