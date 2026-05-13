<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $response = service('response');

        // Obtener la configuración CORS desde Config\Cors
        $corsConfig = config('Cors');
        $allowedOrigins = $corsConfig->default['allowedOrigins'] ?? ['*'];
        $allowedHeaders = $corsConfig->default['allowedHeaders'] ?? ['*'];
        $allowedMethods = $corsConfig->default['allowedMethods'] ?? ['GET', 'POST', 'OPTIONS', 'PUT', 'DELETE', 'PATCH'];
        $supportsCredentials = $corsConfig->default['supportsCredentials'] ?? false;

        // Determinar el origen dinámicamente
        $origin = $request->getHeaderLine('Origin');
        
        if (in_array('*', $allowedOrigins)) {
            $response->setHeader('Access-Control-Allow-Origin', '*');
        } elseif ($origin && in_array($origin, $allowedOrigins)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
            if ($supportsCredentials) {
                $response->setHeader('Access-Control-Allow-Credentials', 'true');
            }
        } elseif ($origin) {
            // Verificar patrones regex
            $matched = false;
            foreach (($corsConfig->default['allowedOriginsPatterns'] ?? []) as $pattern) {
                if (preg_match('/^' . $pattern . '$/', $origin)) {
                    $response->setHeader('Access-Control-Allow-Origin', $origin);
                    if ($supportsCredentials) {
                        $response->setHeader('Access-Control-Allow-Credentials', 'true');
                    }
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                // Origen no permitido, pero dejamos pasar para que el controlador maneje el error
                $response->setHeader('Access-Control-Allow-Origin', $allowedOrigins[0] ?? '*');
            }
        }

        $response->setHeader('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));
        $response->setHeader('Access-Control-Allow-Methods', implode(', ', $allowedMethods));
        $response->setHeader('Access-Control-Max-Age', '7200');

        // Manejar preflight OPTIONS
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response->setStatusCode(204);
            $response->setBody('');
            return $response;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Asegurar headers CORS también en la respuesta after
        $corsConfig = config('Cors');
        $allowedOrigins = $corsConfig->default['allowedOrigins'] ?? ['*'];
        $supportsCredentials = $corsConfig->default['supportsCredentials'] ?? false;

        $origin = $request->getHeaderLine('Origin');
        
        if (in_array('*', $allowedOrigins)) {
            $response->setHeader('Access-Control-Allow-Origin', '*');
        } elseif ($origin && in_array($origin, $allowedOrigins)) {
            $response->setHeader('Access-Control-Allow-Origin', $origin);
            if ($supportsCredentials) {
                $response->setHeader('Access-Control-Allow-Credentials', 'true');
            }
        } elseif ($origin) {
            foreach (($corsConfig->default['allowedOriginsPatterns'] ?? []) as $pattern) {
                if (preg_match('/^' . $pattern . '$/', $origin)) {
                    $response->setHeader('Access-Control-Allow-Origin', $origin);
                    if ($supportsCredentials) {
                        $response->setHeader('Access-Control-Allow-Credentials', 'true');
                    }
                    break;
                }
            }
        }

        $response->setHeader('Access-Control-Allow-Headers', implode(', ', $corsConfig->default['allowedHeaders'] ?? ['*']));
        $response->setHeader('Access-Control-Allow-Methods', implode(', ', $corsConfig->default['allowedMethods'] ?? ['*']));
        $response->setHeader('Access-Control-Expose-Headers', implode(', ', $corsConfig->default['exposedHeaders'] ?? []));
    }
}
