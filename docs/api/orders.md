# API: Orders (Órdenes)

Base URL: `/api/v1`

---

## Reglas de negocio — Conductores

> **Un conductor en estado `offline` no puede ver ni aceptar envíos.**
>
> Los endpoints `GET /driver/trips/available` y `POST /driver/trips/:id/accept` verifican `is_active = 1` antes de operar. Si el conductor está offline, ambos endpoints retornan `403 Forbidden`.

### Ciclo de vida de un envío

```
publicado → tomado → arribado → en_camino → entregado
```

| Transición | Quién la ejecuta | Endpoint |
|---|---|---|
| `publicado` → `tomado` | Conductor acepta el viaje | `POST /driver/trips/:id/accept` |
| `tomado` → `arribado` | Conductor llega al origen | `POST /driver/trips/:id/status` |
| `arribado` → `en_camino` | Conductor recoge el paquete | `POST /driver/trips/:id/status` |
| `en_camino` → `entregado` | Conductor entrega | `POST /driver/trips/:id/status` |

---

## GET `/orders`

Lista órdenes. El `superadmin` ve todas; el `client_admin` solo ve las de su empresa.

**Roles permitidos:** `superadmin`, `client_admin`

### Response — 200 OK

```json
{
  "status": true,
  "message": "Orders retrieved successfully",
  "data": [
    {
      "id": 42,
      "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "client_id": 3,
      "driver_id": 7,
      "pickup_lat": "20.52219",
      "pickup_lng": "-100.81220",
      "pickup_address": "Av. Adolfo López Mateos 100, Celaya, GTO",
      "drop_lat": "20.53100",
      "drop_lng": "-100.80500",
      "drop_address": "Calle Hidalgo 45, Celaya, GTO",
      "receiver_name": "María García",
      "receiver_phone": "4611234567",
      "description": "Paquete frágil",
      "status": "en_camino",
      "payment_type": "prepaid",
      "cost": "45.00",
      "distance_km": "3.20",
      "product_amount": null,
      "total_to_collect": "0.00",
      "paid": 0,
      "created_at": "2026-04-15 10:30:00",
      "updated_at": "2026-04-15 10:45:00"
    }
  ],
  "errors": []
}
```

### Errores posibles

| Código | Mensaje | Causa |
|---|---|---|
| 400 | Client profile not found | El usuario `client_admin` no tiene perfil de cliente asociado |
| 401 | Access denied. Insufficient privileges. | Rol no autorizado |

---

## POST `/orders`

Crea una nueva orden. Descuenta créditos del cliente automáticamente.

**Roles permitidos:** `client_admin`

**Content-Type aceptado:** `application/json` o `multipart/form-data`

### Request

```json
{
  "pickup_lat": 20.52219,
  "pickup_lng": -100.81220,
  "pickup_address": "Av. Adolfo López Mateos 100, Celaya, GTO",
  "drop_lat": 20.53100,
  "drop_lng": -100.80500,
  "drop_address": "Calle Hidalgo 45, Celaya, GTO",
  "receiver_name": "María García",
  "receiver_phone": "4611234567",
  "description": "Paquete frágil",
  "payment_type": "prepaid",
  "distance_km": 3.2
}
```

> Para `payment_type = "cash_full"`, agregar:
> ```json
> { "product_amount": 250.00 }
> ```

### Response — 201 Created

```json
{
  "status": true,
  "message": "Order created successfully",
  "data": {
    "id": 43,
    "uuid": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
    "client_id": 3,
    "driver_id": null,
    "status": "publicado",
    "payment_type": "prepaid",
    "cost": "45.00",
    "distance_km": "3.20",
    "total_to_collect": "0.00",
    "created_at": "2026-04-15 11:00:00"
  },
  "errors": []
}
```

### Response — 400 (saldo insuficiente)

```json
{
  "status": false,
  "message": "Saldo insuficiente. Este viaje requiere 10 créditos. Tu saldo actual es de 5 créditos (aprox. 0 viajes).",
  "data": [],
  "errors": []
}
```

### Validaciones

| Campo | Regla |
|---|---|
| `pickup_lat` | Requerido, decimal |
| `pickup_lng` | Requerido, decimal |
| `pickup_address` | Requerido |
| `drop_lat` | Requerido, decimal |
| `drop_lng` | Requerido, decimal |
| `drop_address` | Requerido |
| `receiver_name` | Requerido, 3–255 caracteres |
| `receiver_phone` | Requerido, 7–20 caracteres |
| `payment_type` | Requerido, valores: `prepaid`, `cash_on_delivery`, `cash_full` |
| `product_amount` | Requerido si `payment_type = cash_full`, decimal > 0 |
| `distance_km` | Opcional, decimal > 0 |

---

## PUT `/orders/:id/cancel`

Cancela una orden en estado `publicado` y reembolsa los créditos.

**Roles permitidos:** `client_admin`, `superadmin`

### Response — 200 OK

```json
{
  "status": true,
  "message": "Order cancelled successfully",
  "data": [],
  "errors": []
}
```

### Errores posibles

| Código | Mensaje | Causa |
|---|---|---|
| 400 | Only published orders can be cancelled | La orden no está en estado `publicado` |
| 400 | Unauthorized to cancel this order | El cliente no es dueño de la orden |
| 404 | Order not found | ID inválido |
| 401 | Access denied. Insufficient privileges. | Rol no autorizado |

---

## Notificaciones SMS automáticas (Twilio)

Si el cliente tiene `sms_enabled = 1`, se envía un SMS al `receiver_phone` de la orden en los siguientes eventos:

| Evento | Estatus | Mensaje enviado |
|---|---|---|
| Conductor asignado | `publicado` → `tomado` | "Hola {receiver_name}, tu conductor {driver_name} ya fue asignado y va en camino a recoger tu pedido 🚗" |
| Pedido en camino | `arribado` → `en_camino` | "Tu pedido está en camino 🚗 El conductor {driver_name} se dirige al destino." |
| Pedido entregado | `en_camino` → `entregado` | "Tu pedido ha sido entregado. ¡Gracias por usar el servicio! 🙌" |

> Los estatus `tomado` y `arribado` **no disparan SMS**.

**Requisitos para que el SMS se envíe:**
- `TWILIO_SID`, `TWILIO_TOKEN` y `TWILIO_MESSAGING_SID` configurados en `.env`
- Campo `sms_enabled = 1` en la tabla `clients` para esa empresa
- `receiver_phone` con 10 dígitos mexicanos (se convierte a `+52XXXXXXXXXX` internamente)
