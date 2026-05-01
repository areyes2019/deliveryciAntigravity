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
    $routes->get('geofences', 'GeofenceController::index', ['filter' => 'jwt:client_admin']);
    $routes->post('geofences', 'GeofenceController::store', ['filter' => 'jwt:client_admin']);
    $routes->delete('geofences/(:num)', 'GeofenceController::destroy/$1', ['filter' => 'jwt:client_admin']);
    $routes->post('validate-geofence', 'GeofenceController::checkPoints', ['filter' => 'jwt:client_admin']);
    $routes->get('zones', 'PricingController::getZones', ['filter' => 'jwt:client_admin']);
    $routes->post('zones', 'PricingController::createZone', ['filter' => 'jwt:client_admin']);
    $routes->delete('zones/(:num)', 'PricingController::deleteZone/$1', ['filter' => 'jwt:client_admin']);

    // Drivers
    $routes->get('drivers', 'DriverController::index', ['filter' => 'jwt:client_admin']);
    $routes->post('drivers', 'DriverController::create', ['filter' => 'jwt:client_admin']);
    $routes->put('drivers/(:num)', 'DriverController::update/$1', ['filter' => 'jwt:client_admin']);
    $routes->delete('drivers/(:num)', 'DriverController::delete/$1', ['filter' => 'jwt:client_admin']);

    // Driver Billing Config
    $routes->get('driver-billing', 'DriverBillingConfigController::getConfig', ['filter' => 'jwt:client_admin']);
    $routes->put('driver-billing', 'DriverBillingConfigController::saveConfig', ['filter' => 'jwt:client_admin']);

    // Wallet
    $routes->group('wallet', ['filter' => 'jwt:superadmin'], function($routes) {
        $routes->post('withdraw', 'WalletController::withdraw');
        $routes->post('add-income', 'WalletController::addIncome');
    });
    $routes->post('wallet/recharge', 'WalletController::recharge', ['filter' => 'jwt:client_admin']);
    $routes->get('wallet/balance/(:num)', 'WalletController::getBalance/$1', ['filter' => 'jwt:superadmin,driver']);
    $routes->get('wallet/movements/(:num)', 'WalletController::getMovements/$1', ['filter' => 'jwt:superadmin,driver']);
    $routes->get('wallet/today/(:num)', 'WalletController::getTodayStats/$1', ['filter' => 'jwt:superadmin,driver']);

    $routes->group('driver', ['filter' => 'jwt:driver'], function($routes) {
        $routes->get('trips/available', 'Driver\DriverApiController::availableTrips');
        $routes->get('trips/current', 'Driver\DriverApiController::getCurrentTrip');
        $routes->post('trips/(:num)/accept', 'Driver\DriverApiController::acceptTrip/$1');
        $routes->post('trips/(:num)/status', 'Driver\DriverApiController::updateStatus/$1');
        $routes->post('location', 'Driver\DriverApiController::updateLocation');
        $routes->post('toggle-availability', 'DriverController::toggleAvailability');
        $routes->post('go-offline', 'DriverController::goOffline');
    });

$routes->options('(:any)', 'Home::index'); // Let CorsFilter intercept
});

$routes->options('(:any)', 'Home::index');

// SPA fallback: sirve el index.html de Vue para todas las rutas frontend
$routes->get('(:any)', 'Home::vue');
