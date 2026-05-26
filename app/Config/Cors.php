<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Cors extends BaseConfig
{
    /**
     * Configuración CORS por defecto
     */
    public array $default = [
        'allowedOrigins'          => ['*'],
        'allowedOriginsPatterns'  => [],
        'allowedHeaders'          => ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept', 'Origin'],
        'allowedMethods'          => ['GET', 'POST', 'OPTIONS', 'PUT', 'DELETE', 'PATCH'],
        'supportsCredentials'     => false,
        'exposedHeaders'          => [],
    ];
}
