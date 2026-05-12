<?php

namespace App\Services;

use Config\DeepSeek;

/**
 * Servicio de Parseo con IA
 *
 * Esta clase orquesta la comunicación con la API de DeepSeek para interpretar
 * mensajes de WhatsApp escritos en lenguaje natural por los clientes y
 * convertirlos en datos estructurados de envío.
 *
 * Extrae los siguientes campos del mensaje:
 *   - pickup_address:   Dirección de origen/recogida
 *   - delivery_address: Dirección de destino/entrega
 *   - receiver_name:    Nombre del destinatario (quien recibe)
 *   - receiver_phone:   Teléfono del destinatario (normalizado, solo dígitos)
 *   - scheduled_time:   Hora programada (HH:MM:SS) o null
 *   - notes:            Información adicional
 *
 * Flujo:
 *   1. Recibe un mensaje de texto plano (ej. mensaje de WhatsApp).
 *   2. Construye un prompt especializado que le indica a la IA que debe
 *      responder ÚNICAMENTE con JSON válido.
 *   3. Envía el prompt a la API de DeepSeek (Chat Completions).
 *   4. Recibe la respuesta, la parsea y valida.
 *   5. Normaliza el teléfono (solo dígitos).
 *   6. Devuelve un array limpio con los campos estructurados.
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
     * @param string $mensaje      Texto crudo del mensaje de WhatsApp del cliente.
     * @param array  $contexto     Historial de mensajes anteriores [{role, content}]
     * @param array|null $datosActuales Datos ya extraídos previamente (para merge incremental)
     *
     * @return array{
     *   status: bool,
     *   message: string,
     *   data?: array{
     *     pickup_address: string,
     *     delivery_address: string,
     *     receiver_name: string|null,
     *     receiver_phone: string|null,
     *     reference_code: string,
     *     description: string,
     *     scheduled_time: string|null,
     *     notes: string,
     *     payment_type: string,
     *     product_amount: float|null
     *   }
     * }
     */
    public function parse(string $mensaje, array $contexto = [], ?array $datosActuales = null): array
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
Eres un asistente especializado en logística y delivery para México.
Tu ÚNICA función es extraer datos estructurados de mensajes de WhatsApp de clientes que solicitan envíos.
Los mensajes pueden ser informales, con abreviaturas, errores ortográficos y sin formato.

REGLAS ESTRICTAS:
1. Responde ÚNICAMENTE con JSON válido. NADA más. No uses markdown, no expliques nada.
2. El JSON debe tener EXACTAMENTE esta estructura, sin campos adicionales:
   {
     "pickup_address": "string",
     "delivery_address": "string",
     "receiver_name": "string|null",
     "receiver_phone": "string|null",
     "reference_code": "string",
     "description": "string",
     "scheduled_time": "string|null",
     "notes": "string",
     "payment_type": "string",
     "product_amount": "number|null"
   }

DEFINICIÓN DE CAMPOS:
- pickup_address:   Dirección de origen / recogida. Si el cliente dice "de mi ubicacion" o "aqui", usa "Ubicación actual del cliente". Si no hay dirección de recogida clara, devuelve "".
- delivery_address: Dirección de destino / entrega. Obligatorio.
- receiver_name:    Nombre completo de la persona que RECIBE el paquete (destinatario). Si no se menciona, devuelve null.
- receiver_phone:   Teléfono del destinatario. SOLO DÍGITOS. Sin espacios, guiones, paréntesis ni prefijos. Ej. "4611234567". Si viene con LADA (ej. 52 461 123 4567), quita el 52. Si no hay teléfono, devuelve null.
- reference_code:   Código de referencia, número de guía, ID de pedido, o número de confirmación. Si no hay, devuelve cadena vacía "".
- description:      Descripción del paquete o producto (qué se envía: pizza, documento, ropa, comida, etc.). Si no hay, devuelve cadena vacía "".
- scheduled_time:   Hora programada en formato TIME (HH:MM:SS). Si dice "a las 5:00", devuelve "17:00:00". Si dice "ahorita", "urgente", "lo más pronto posible", devuelve null. Si no menciona hora, devuelve null.
- notes:            Info adicional útil (referencias de domicilio, instrucciones de entrega, intercomunicador, color de portón, etc.). Si no hay, devuelve "".
- payment_type:     Método de pago detectado. Valores posibles: "prepaid", "cash_on_delivery", "cash_full". Reglas de detección abajo.
- product_amount:   Monto del producto en pesos mexicanos (número). Solo aplica cuando payment_type es "cash_full". Si no se menciona monto, devuelve null.

