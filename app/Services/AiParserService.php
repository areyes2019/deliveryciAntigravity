<?php

namespace App\Services;

use Config\DeepSeek;

/**
 * Servicio de Parseo con IA
 *
 * Esta clase orquesta la comunicación con la API de DeepSeek para interpretar
 * mensajes de WhatsApp escritos en lenguaje natural por los clientes y
 * convertirlos en datos estructurados (pickup_address, delivery_address,
 * scheduled_time, notes).
 *
 * Flujo:
 *   1. Recibe un mensaje de texto plano (ej. "Necesito un envio de mi
 *      ubicacion a la calle emeteria valencia 56 col centro a las 5:00").
 *   2. Construye un prompt especializado que le indica a la IA que debe
 *      responder ÚNICAMENTE con JSON válido.
 *   3. Envía el prompt a la API de DeepSeek (Chat Completions).
 *   4. Recibe la respuesta, la parsea y valida.
 *   5. Devuelve un array limpio con los campos estructurados.
 *
 * Ubicación: app/Services/AiParserService.php
 *
 * @package App\Services
 */
class AiParserService
{
    /**
     * Instancia de la configuración de DeepSeek.
     *
     * @var DeepSeek
     */
    private DeepSeek $deepSeekConfig;

    /**
     * Cliente HTTP de CodeIgniter 4 para hacer peticiones a la API.
     *
     * @var \CodeIgniter\HTTP\CURLRequest
     */
    private \CodeIgniter\HTTP\CURLRequest $httpClient;

    public function __construct()
    {
        // Cargar la configuración de DeepSeek desde app/Config/DeepSeek.php
        $this->deepSeekConfig = config('DeepSeek');

        // Inicializar el cliente HTTP de CodeIgniter 4
        // El servicio 'curlrequest' devuelve una instancia lista para usar
        $this->httpClient = service('curlrequest');
    }

