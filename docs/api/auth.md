# API: Autenticación

Base URL: `/api/v1`

Todos los endpoints protegidos requieren el header:
```
Authorization: Bearer <token>
```

---

## POST `/auth/login`

Autentica al usuario y retorna un JWT.

**Roles permitidos:** Público (sin autenticación)

### Request

```json
{
  "email": "admin@empresa.com",
  "password": "miPassword123"
}
```

### Response — 200 OK

```json
{
  "status": true,
  "message": "Login successful",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": {
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "name": "Juan Pérez",
      "email": "admin@empresa.com",
      "role": "client_admin",
      "is_suspended": 0,
      "client": {
        "id": 3,
        "business_name": "Empresa XYZ",
        "credits_balance": 150,
        "cost_per_trip": 10,
        "pricing_mode": "distance"
      },
      "client_balance": 150,
      "cost_per_trip": 10
    }
  },
  "errors": []
}
```

> Para rol `driver`, el objeto `user` incluye `driver: { ... }` en lugar de `client`.
> Para rol `superadmin`, no incluye perfil adicional.

### Response — 401 Unauthorized

```json
{
  "status": false,
  "message": "Invalid email or password",
  "data": [],
  "errors": []
}
```

### Response — 403 Forbidden (cuenta suspendida)

```json
{
  "status": false,
  "message": "Your account has been suspended. Please contact support.",
  "data": [],
  "errors": []
}
```

### Validaciones
| Campo | Regla |
|---|---|
| `email` | Requerido, formato email válido |
| `password` | Requerido |

---

## GET `/auth/me`

Retorna los datos actualizados del usuario autenticado.

**Roles permitidos:** `superadmin`, `client_admin`, `driver`

### Response — 200 OK

```json
{
  "status": true,
  "message": "User details",
  "data": {
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "name": "Juan Pérez",
    "email": "admin@empresa.com",
    "role": "client_admin",
    "is_suspended": 0,
    "client": {
      "id": 3,
      "business_name": "Empresa XYZ",
      "credits_balance": 140,
      "cost_per_trip": 10,
      "pricing_mode": "distance",
      "base_fare": 20.00,
      "price_per_km": 5.00,
      "min_distance_km": 2.0,
      "sms_enabled": 1
    }
  },
  "errors": []
}
```

### Errores posibles

| Código | Mensaje | Causa |
|---|---|---|
| 401 | Access denied. Token missing. | No se envió el header Authorization |
| 401 | Access denied. Invalid or expired token. | Token inválido o expirado |
| 404 | User not found | El usuario del token fue eliminado |

---

## Notas sobre el JWT

- El token se firma con `encryption.key` del archivo `.env`.
- La expiración se configura con `jwt.expirationHours` (default: 720h = 30 días).
- El payload contiene: `{ id, uuid, email, role }`.
- Al recibir un 401 en cualquier endpoint, el frontend hace logout automático.
