<?php

namespace App\Filters;

use App\Libraries\JwtLibrary;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class JwtFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $header = $request->getHeaderLine("Authorization");
        $token = null;

        if (!empty($header)) {
            if (preg_match('/Bearer\s(\S+)/', $header, $matches)) {
                $token = $matches[1];
            }
        }

        if (is_null($token) || empty($token)) {
            return \Config\Services::response()
                ->setJSON(['status' => false, 'message' => 'Access denied. Token missing.'])
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED);
        }

        $jwtLib = new JwtLibrary();
        $decoded = $jwtLib->validate($token);
        
        if (!$decoded) {
            return \Config\Services::response()
                ->setJSON(['status' => false, 'message' => 'Access denied. Invalid or expired token.'])
                ->setStatusCode(ResponseInterface::HTTP_UNAUTHORIZED);
        }

        if ($arguments && is_array($arguments)) {
            $userRole = $decoded['role'] ?? '';
            if (!in_array($userRole, $arguments)) {
                return \Config\Services::response()
                    ->setJSON(['status' => false, 'message' => 'Access denied. Insufficient privileges.'])
                    ->setStatusCode(ResponseInterface::HTTP_FORBIDDEN);
            }
        }

        // Attach to the request object so controllers can access it via $this->request->jwtPayload
        $request->jwtPayload = $decoded;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        //
    }
}
