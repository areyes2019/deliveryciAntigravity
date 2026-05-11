<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuración de OpenAI
 *
 * Centraliza todas las constantes necesarias para consumir la API de OpenAI.
 * Los valores sensibles (API Key) se cargan desde el archivo .env
 * mediante el helper env() de CodeIgniter 4.
 *
 * Ubicación: app/Config/OpenAI.php
 * 
 * Uso en Services:
 *   $config = config('OpenAI');
 *   $apiKey = $config->apiKey;
 */
class OpenAI extends BaseConfig
{
    /**
     * API Key de OpenAI
     *
     * Se obtiene de la variable de entorno OPENAI_API_KEY definida en .env
     * Si no está definida, se usa una cadena vacía como fallback seguro.
     *
     * @var string
     */
    public string $apiKey = '';

    /**
     * Modelo de OpenAI a utilizar
     *
     * Recomendado: 'gpt-4o-mini' por su equilibrio entre velocidad, costo y precisión.
     * Alternativas: 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo'.
     *
     * @var string
     */
    public string $model = 'gpt-4o-mini';

    /**
     * Temperatura del modelo (0.0 - 2.0)
     *
     * Controla la creatividad de la respuesta.
     * 0.0 = más determinista / consistente (ideal para extracción de datos)
     * 1.0 = balanceado
     * 2.0 = más creativo / aleatorio
     *
     * @var float
     */
    public float $temperature = 0.0;

    /**
     * Máximo de tokens en la respuesta
     *
     * Limita el tamaño de la respuesta de la IA.
     * 300 tokens son suficientes para devolver un JSON pequeño.
     *
     * @var int
     */
    public int $maxTokens = 300;

    /**
     * Timeout de la petición HTTP a OpenAI en segundos
     *
     * @var int
     */
    public int $timeout = 30;

    /**
     * URL base de la API de OpenAI
     *
     * @var string
     */
    public string $baseUrl = 'https://api.openai.com/v1/chat/completions';

    // --------------------------------------------------------------------
    // Constructor: carga valores desde .env
    // --------------------------------------------------------------------

    public function __construct()
    {
        parent::__construct();

        // Cargar la API Key desde el archivo .env
        $this->apiKey = (string) env('OPENAI_API_KEY', '');
    }
}
