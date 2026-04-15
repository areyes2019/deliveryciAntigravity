<?php

namespace App\Filters;

use App\Libraries\JwtLibrary;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Filtro JWT — Autenticación y autorización por roles.
 *
 * Se aplica a las rutas protegidas de la API mediante la clave 'jwt' en Routes.php.
 * Ejemplo de uso en rutas:
 *   ['filter' => 'jwt']                          → solo valida que el token sea válido
 *   ['filter' => 'jwt:client_admin']              → token válido + rol client_admin
 *   ['filter' => 'jwt:superadmin,client_admin']   → token válido + cualquiera de esos roles
 *
 * Si el filtro pasa, inyecta el payload del token en $request->jwtPayload
 * para que los controllers lo lean con $this->request->jwtPayload.
 *
 * El payload contiene: id, uuid, email, role.
 */
class JwtFilter implements FilterInterface
{
    /**
     * Ejecutado antes de que la petición llegue al controller.
     *
     * Proceso en 3 pasos:
     *   1. Extrae el token del header Authorization: Bearer <token>
     *   2. Valida el token con JwtLibrary (firma + expiración)
     *   3. Si la ruta especificó roles, verifica que el rol del token esté permitido
     *
     * Respuestas posibles de error:
     *   401 Unauthorized → token ausente o inválido/expirado
     *   403 Forbidden    → token válido pero el rol no tiene permiso para esta ruta
     *
     * @param RequestInterface $request   Petición HTTP entrante
     * @param array|null       $arguments Roles permitidos declarados en la ruta (puede ser null)
     * @return ResponseInterface|void     Retorna respuesta de error o continúa al controller
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Paso 1: extraer el token del header Authorization
        $header = $request->getHeaderLine("Authorization");
        $token = null;

        if (!empty($header)) {
            // El formato esperado es: "Bearer eyJhbGciOi..."
            if (preg_match('/Bearer\s(\S+)/', $header, $matches)) {
                $token = $matches[1];
            }
        }

        // Si no se encontró token, rechazar con 401
        if (is_null($token) || empty($token)) {
            return \Config\Services::response()
                ->setJSON(['status' => false, 'message' => 'Access denied. Token missing.'])
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED);
        }

        // Paso 2: validar firma y expiración del token
        $jwtLib = new JwtLibrary();
        $decoded = $jwtLib->validate($token);

        // Si el token es inválido o expiró, rechazar con 401
        if (!$decoded) {
            return \Config\Services::response()
                ->setJSON(['status' => false, 'message' => 'Access denied. Invalid or expired token.'])
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED);
        }

        // Paso 3: verificar que el rol del usuario esté en la lista de roles permitidos
        // (solo si la ruta declaró restricción de roles)
        if ($arguments && is_array($arguments)) {
            $userRole = $decoded['role'] ?? '';
            if (!in_array($userRole, $arguments)) {
                return \Config\Services::response()
                    ->setJSON(['status' => false, 'message' => 'Access denied. Insufficient privileges.'])
                    ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN);
            }
        }

        // Token válido y rol autorizado: inyectar payload en el request
        // Los controllers lo leen con: $this->request->jwtPayload
        $request->jwtPayload = $decoded;
    }

    /**
     * Ejecutado después de que el controller genera la respuesta.
     * No se necesita lógica post-respuesta para JWT.
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        //
    }
}
