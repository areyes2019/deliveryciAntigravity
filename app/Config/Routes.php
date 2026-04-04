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

    // Clients (SuperAdmin only)
    $routes->get('clients', 'ClientController::index', ['filter' => 'jwt:superadmin']);
    $routes->get('clients/(:num)', 'ClientController::show/$1', ['filter' => 'jwt:superadmin']);
    $routes->post('clients', 'ClientController::create', ['filter' => 'jwt:superadmin']);
    $routes->put('clients/(:num)', 'ClientController::update/$1', ['filter' => 'jwt:superadmin']);
    $routes->delete('clients/(:num)', 'ClientController::delete/$1', ['filter' => 'jwt:superadmin']);
    $routes->post('clients/(:num)/add-credits', 'ClientController::addCredits/$1', ['filter' => 'jwt:superadmin']);

    // Drivers
    $routes->get('drivers', 'DriverController::index', ['filter' => 'jwt:client_admin']);
    $routes->post('drivers', 'DriverController::create', ['filter' => 'jwt:client_admin']);
    $routes->put('drivers/(:num)', 'DriverController::update/$1', ['filter' => 'jwt:client_admin']);
    $routes->delete('drivers/(:num)', 'DriverController::delete/$1', ['filter' => 'jwt:client_admin']);

    $routes->options('(:any)', 'Home::index'); // Let CorsFilter intercept
});

$routes->options('(:any)', 'Home::index');
