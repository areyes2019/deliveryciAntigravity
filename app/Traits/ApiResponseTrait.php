<?php

namespace App\Traits;

trait ApiResponseTrait
{
    /**
     * Format a standard API response.
     *
     * @param bool   $status
     * @param string $message
     * @param array  $data
     * @param array  $errors
     * @param int    $statusCode
     * @return \CodeIgniter\HTTP\ResponseInterface
     */
    protected function respondStandard(bool $status, string $message = '', array $data = [], array $errors = [], int $statusCode = 200)
    {
        return $this->response->setStatusCode($statusCode)->setJSON([
            'status'  => $status,
            'message' => $message,
            'data'    => $data,
            'errors'  => $errors
        ]);
    }

    protected function respondSuccess(string $message = 'OK', array $data = [], int $statusCode = 200)
    {
        return $this->respondStandard(true, $message, $data, [], $statusCode);
    }

    protected function respondError(string $message = 'Error', array $errors = [], int $statusCode = 400)
    {
        return $this->respondStandard(false, $message, [], $errors, $statusCode);
    }

    protected function respondUnauthorized(string $message = 'Unauthorized')
    {
        return $this->respondStandard(false, $message, [], [], 401);
    }
}
