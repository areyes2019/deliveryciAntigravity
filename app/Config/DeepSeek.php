<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuración de DeepSeek API
 *
 * Centraliza todas las constantes necesarias para consumir la API de DeepSeek.
 * DeepSeek es una alternativa más rápida y económica a OpenAI.
 * Su API es compatible con el formato de Chat Completions de OpenAI.
 *
 * Los valores sensibles (API Key) se cargan desde el archivo .env
 * mediante el helper env() de CodeIgniter 4.
 *
 * Ubicación: app/Config/DeepSeek.php
 * 
 * Uso en Services:
 *   $config = config('DeepSeek');
 *   $apiKey = $config->apiKey;
 */
class DeepSeek extends BaseConfig
{
    /**
     * API Key de DeepSeek
     *
     * Se obtiene de la variable de entorno DEEPSEEK_API_KEY definida en .env
     * Si no está definida, se usa una cadena vacía como fallback seguro.
     *
     * @var string
     */
    public string $apiKey = '';

    /**
     * Modelo de DeepSeek a utilizar
     *
     * Recomendado: 'deepseek-chat' para tareas rápidas y económicas.
     * Alternativa: 'deepseek-reasoner' para tareas que requieren razonamiento.
     *
     * @var string
     */
    public string $model = 'deepseek-chat';

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
     * Timeout de la petición HTTP a DeepSeek en segundos
     *
     * @var int
     */
    public int $timeout = 30;

    /**
     * URL base de la API de DeepSeek (Chat Completions)
     *
     * DeepSeek usa el mismo endpoint /chat/completions que OpenAI.
     *
     * @var string
     */
    public string $baseUrl = 'https://api.deepseek.com/v1/chat/completions';

    // --------------------------------------------------------------------
    // Constructor: carga valores desde .env
    // --------------------------------------------------------------------

    public function __construct()
    {
        parent::__construct();

        // Cargar la API Key desde el archivo .env
        $this->apiKey = (string) env('DEEPSEEK_API_KEY', '');
    }
}
