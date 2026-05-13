<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    /**
     * The default CORS configuration.
     *
     * @var array{
     *      allowedOrigins: list<string>,
     *      allowedOriginsPatterns: list<string>,
     *      supportsCredentials: bool,
     *      allowedHeaders: list<string>,
     *      exposedHeaders: list<string>,
     *      allowedMethods: list<string>,
     *      maxAge: int,
     *  }
     */
    public array $default = [
        'allowedOrigins' => [
            'http://localhost:5173',
            'http://localhost:5174',
            'http://127.0.0.1:5173',
            'http://127.0.0.1:5174',
            'http://localhost:3000',
            'http://delivery.test',
            'https://delivery.test',
            'http://panda_expres.test',
            'https://unalliterative-semimagnetic-tamiko.ngrok-free.dev',
            // 'https://tu-dominio-produccion.com',  ← agrega aquí tu dominio real
        ],

        'allowedOriginsPatterns' => [
            'https://[\w-]+\.ngrok-free\.dev',
            'https://[\w-]+\.ngrok\.io',
        ],

        'supportsCredentials' => true,

        'allowedHeaders' => [
            'Origin',
            'X-Requested-With',
            'Content-Type',
            'Accept',
            'Authorization',
            'ngrok-skip-browser-warning',
        ],

        'exposedHeaders' => [
            'Content-Type',
            'Authorization',
            'X-Requested-With',
        ],

        'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

        'maxAge' => 7200,
    ];
}