DETECCIÓN DE DATOS DEL DESTINATARIO (MUY IMPORTANTE):
- El destinatario es la persona que RECIBE, no el remitente.
- Indicadores comunes: "a nombre de", "para", "entregar a", "recibe", "destinatario", "se entrega a".
- El nombre puede incluir apellidos. Extráelo completo.
- El teléfono mexicano típico tiene 10 dígitos. Puede venir con formato como "461-123-45-67", "(461) 123 4567", "4611234567", "52 461 123 4567". Normaliza quitando todo excepto dígitos. Si el número empieza con 52 y tiene más de 10 dígitos después, quita el 52.

DETECCIÓN DE MÉTODO DE PAGO (MUY IMPORTANTE):
Interpreta el mensaje como lo haría un despachador humano experimentado.

REGLAS PARA payment_type:

1. cash_full (Receptor paga producto + envío):
   Si el mensaje menciona un MONTO o COBRO específico, usa cash_full.
   Frases clave en lenguaje informal mexicano:
   - "con cobro de [monto]"
   - "cobra [monto]"
   - "cóbrale [monto]"
   - "se pagan [monto]"
   - "son [monto]"
   - "el cliente paga [monto]"
   - "cobrar [monto] al entregar"
   - "van [monto] de producto"
   - "llevar [algo] y cobrar [monto]"
   - "paga [monto]"
   - "cobro de [monto]"
   - "[monto] de producto"
   - "vale [monto]"
   - "cuesta [monto]"
   - "precio de [monto]"
   - "me deben [monto]"
   - "pagar [monto]"
   - "entrega contra pago de [monto]"
   - "contra entrega [monto]"
   - "cobrar al entregar [monto]"
   - "al cobro [monto]"
   - "con pago de [monto]"
   - "lleva cobro de [monto]"
   - "se cobra [monto]"
   - "recibir [monto]"
   - "junto con [monto]"
   - "más [monto]"
   - "adicional [monto]"
   - "producto de [monto]"
   - "mercancia de [monto]"
   - "articulo de [monto]"
   - "pedido de [monto]"
   - "total [monto]"
   - "importe [monto]"
   - "cobrar [monto]"
   - "paga [monto] al recibir"
   - "entrega con cobro de [monto]"
   - "lleva [monto] de producto"
   - "son [monto] de producto"
   - "van [monto]"
   - "llevar [algo] y cobrar [monto]"

   Cuando detectes cash_full, EXTRÁE el monto numérico y asígnalo a product_amount.
   El monto puede venir como "300", "$300", "300 pesos", "300.00", "300 mxn".
   Normaliza a número (ej. 300). Si hay múltiples montos, usa el que parezca ser del producto.

2. cash_on_delivery (Receptor paga solo el envío):
   Si el mensaje menciona que el receptor paga el envío pero NO menciona un monto específico de producto.
   Frases clave:
   - "el receptor paga el envío"
   - "el cliente paga el envío"
   - "contra entrega" (sin monto)
   - "COD"
   - "paga al recibir" (sin monto)
   - "paga el destinatario"
   - "quien recibe paga"
   - "pago contra entrega"
   - "contra reembolso"
   - "al recibir paga"
   - "entrega con cobro" (sin monto específico)
   - "cobrar al entregar" (sin monto específico)
   - "paga hasta que llegue"
   - "ahí se paga"
   - "alla pagan"
   - "paga el que recibe"
   - "el que recibe paga el envio"
   - "paga la persona que recibe"

3. prepaid (Remitente paga el envío - VALOR POR DEFECTO):
   Si el mensaje NO menciona cobros, pagos, montos, ni contra entrega.
   Es el valor por defecto cuando no hay información de pago.

IMPORTANTE:
- NO confundir el precio del producto con el precio del envío.
  El envío SIEMPRE se calcula aparte por el sistema de pricing.
