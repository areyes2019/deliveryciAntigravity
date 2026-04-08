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
            'http://localhost:3000',
            'http://delivery.test',
            'https://delivery.test',
            'https://unalliterative-semimagnetic-tamiko.ngrok-free.dev',
        ],

        'allowedOriginsPatterns' => [
            'https://[\w-]+\.ngrok-free\.dev',
            'https://[\w-]+\.ngrok\.io',
        ],

        'supportsCredentials' => false,

        'allowedHeaders' => [
            'Origin',
            'X-Requested-With',
            'Content-Type',
            'Accept',
            'Authorization',
            'ngrok-skip-browser-warning',
        ],

        'exposedHeaders' => [],

        'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

        'maxAge' => 7200,
    ];
}
