# API: Delivery (App del Conductor)

Base URL: `/api/v1/driver`

Todos los endpoints requieren rol `driver`.

---

## GET `/driver/trips/available`

Lista los viajes publicados disponibles para tomar (del cliente del conductor).

### Response — 200 OK

```json
{
  "status": true,
  "message": "Available trips",
  "data": [
    {
      "id": 43,
      "uuid": "b2c3d4e5-f6a7-8901-bcde-f12345678901",
      "pickup_lat": "20.52219",
      "pickup_lng": "-100.81220",
      "pickup_address": "Av. Adolfo López Mateos 100, Celaya, GTO",
      "drop_lat": "20.53100",
      "drop_lng": "-100.80500",
      "drop_address": "Calle Hidalgo 45, Celaya, GTO",
      "receiver_name": "María García",
      "receiver_phone": "4611234567",
      "description": "Paquete frágil",
      "status": "publicado",
      "payment_type": "cash_on_delivery",
      "cost": "45.00",
      "distance_km": "3.20",
      "total_to_collect": "45.00",
      "created_at": "2026-04-15 11:00:00"
    }
  ],
  "errors": []
}
```

---

## GET `/driver/trips/current`

Retorna el viaje activo del conductor (estado `tomado`, `arribado` o `en_camino`).

### Response — 200 OK (con viaje activo)

```json
{
  "status": true,
  "message": "Current trip",
  "data": {
    "id": 43,
    "status": "tomado",
    "pickup_address": "Av. Adolfo López Mateos 100, Celaya, GTO",
    "drop_address": "Calle Hidalgo 45, Celaya, GTO",
    "receiver_name": "María García",
    "receiver_phone": "4611234567",
    "total_to_collect": "45.00",
    "payment_type": "cash_on_delivery"
  },
  "errors": []
}
```

### Response — 200 OK (sin viaje activo)

```json
{
  "status": true,
  "message": "No active trip",
  "data": null,
  "errors": []
}
```

---

## POST `/driver/trips/:id/accept`

Acepta un viaje disponible. Cambia el estado a `tomado` y asigna el conductor.

### Response — 200 OK

```json
{
  "status": true,
  "message": "Trip accepted successfully",
  "data": {
    "id": 43,
    "status": "tomado",
    "driver_id": 7
  },
  "errors": []
}
```

### Errores posibles

| Código | Mensaje | Causa |
|---|---|---|
| 400 | Trip not available | La orden ya fue tomada por otro conductor |
| 404 | Trip not found | ID inválido |

---

## POST `/driver/trips/:id/status`

Actualiza el estado del viaje al siguiente paso.

### Request

```json
{
  "status": "arribado"
}
```

Valores válidos según el estado actual:

| Estado actual | Valores permitidos |
|---|---|
| `tomado` | `arribado` |
| `arribado` | `en_camino` |
| `en_camino` | `entregado`, `rechazado` |

### Response — 200 OK

```json
{
  "status": true,
  "message": "Status updated",
  "data": {
    "id": 43,
    "status": "en_camino"
  },
  "errors": []
}
```

> Al marcar `entregado` o `rechazado`, el sistema registra automáticamente el ingreso
> en la billetera del conductor y descuenta la garantía si aplica.

---

## POST `/driver/location`

Actualiza la posición GPS del conductor en tiempo real.

### Request

```json
{
  "lat": 20.52450,
  "lng": -100.81100
}
```

### Response — 200 OK

```json
{
  "status": true,
  "message": "Location updated",
  "data": [],
  "errors": []
}
```

---

## POST `/driver/toggle-availability`

Alterna el estado de conexión del conductor (online/offline).

### Response — 200 OK (conectándose)

```json
{
  "status": true,
  "message": "Te has conectado exitosamente.",
  "data": {
    "is_active": 1,
    "status": "online"
  },
  "errors": []
}
```

### Response — 200 OK (desconectándose)

```json
{
  "status": true,
  "message": "Te has desconectado exitosamente.",
  "data": {
    "is_active": 0,
    "status": "offline"
  },
  "errors": []
}
```

### Response — 403 (conductor suspendido)

```json
{
  "status": false,
  "message": "Your account has been suspended by an administrator. Please contact support.",
  "data": [],
  "errors": []
}
```

---

## POST `/driver/go-offline`

Fuerza la desconexión del conductor. Se llama automáticamente al hacer logout.

### Response — 200 OK

```json
{
  "status": true,
  "message": "Te has desconectado exitosamente.",
  "data": {
    "is_active": 0,
    "status": "offline"
  },
  "errors": []
}
```