- product_amount es SOLO el valor del producto, NO el envío.
- Si el cliente dice "cobrar 300", significa que el producto vale 300.
- Si el cliente dice "el cliente paga el envío" sin monto, es cash_on_delivery con product_amount = null.
- Si no hay información de pago, asume prepaid.

EJEMPLOS REALES DE DELIVERY MEXICANO:
Cliente: "Buenas tardes, necesito un envio. Recojo en Av Benito Juarez 123 Col Centro Celaya. Entrego en Calle Hidalgo 456 Col San Miguel. A nombre de María García. Tel 461-123-45-67. Es un pastel de cumpleaños."
Respuesta: {"pickup_address": "Av Benito Juarez 123, Col Centro, Celaya", "delivery_address": "Calle Hidalgo 456, Col San Miguel", "receiver_name": "María García", "receiver_phone": "4611234567", "reference_code": "", "description": "Pastel de cumpleaños", "scheduled_time": null, "notes": "", "payment_type": "prepaid", "product_amount": null}

Cliente: "Manda una pizza de la pizzeria de la esquina a mi casa, son 2 pizzas, que las reciba Juan Perez, tel 4619876543"
Respuesta: {"pickup_address": "Pizzería de la esquina", "delivery_address": "Domicilio del cliente", "receiver_name": "Juan Pérez", "receiver_phone": "4619876543", "reference_code": "", "description": "2 pizzas", "scheduled_time": null, "notes": "", "payment_type": "prepaid", "product_amount": null}

Cliente: "Recoge en Av Reforma 222 Col Centro, entregas en Insurgentes Sur 333 Col Del Valle, a nombre de Laura Martinez, telefono 5544332211, codigo de confirmacion ORD-12345, entregar antes de las 6pm, porton negro"
Respuesta: {"pickup_address": "Av Reforma 222, Col Centro", "delivery_address": "Insurgentes Sur 333, Col Del Valle", "receiver_name": "Laura Martinez", "receiver_phone": "5544332211", "reference_code": "ORD-12345", "description": "", "scheduled_time": "18:00:00", "notes": "Portón negro", "payment_type": "prepaid", "product_amount": null}

Cliente: "Ocupo un envio urgente, vengo saliendo de mi casa en Las Flores 45, llevarlo a Cerrada del Sol 12, es un sobre con documentos"
Respuesta: {"pickup_address": "Las Flores 45", "delivery_address": "Cerrada del Sol 12", "receiver_name": null, "receiver_phone": null, "reference_code": "", "description": "Sobre con documentos", "scheduled_time": null, "notes": "Urgente", "payment_type": "prepaid", "product_amount": null}

Cliente: "Buen dia, paso por un paquete a Av Independencia 789, entregar a las 4:30 en Blvd Adolfo Lopez Mateos 101, con Roberto Hernandez, cel 4615551234, pedido #9876, es ropa"
Respuesta: {"pickup_address": "Av Independencia 789", "delivery_address": "Blvd Adolfo Lopez Mateos 101", "receiver_name": "Roberto Hernandez", "receiver_phone": "4615551234", "reference_code": "#9876", "description": "Ropa", "scheduled_time": "16:30:00", "notes": "", "payment_type": "prepaid", "product_amount": null}

Cliente: "llevar unas pizzas y cobrar 280"
Respuesta: {"pickup_address": "", "delivery_address": "", "receiver_name": null, "receiver_phone": null, "reference_code": "", "description": "Pizzas", "scheduled_time": null, "notes": "", "payment_type": "cash_full", "product_amount": 280}

Cliente: "el cliente paga el envio"
Respuesta: {"pickup_address": "", "delivery_address": "", "receiver_name": null, "receiver_phone": null, "reference_code": "", "description": "", "scheduled_time": null, "notes": "", "payment_type": "cash_on_delivery", "product_amount": null}

Cliente: "manda un paquete de lago 56 a hidalgo 94"
Respuesta: {"pickup_address": "Lago 56", "delivery_address": "Hidalgo 94", "receiver_name": null, "receiver_phone": null, "reference_code": "", "description": "Paquete", "scheduled_time": null, "notes": "", "payment_type": "prepaid", "product_amount": null}

