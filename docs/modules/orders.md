# Módulo: Orders (Órdenes)

## 🎯 Problema que resuelve

Permite a un `client_admin` crear solicitudes de entrega, calcular su costo automáticamente y publicarlas para que los conductores disponibles las tomen. Centraliza el ciclo de vida completo de cada entrega.

---

## ⚙️ Funcionalidades principales

- Crear una orden con puntos de pickup y drop (coordenadas + dirección).
- Calcular el costo del viaje automáticamente al crear (PricingService).
- Publicar la orden para que conductores disponibles la vean.
- Cancelar una orden publicada y recibir reembolso de créditos.
- Listar órdenes filtrando por cliente (client_admin) o todas (superadmin).
- Registrar auditoría de cada cambio de estado.

---

## 🧩 Entidades involucradas

| Entidad | Tabla | Rol |
|---|---|---|
| Order | `orders` | Entidad principal |
| Client | `clients` | Propietario de la orden |
| Driver | `drivers` | Ejecutor asignado |
| OrderStatusLog | `order_status_log` | Auditoría de cambios |
| CreditTransaction | `credit_transactions` | Descuento/reembolso |

---

## 🗄️ Campos principales (orders)

- id: uuid
- client_id: uuid
- driver_id: uuid (nullable)
- pickup_address: string
- pickup_lat: decimal
- pickup_lng: decimal
- drop_address: string
- drop_lat: decimal
- drop_lng: decimal
- distance_km: decimal
- cost: decimal
- product_amount: decimal (nullable)
- payment_type: enum (prepaid, cash_on_delivery, cash_full)
- total_to_collect: decimal
- status: enum (pendiente, publicado, tomado, arribado, en_camino, entregado, rechazado, cancelado)
- created_at: datetime
- updated_at: datetime

---

## 🔄 Ciclo de vida de una orden

[client_admin crea]

↓

pendiente ──→ publicado ──→ tomado ──→ arribado ──→ en_camino ──→ entregado

↓                                               ↗

cancelado                                    rechazado

| Estado | Quién lo asigna | Descripción |
|---|---|---|
| pendiente | Sistema | Estado inicial |
| publicado | OrderService | Visible para conductores |
| tomado | Driver | Conductor acepta |
| arribado | Driver | Llega a pickup |
| en_camino | Driver | En tránsito |
| entregado | Driver | Entrega finalizada |
| rechazado | Driver | No pudo completar |
| cancelado | client_admin / superadmin | Cancelación |

---

## ⚡ Eventos del sistema

- create_order → calcula costo + descuenta créditos + status = publicado
- driver_accept_order → status = tomado
- driver_arrive → status = arribado
- driver_start → status = en_camino
- driver_complete → status = entregado
- cancel_order → status = cancelado + reembolso

---

## 💳 Tipos de pago

| Tipo | Descripción | total_to_collect |
|---|---|---|
| prepaid | Cliente paga con créditos | 0 |
| cash_on_delivery | Destinatario paga servicio | = cost |
| cash_full | Servicio + producto | = cost + product_amount |

---

## 📜 Reglas de negocio

1. Solo se puede crear una orden si el cliente tiene `credits_balance >= cost`.
2. Al crear una orden:
   - Se calcula costo (PricingService)
   - Se descuenta crédito (CreditService)
   - Se crea la orden en estado `publicado`
3. Solo órdenes en estado `publicado` pueden cancelarse.
4. Al cancelar:
   - Se devuelven créditos automáticamente
   - Se registra el evento
5. Una vez en estado `tomado`, no puede cancelarse desde panel.
6. Cada cambio de estado se registra en `order_status_log`.
7. Si frontend envía `distance_km`, usarlo. Si no, calcular con Haversine.

---

## 🧠 Responsabilidades

### Backend (CI4)
- Validar datos
- Calcular costo
- Manejar estados
- Aplicar reglas de negocio
- Registrar auditoría

### Frontend (Vue 3)
- Selección de direcciones (Google Places)
- Mostrar costo estimado
- Manejo de UI/UX

---

## 🎯 Casos de uso

### UC-01: Cliente crea una orden
1. Selecciona direcciones (Google Places).
2. Sistema calcula costo en tiempo real.
3. Ingresa datos del destinatario.
4. Confirma creación.
5. Sistema descuenta créditos y publica.

---

### UC-02: Cliente cancela orden
1. Selecciona orden en estado `publicado`.
2. Confirma cancelación.
3. Sistema devuelve créditos.

---

### UC-03: Superadmin consulta órdenes
1. Accede al panel.
2. Ve todas las órdenes.

---

## 🔌 Endpoints

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| GET | /api/v1/orders | client_admin, superadmin | Listar |
| GET | /api/v1/orders/:id | client_admin, superadmin | Ver detalle |
| POST | /api/v1/orders | client_admin | Crear |
| PUT | /api/v1/orders/:id/cancel | client_admin, superadmin | Cancelar |