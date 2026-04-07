<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => 'corsFilter'], static function($routes) {
    $routes->post('auth/login', 'Auth::login');
    $routes->get('auth/me', 'Auth::me', ['filter' => 'jwt']);

    // Orders
    $routes->get('orders', 'OrderController::index', ['filter' => 'jwt:client_admin,superadmin']);
    $routes->post('orders', 'OrderController::create', ['filter' => 'jwt:client_admin']);
    $routes->put('orders/(:num)/cancel', 'OrderController::cancel/$1', ['filter' => 'jwt:client_admin,superadmin']);

    // Clients (SuperAdmin only)
    $routes->get('clients', 'ClientController::index', ['filter' => 'jwt:superadmin']);
    $routes->get('clients/(:num)', 'ClientController::show/$1', ['filter' => 'jwt:superadmin']);
    $routes->post('clients', 'ClientController::create', ['filter' => 'jwt:superadmin']);
    $routes->put('clients/(:num)', 'ClientController::update/$1', ['filter' => 'jwt:superadmin']);
    $routes->delete('clients/(:num)', 'ClientController::delete/$1', ['filter' => 'jwt:superadmin']);
    $routes->post('clients/(:num)/add-credits', 'ClientController::addCredits/$1', ['filter' => 'jwt:superadmin']);

    // Pricing Config & Zones
    $routes->put('pricing-config', 'PricingController::updateConfig', ['filter' => 'jwt:client_admin']);
    $routes->post('calculate-price', 'PricingController::calculatePreview', ['filter' => 'jwt:client_admin']);
    $routes->get('zones', 'PricingController::getZones', ['filter' => 'jwt:client_admin']);
    $routes->post('zones', 'PricingController::createZone', ['filter' => 'jwt:client_admin']);
    $routes->delete('zones/(:num)', 'PricingController::deleteZone/$1', ['filter' => 'jwt:client_admin']);

    // Drivers
    $routes->get('drivers', 'DriverController::index', ['filter' => 'jwt:client_admin']);
    $routes->post('drivers', 'DriverController::create', ['filter' => 'jwt:client_admin']);
    $routes->put('drivers/(:num)', 'DriverController::update/$1', ['filter' => 'jwt:client_admin']);
    $routes->delete('drivers/(:num)', 'DriverController::delete/$1', ['filter' => 'jwt:client_admin']);

    $routes->group('driver', ['filter' => 'jwt:driver'], function($routes) {
        $routes->get('trips/available', 'Driver\DriverApiController::availableTrips');
        $routes->post('trips/(:num)/accept', 'Driver\DriverApiController::acceptTrip/$1');
        $routes->post('trips/(:num)/status', 'Driver\DriverApiController::updateStatus/$1');
        $routes->post('location', 'Driver\DriverApiController::updateLocation');
    });

    $routes->options('(:any)', 'Home::index'); // Let CorsFilter intercept
});

$routes->options('(:any)', 'Home::index');