Cliente: "con cobro de 300, llevar una pizza de la juarez a la colonia san miguel, recibe juan perez 4611234567"
Respuesta: {"pickup_address": "Juarez", "delivery_address": "Colonia San Miguel", "receiver_name": "Juan Perez", "receiver_phone": "4611234567", "reference_code": "", "description": "Pizza", "scheduled_time": null, "notes": "", "payment_type": "cash_full", "product_amount": 300}

Cliente: "cobra 450 al entregar, es un pastel, recoge en av reforma 123, entrega en hidalgo 456, con maria"
Respuesta: {"pickup_address": "Av Reforma 123", "delivery_address": "Hidalgo 456", "receiver_name": "Maria", "receiver_phone": null, "reference_code": "", "description": "Pastel", "scheduled_time": null, "notes": "", "payment_type": "cash_full", "product_amount": 450}

Cliente: "contra entrega, lleva un sobre de la oficina a la casa de roberto, tel 4611112233"
Respuesta: {"pickup_address": "Oficina", "delivery_address": "Casa de Roberto", "receiver_name": "Roberto", "receiver_phone": "4611112233", "reference_code": "", "description": "Sobre", "scheduled_time": null, "notes": "", "payment_type": "cash_on_delivery", "product_amount": null}

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
        //
        // Si hay contexto conversacional (historial de mensajes anteriores),
        // lo insertamos entre system y user para que la IA tenga memoria
        // de la conversación y pueda actualizar datos incrementalmente.
        // --------------------------------------------------------------------

        $messages = [
            [
                'role'    => 'system',
                'content' => $systemPrompt,
            ],
        ];

        // Si hay contexto conversacional, insertar el historial
        if (!empty($contexto)) {
            foreach ($contexto as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = [
                        'role'    => $msg['role'],
                        'content' => $msg['content'],
                    ];
                }
            }
        }

        // Si hay datos actuales (para merge incremental), agregarlos como contexto
        if (!empty($datosActuales)) {
            $messages[] = [
                'role'    => 'system',
                'content' => 'Datos actuales del viaje (para referencia, actualiza solo si el nuevo mensaje proporciona información diferente): ' . json_encode($datosActuales, JSON_UNESCAPED_UNICODE),
            ];
        }

        // Agregar el mensaje actual del usuario
        $messages[] = [
            'role'    => 'user',
            'content' => $mensaje,
        ];

        $payload = [
            'model'       => $this->deepSeekConfig->model,
            'messages'    => $messages,
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
        // Solo pickup_address y delivery_address son estrictamente obligatorios.
        // Los campos receiver_name, receiver_phone, reference_code, description,
        // scheduled_time y notes pueden ser null o vacío.
        // --------------------------------------------------------------------

        $requiredFields = ['pickup_address', 'delivery_address'];

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
        // y normalizamos el teléfono (solo dígitos, quitar LADA 52 si aplica).
        // --------------------------------------------------------------------

        $cleanData = [
            'pickup_address'   => is_string($parsedData['pickup_address'] ?? '') ? trim($parsedData['pickup_address']) : '',
            'delivery_address' => is_string($parsedData['delivery_address'] ?? '') ? trim($parsedData['delivery_address']) : '',
            'receiver_name'    => is_string($parsedData['receiver_name'] ?? null) && trim($parsedData['receiver_name']) !== '' ? trim($parsedData['receiver_name']) : null,
            'receiver_phone'   => is_string($parsedData['receiver_phone'] ?? null) && trim($parsedData['receiver_phone']) !== '' ? trim($parsedData['receiver_phone']) : null,
            'reference_code'   => is_string($parsedData['reference_code'] ?? '') ? trim($parsedData['reference_code']) : '',
            'description'      => is_string($parsedData['description'] ?? '') ? trim($parsedData['description']) : '',
            'scheduled_time'   => is_string($parsedData['scheduled_time'] ?? null) ? trim($parsedData['scheduled_time']) : null,
            'notes'            => is_string($parsedData['notes'] ?? '') ? trim($parsedData['notes']) : '',
            'payment_type'     => $this->sanitizePaymentType($parsedData['payment_type'] ?? 'prepaid'),
            'product_amount'   => $this->sanitizeProductAmount($parsedData['product_amount'] ?? null, $parsedData['payment_type'] ?? 'prepaid'),
        ];

        // --------------------------------------------------------------------
        // NORMALIZACIÓN DE TELÉFONO
        // --------------------------------------------------------------------
        // Limpiamos el teléfono: solo dígitos, quitamos espacios, guiones,
        // paréntesis, y el prefijo 52 si el número tiene más de 10 dígitos.
        //
        // Ejemplos de entrada → salida:
        //   "461-123-45-67"       → "4611234567"
        //   "(461) 123 4567"      → "4611234567"
        //   "4611234567"          → "4611234567"
        //   "52 461 123 4567"     → "4611234567"
        //   "+52 461 123 4567"    → "4611234567"
        //   "55 1234 5678"        → "5512345678"
        //   ""                    → null
        // --------------------------------------------------------------------

        if ($cleanData['receiver_phone'] !== null) {
            // Eliminar todo excepto dígitos
            $phoneDigits = preg_replace('/[^0-9]/', '', $cleanData['receiver_phone']);

            // Si hay más de 10 dígitos y empieza con 52 (LADA México), quitarlo
            if (strlen($phoneDigits) > 10 && str_starts_with($phoneDigits, '52')) {
                $phoneDigits = substr($phoneDigits, 2);
            }

            // Si después de la limpieza tenemos exactamente 10 dígitos, usarlo
            if (strlen($phoneDigits) >= 10) {
                // Tomar los últimos 10 dígitos por si hay prefijos adicionales
                $cleanData['receiver_phone'] = substr($phoneDigits, -10);
            } elseif (strlen($phoneDigits) > 0) {
                // Si tiene menos de 10 pero más de 0, lo dejamos como está
                // (podría ser un número interno o extensión)
                $cleanData['receiver_phone'] = $phoneDigits;
            } else {
                // Si después de limpiar no queda nada, null
                $cleanData['receiver_phone'] = null;
            }
        }

        // --------------------------------------------------------------------
        // VALIDACIÓN ADICIONAL: scheduled_time debe tener formato HH:MM:SS
        // o ser null. Si el valor no es válido, lo dejamos como null.
        // --------------------------------------------------------------------

        if ($cleanData['scheduled_time'] !== null) {
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

    /**
     * Sanitiza el campo payment_type.
     *
     * Asegura que solo devuelva valores válidos: "prepaid", "cash_on_delivery" o "cash_full".
     * Si el valor no es reconocido, devuelve "prepaid" como valor por defecto.
     *
     * @param mixed $value Valor crudo del campo payment_type.
     *
     * @return string "prepaid" | "cash_on_delivery" | "cash_full"
     */
    private function sanitizePaymentType(mixed $value): string
    {
        $validTypes = ['prepaid', 'cash_on_delivery', 'cash_full'];

        if (is_string($value) && in_array($value, $validTypes, true)) {
            return $value;
        }

        // Valor por defecto seguro
        return 'prepaid';
    }

    /**
     * Sanitiza el campo product_amount.
     *
     * Solo devuelve un valor numérico si payment_type es "cash_full".
     * Para cualquier otro payment_type, devuelve null.
     * Si el valor no es numérico, devuelve null.
     *
     * @param mixed  $value       Valor crudo del campo product_amount.
     * @param string $paymentType El payment_type ya sanitizado.
     *
     * @return float|null
     */
    private function sanitizeProductAmount(mixed $value, string $paymentType): ?float
    {
        // Solo aplica para cash_full
        if ($paymentType !== 'cash_full') {
            return null;
        }

        // Si no hay valor, devolver null
        if ($value === null || $value === '' || $value === 0) {
            return null;
        }

        // Intentar convertir a número
        if (is_numeric($value)) {
            $amount = (float) $value;
            // No permitir montos negativos
            return $amount > 0 ? $amount : null;
        }

        // Si es string, intentar extraer número
        if (is_string($value)) {
            // Eliminar símbolos de moneda, comas, espacios
            $cleaned = preg_replace('/[^0-9.]/', '', $value);
            if (is_numeric($cleaned)) {
                $amount = (float) $cleaned;
                return $amount > 0 ? $amount : null;
            }
        }

        return null;
    }
}
