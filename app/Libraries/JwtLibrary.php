<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtLibrary
{
    private string $secretKey;
    private int $expirationHours;

    public function __construct()
    {
        $this->secretKey = getenv('encryption.key') ?: 'delivery_ci4_super_secret_key_1234567890';
        $this->expirationHours = 24; // Validate according to environment
    }

    /**
     * Generate a new JWT token.
     */
    public function generate(array $payload): string
    {
        $issuedAt   = time();
        $expire     = $issuedAt + ($this->expirationHours * 3600);
        
        $tokenPayload = [
            'iat'  => $issuedAt,
            'exp'  => $expire,
            'data' => $payload
        ];

        return JWT::encode($tokenPayload, $this->secretKey, 'HS256');
    }

    /**
     * Validate and decode a JWT token.
     * Returns the payload data if valid, null otherwise.
     */
    public function validate(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
            return (array) $decoded->data;
        } catch (\Exception $e) {
            return null; // Invalid token, expired, or signature failed
        }
    }
}