    /**
     * Método principal: parsea un mensaje de texto y devuelve datos
     * estructurados de envío.
     *
     * @param string $mensaje Texto crudo del mensaje de WhatsApp del cliente.
     *
     * @return array{
     *   status: bool,
     *   message: string,
     *   data?: array{
     *     pickup_address: string,
     *     delivery_address: string,
     *     scheduled_time: string|null,
     *     notes: string
     *   }
     * }
     */
    public function parse(string $mensaje): array
    {
        // --------------------------------------------------------------------
        // VALIDACIÓN DE ENTRADA
        // --------------------------------------------------------------------
        // Si el mensaje está vacío o solo tiene espacios, no tiene sentido
        // enviarlo a DeepSeek (gastaríamos tokens y créditos innecesariamente).
        // --------------------------------------------------------------------

        $mensaje = trim($mensaje);

        if (empty($mensaje)) {
            return [
                'status'  => false,
                'message' => 'El mensaje no puede estar vacío.',
            ];
        }

        // --------------------------------------------------------------------
        // VALIDACIÓN DE API KEY
        // --------------------------------------------------------------------
        // Verificar que la API Key esté configurada en el .env antes de
        // hacer cualquier petición. Si falta, no tiene caso continuar.
        // --------------------------------------------------------------------

        if (empty($this->deepSeekConfig->apiKey)) {
            return [
                'status'  => false,
                'message' => 'La API Key de DeepSeek no está configurada. Verifica tu archivo .env.',
            ];
        }

        // --------------------------------------------------------------------
        // CONSTRUCCIÓN DEL PROMPT
        // --------------------------------------------------------------------
        // El prompt es la instrucción que le damos a la IA. Es crítico ser
        // explícito y restrictivo para obtener respuestas predecibles.
        //
        // Reglas clave del prompt:
        //   - Exigir SOLO JSON válido (sin markdown, sin explicaciones).
        //   - Definir claramente los 4 campos que debe devolver.
        //   - Incluir el mensaje del usuario al final.
        //   - Dar ejemplos de valores esperados para cada campo.
        // --------------------------------------------------------------------

        $systemPrompt = <<<'PROMPT'
Eres un asistente especializado en logística y delivery.
Tu ÚNICA función es extraer datos estructurados de mensajes de clientes que solicitan envíos.

REGLAS ESTRICTAS:
1. Responde ÚNICAMENTE con JSON válido. NADA más. No uses markdown, no expliques nada.
2. El JSON debe tener EXACTAMENTE esta estructura, sin campos adicionales:
   {
     "pickup_address": "string",
     "delivery_address": "string",
     "scheduled_time": "string|null",
     "notes": "string"
   }

DEFINICIÓN DE CAMPOS:
- pickup_address:   Lugar de recogida (origen). Si el cliente dice "de mi ubicacion", usa "Ubicación actual del cliente".
- delivery_address: Lugar de entrega (destino). Obligatorio.
- scheduled_time:   Hora programada en formato TIME (HH:MM:SS). Si dice "a las 5:00", devuelve "17:00:00". Si no menciona hora, devuelve null.
- notes:            Cualquier información adicional útil (referencias, instrucciones especiales, etc.). Si no hay información extra, devuelve cadena vacía "".

EJEMPLOS:
Cliente: "Necesito un envio de mi ubicacion a la calle emeteria valencia 56 col centro a las 5:00"
Respuesta: {"pickup_address": "Ubicación actual del cliente", "delivery_address": "Calle Emeteria Valencia 56, Col Centro", "scheduled_time": "17:00:00", "notes": ""}

Cliente: "Manda traer una pizza de la pizzeria de la esquina a mi casa"
Respuesta: {"pickup_address": "Pizzería de la esquina", "delivery_address": "Domicilio del cliente", "scheduled_time": null, "notes": "Pizza"}

Cliente: "Recoge un paquete en Av Reforma 222 y llevalo a Insurgentes Sur 333, que sea para las 3:30pm, tocar timbre dos veces"
Respuesta: {"pickup_address": "Av Reforma 222", "delivery_address": "Insurgentes Sur 333", "scheduled_time": "15:30:00", "notes": "Tocar timbre dos veces"}

Mensaje del cliente:
PROMPT;

        // --------------------------------------------------------------------
        // ARMAR EL PAYLOAD PARA LA API DE DEEPSEEK
        // --------------------------------------------------------------------
        // DeepSeek usa el mismo formato de Chat Completions que OpenAI,
        // por lo que el payload es idéntico.
        //
        // La API recibe un array de "messages" donde cada mensaje tiene
        // un "role" (system, user, assistant) y "content".
        //
        // Usamos:
        //   - system:   instrucción fija que define el comportamiento de la IA
        //   - user:     el mensaje concreto del cliente a parsear
        // --------------------------------------------------------------------

        $payload = [
            'model'       => $this->deepSeekConfig->model,
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role'    => 'user',
                    'content' => $mensaje,
                ],
            ],
            'temperature' => $this->deepSeekConfig->temperature,
            'max_tokens'  => $this->deepSeekConfig->maxTokens,
        ];

        // Log para depuración: registramos el payload (sin la API Key)
        log_message('info', '[AiParserService] Enviando petición a DeepSeek', [
            'model'      => $this->deepSeekConfig->model,
            'max_tokens' => $this->deepSeekConfig->maxTokens,
            'mensaje'    => $mensaje,
        ]);

        // --------------------------------------------------------------------
        // PETICIÓN HTTP A DEEPSEEK
        // --------------------------------------------------------------------
        // Usamos el cliente CURLRequest de CI4 que ya maneja timeouts,
        // headers y formateo de respuesta.
        // --------------------------------------------------------------------

        try {
            $response = $this->httpClient->post(
                $this->deepSeekConfig->baseUrl,
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->deepSeekConfig->apiKey,
                        'Content-Type'  => 'application/json',
                    ],
                    'json'    => $payload,
                    'timeout' => $this->deepSeekConfig->timeout,
                ]
            );
        } catch (\Throwable $e) {
            // --------------------------------------------------------------------
            // ERROR DE CONEXIÓN
            // --------------------------------------------------------------------
            // Captura errores de red, DNS, timeouts, etc.
            // Por ejemplo: si el servidor de DeepSeek no responde, si hay
            // problemas de conexión a Internet, etc.
            // --------------------------------------------------------------------
            log_message('error', '[AiParserService] Error de conexión con DeepSeek: ' . $e->getMessage());

            return [
                'status'  => false,
                'message' => 'Error de conexión con el servicio de IA. Intenta de nuevo más tarde.',
            ];
        }

        // --------------------------------------------------------------------
        // VALIDACIÓN DEL CÓDIGO HTTP
        // --------------------------------------------------------------------
        // DeepSeek devuelve 200 si todo está bien, 4xx si hay error del cliente
        // (ej. API Key inválida, modelo no disponible), 5xx si hay error interno.
        // --------------------------------------------------------------------

        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $body = $response->getBody();
            log_message('error', '[AiParserService] DeepSeek respondió con código ' . $statusCode . ': ' . $body);

            return [
                'status'  => false,
                'message' => 'El servicio de IA respondió con un error (código ' . $statusCode . '). Verifica tu API Key y configuración.',
            ];
        }

        // --------------------------------------------------------------------
        // PARSEO DE LA RESPUESTA DE DEEPSEEK
        // --------------------------------------------------------------------
        // DeepSeek devuelve el mismo formato que OpenAI:
        // {
        //   "choices": [
        //     {
        //       "message": {
        //         "content": "{\"pickup_address\": \"...\", ...}"
        //       }
        //     }
        //   ]
        // }
        //
        // El contenido que nos interesa está en choices[0].message.content
        // y debería ser un string JSON válido.
        // --------------------------------------------------------------------

        $responseBody = json_decode($response->getBody(), true);

        // Validar que el JSON de respuesta sea válido
        if (json_last_error() !== JSON_ERROR_NONE || !isset($responseBody['choices'][0]['message']['content'])) {
            log_message('error', '[AiParserService] Respuesta inválida de DeepSeek: ' . $response->getBody());

            return [
                'status'  => false,
                'message' => 'El servicio de IA devolvió una respuesta inesperada.',
            ];
        }

        // Extraer el contenido del mensaje (debería ser un JSON string)
        $contentRaw = $responseBody['choices'][0]['message']['content'];

        // --------------------------------------------------------------------
        // LIMPIEZA DE LA RESPUESTA
        // --------------------------------------------------------------------
        // A veces la IA envuelve el JSON en bloques de código markdown
        // como ```json ... ```. Aquí limpiamos eso por si acaso.
        // --------------------------------------------------------------------

        $contentRaw = trim($contentRaw);

        // Eliminar bloques de código markdown ```json ... ``` si existen
        $contentRaw = preg_replace('/^```(?:json)?\s*/i', '', $contentRaw);
        $contentRaw = preg_replace('/\s*```$/', '', $contentRaw);
        $contentRaw = trim($contentRaw);

        // --------------------------------------------------------------------
        // DECODIFICAR EL JSON
        // --------------------------------------------------------------------

        $parsedData = json_decode($contentRaw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message('error', '[AiParserService] La IA no devolvió JSON válido. Content: ' . $contentRaw);

            return [
                'status'  => false,
                'message' => 'La IA no pudo interpretar el mensaje. Por favor, sé más específico.',
            ];
        }

        // --------------------------------------------------------------------
        // VALIDACIÓN DE CAMPOS REQUERIDOS
        // --------------------------------------------------------------------
        // Aseguramos que los campos esperados existan, aunque vengan vacíos.
        // Si falta algún campo clave, devolvemos error para evitar datos
        // incompletos aguas abajo.
        // --------------------------------------------------------------------

        $requiredFields = ['pickup_address', 'delivery_address', 'scheduled_time', 'notes'];

        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $parsedData)) {
                log_message('error', '[AiParserService] Falta el campo "' . $field . '" en la respuesta de la IA. Response: ' . $contentRaw);

                return [
                    'status'  => false,
                    'message' => 'La IA no devolvió todos los campos requeridos. Intenta reformular tu mensaje.',
                ];
            }
        }

        // --------------------------------------------------------------------
        // SANITIZACIÓN DE CAMPOS
        // --------------------------------------------------------------------
        // Nos aseguramos de que cada campo tenga el tipo correcto (string o null)
        // para evitar errores en el controlador o en el frontend.
        // --------------------------------------------------------------------

        $cleanData = [
            'pickup_address'   => is_string($parsedData['pickup_address']) ? trim($parsedData['pickup_address']) : '',
            'delivery_address' => is_string($parsedData['delivery_address']) ? trim($parsedData['delivery_address']) : '',
            'scheduled_time'   => is_string($parsedData['scheduled_time']) ? trim($parsedData['scheduled_time']) : null,
            'notes'            => is_string($parsedData['notes']) ? trim($parsedData['notes']) : '',
        ];

        // --------------------------------------------------------------------
        // VALIDACIÓN ADICIONAL: scheduled_time debe tener formato HH:MM:SS
        // o ser null. Si el valor no es válido, lo dejamos como null.
        // --------------------------------------------------------------------

        if ($cleanData['scheduled_time'] !== null) {
            // Validar formato de hora HH:MM:SS (24 horas)
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $cleanData['scheduled_time'])) {
                log_message('warning', '[AiParserService] scheduled_time con formato inválido: "' . $cleanData['scheduled_time'] . '". Se ignora.');
                $cleanData['scheduled_time'] = null;
            }
        }

        // --------------------------------------------------------------------
        // LOG DE ÉXITO
        // --------------------------------------------------------------------

        log_message('info', '[AiParserService] Mensaje parseado exitosamente', $cleanData);

        // --------------------------------------------------------------------
        // RESPUESTA EXITOSA
        // --------------------------------------------------------------------
        // Devolvemos los datos estructurados limpios.
        // --------------------------------------------------------------------

        return [
            'status'  => true,
            'message' => 'Mensaje interpretado correctamente.',
            'data'    => $cleanData,
        ];
    }
}
