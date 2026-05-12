<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Services\AiParserService;
use App\Traits\ApiResponseTrait;

/**
 * Controlador de Parseo con IA
 *
 * Endpoint público (sin JWT por ahora) que recibe mensajes en lenguaje natural
 * desde WhatsApp y los convierte en datos estructurados de envío.
 *
 * Este controlador NO contiene lógica de negocio. Su única responsabilidad es:
 *   1. Recibir la petición HTTP.
 *   2. Validar el formato de entrada.
 *   3. Delegar el procesamiento al Service (AiParserService).
 *   4. Devolver la respuesta HTTP correspondiente.
 *
 * Ubicación: app/Controllers/Api/V1/AiParserController.php
 *
 * Ruta: POST /api/ia/procesar-mensaje
 *
 * @package App\Controllers\Api\V1
 */
class AiParserController extends BaseController
{
    use ApiResponseTrait;

    /**
     * Instancia del servicio de parseo con IA.
     *
     * @var AiParserService
     */
    private AiParserService $aiParserService;

    /**
     * Constructor
     *
     * Inicializa el servicio de IA. Se usa el helper service() de CI4 para
     * una instancia limpia.
     */
    public function __construct()
    {
        $this->aiParserService = service('AiParserService');
    }

    /**
     * Procesa un mensaje de WhatsApp y devuelve datos estructurados de envío.
     *
     * Endpoint: POST /api/ia/procesar-mensaje
     *
     * Request Body (JSON):
     * {
     *   "mensaje": "Buenas tardes, necesito un envio. Recojo en Av Benito Juarez 123 Col Centro Celaya. Entrego en Calle Hidalgo 456 Col San Miguel. A nombre de María García. Tel 461-123-45-67. Es un pastel de cumpleaños."
     * }
     *
     * Respuesta Exitosa (200):
     * {
     *   "status": true,
     *   "message": "Mensaje interpretado correctamente.",
     *   "data": {
     *     "pickup_address": "Av Benito Juarez 123, Col Centro, Celaya",
     *     "delivery_address": "Calle Hidalgo 456, Col San Miguel",
     *     "receiver_name": "María García",
     *     "receiver_phone": "4611234567",
     *     "reference_code": "",
     *     "description": "Pastel de cumpleaños",
     *     "scheduled_time": null,
     *     "notes": ""
     *   }
     * }
     *
     * Respuesta de Error (400):
     * {
     *   "status": false,
     *   "message": "El mensaje no puede estar vacío.",
     *   "errors": []
     * }
     *
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    public function procesarMensaje()
    {
        // --------------------------------------------------------------------
        // 1. OBTENER EL INPUT
        // --------------------------------------------------------------------
        // Intentamos obtener el cuerpo de la petición como JSON.
        // getJSON(true) devuelve un array asociativo.
        // Si no hay JSON válido, getJSON devuelve null.
        // --------------------------------------------------------------------

        $input = $this->request->getJSON(true);

        // Si no se pudo parsear el JSON, intentamos con getPost() como fallback
        // (útil para pruebas con form-data o x-www-form-urlencoded)
        if (empty($input)) {
            $input = $this->request->getPost();
        }

        // Si sigue vacío, devolvemos error
        if (empty($input)) {
            return $this->respondError(
                'El cuerpo de la petición es inválido o está vacío. Envía un JSON con el campo "mensaje".',
                [],
                400
            );
        }

        // --------------------------------------------------------------------
        // 2. EXTRAER EL MENSAJE
        // --------------------------------------------------------------------
        // El campo esperado es "mensaje". Si no existe, devolvemos un error
        // claro para que el desarrollador sepa exactamente qué falta.
        // --------------------------------------------------------------------

        $mensaje = $input['mensaje'] ?? '';

        if (empty(trim($mensaje))) {
            return $this->respondError(
                'El campo "mensaje" es requerido y no puede estar vacío.',
                [],
                400
            );
        }

        // --------------------------------------------------------------------
        // 3. EXTRAER CONTEXTO CONVERSACIONAL (OPCIONAL)
        // --------------------------------------------------------------------
        // Si el frontend envía contexto (historial de mensajes anteriores)
        // y datos_actuales (datos ya extraídos previamente), los pasamos
        // al servicio para que la IA pueda actualizar incrementalmente.
        // --------------------------------------------------------------------

        $contexto = $input['contexto'] ?? [];
        $datosActuales = $input['datos_actuales'] ?? null;

        // --------------------------------------------------------------------
        // 4. DELEGAR AL SERVICE
        // --------------------------------------------------------------------
        // El controlador NO debe contener lógica de negocio. Solo llamamos
        // al Service y devolvemos lo que él nos dé.
        // --------------------------------------------------------------------

        $result = $this->aiParserService->parse($mensaje, $contexto, $datosActuales);

        // --------------------------------------------------------------------
        // 4. DEVOLVER RESPUESTA
        // --------------------------------------------------------------------
        // El Service devuelve un array estandarizado con 'status', 'message'
        // y opcionalmente 'data'. Mapeamos esa respuesta a nuestra API.
        // --------------------------------------------------------------------

        if ($result['status'] === false) {
            // Error: devolvemos el mensaje de error del Service
            return $this->respondError(
                $result['message'],
                [],
                400
            );
        }

        // Éxito: devolvemos los datos parseados
        return $this->respondSuccess(
            $result['message'],
            $result['data'] ?? [],
            200
        );
    }
}
