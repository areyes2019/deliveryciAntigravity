# Base de Datos — Esquema

## Diagrama de relaciones

```
users (1) ──────────── (1) clients
  │                          │
  │                          ├── (N) orders
  │                          │         │
  └── (1) drivers ───────────┘    (N) order_status_log
            │
            ├── (N) wallet_movements
            └── (through client) driver_billing_config

clients (1) ──── (N) pricing_zones
clients (1) ──── (N) zone_pricing_matrix
clients (1) ──── (1) driver_billing_config
clients (1) ──── (N) credit_transactions
```

---

## Tablas

### `users`

Tabla central de identidad. Todos los actores del sistema tienen un registro aquí.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | ID interno |
| `uuid` | VARCHAR(36) UNIQUE | ID público seguro (para exponer en API) |
| `name` | VARCHAR(100) | Nombre completo |
| `email` | VARCHAR(150) UNIQUE | Email de login |
| `password` | VARCHAR(255) | Hash bcrypt |
| `role` | ENUM | `superadmin`, `client_admin`, `driver` |
| `is_suspended` | TINYINT(1) | 1 = cuenta bloqueada por admin |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |
| `deleted_at` | DATETIME | Soft delete |

---

### `clients`

Perfil extendido de cada empresa cliente. Uno por cada usuario `client_admin`.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `uuid` | VARCHAR(36) UNIQUE | |
| `user_id` | INT FK → users.id | Admin de la empresa |
| `business_name` | VARCHAR(150) | Nombre comercial |
| `credits_balance` | INT | Saldo de créditos disponibles |
| `cost_per_trip` | DECIMAL(10,2) | Créditos que cuesta cada viaje |
| `pricing_mode` | VARCHAR(50) | `distance` o `zone` |
| `base_fare` | DECIMAL(10,2) | Tarifa base (modo distance) |
| `price_per_km` | DECIMAL(10,2) | Precio por km (modo distance) |
| `min_distance_km` | DECIMAL(8,2) | Distancia mínima cobrable |
| `sms_enabled` | TINYINT(1) | 1 = enviar SMS al destinatario |

---

### `drivers`

Perfil del conductor. Uno por cada usuario `driver`.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `uuid` | VARCHAR(36) UNIQUE | |
| `user_id` | INT FK → users.id | |
| `client_id` | INT FK → clients.id | Empresa a la que pertenece |
| `phone` | VARCHAR(20) | Teléfono de contacto |
| `vehicle_details` | TEXT | Descripción del vehículo |
| `is_active` | TINYINT(1) | 1 = conectado y disponible |
| `current_lat` | DECIMAL(10,8) | Última latitud GPS conocida |
| `current_lng` | DECIMAL(11,8) | Última longitud GPS conocida |

---

### `orders`

Entidad central. Cada entrega del sistema.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `uuid` | VARCHAR(36) UNIQUE | |
| `client_id` | INT FK → clients.id | |
| `driver_id` | INT FK → drivers.id (nullable) | Asignado al aceptar |
| `pickup_lat/lng` | DECIMAL | Coordenadas de recogida |
| `pickup_address` | VARCHAR(255) | Dirección de recogida |
| `drop_lat/lng` | DECIMAL | Coordenadas de entrega |
| `drop_address` | VARCHAR(255) | Dirección de entrega |
| `receiver_name` | VARCHAR(255) | Nombre del destinatario |
| `receiver_phone` | VARCHAR(20) | Teléfono del destinatario |
| `description` | TEXT | Descripción del paquete |
| `status` | ENUM | Ver ciclo de vida en modules/orders.md |
| `payment_type` | ENUM | `prepaid`, `cash_on_delivery`, `cash_full` |
| `cost` | DECIMAL(10,2) | Costo calculado del servicio |
| `distance_km` | DECIMAL(8,2) | Distancia del viaje |
| `product_amount` | DECIMAL(10,2) | Valor del producto (cash_full) |
| `total_to_collect` | DECIMAL(10,2) | Total que cobra el conductor al destinatario |
| `paid` | TINYINT(1) | 1 = liquidado |

---

### `order_status_log`

Auditoría inmutable de cada cambio de estado de una orden.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `order_id` | INT FK → orders.id | |
| `previous_status` | VARCHAR | Estado anterior (null en primera inserción) |
| `new_status` | VARCHAR | Nuevo estado |
| `log_time` | DATETIME | Timestamp exacto del cambio |

---

### `pricing_zones`

Zonas geográficas con precio. Solo aplica cuando `clients.pricing_mode = 'zone'`.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `client_id` | INT FK → clients.id | |
| `name` | VARCHAR(100) | Ej: "Centro", "Norte", "Periférico" |
| `polygon_coordinates` | JSON | Array de puntos `[{lat, lng}, ...]` |
| `base_price` | DECIMAL(10,2) | Precio base cuando el origen está en esta zona |
| `increment_price` | DECIMAL(10,2) | Precio adicional al cruzar hacia esta zona |

---

### `zone_pricing_matrix`

Precio específico para cada combinación origen→destino de zonas.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `client_id` | INT FK → clients.id | |
| `origin_zone_id` | INT FK → pricing_zones.id | |
| `destination_zone_id` | INT FK → pricing_zones.id | |
| `price` | DECIMAL(10,2) | Precio configurado (null = usar precio automático) |

Índice único: `(client_id, origin_zone_id, destination_zone_id)`

---

### `driver_billing_config`

Esquema de cobro al conductor. Uno por cliente.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `client_id` | INT FK → clients.id | |
| `tipo_esquema` | ENUM | `credito` o `porcentaje` |
| `precio_credito` | DECIMAL(10,2) | Costo por viaje en esquema crédito |
| `porcentaje_comision` | DECIMAL(5,2) | % que retiene el sistema en esquema porcentaje |

---

### `wallet_movements`

Todos los movimientos económicos de la billetera del conductor.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `driver_id` | INT FK → drivers.id | |
| `type` | VARCHAR | `ingreso`, `retiro`, `ajuste`, `comision` |
| `wallet_type` | VARCHAR | `earnings` o `guarantee` |
| `amount` | DECIMAL(10,2) | Monto (positivo o negativo) |
| `reference_id` | INT (nullable) | ID de la entidad origen (ej. orden) |
| `reference_type` | VARCHAR | Tipo de entidad origen (ej. `viaje`) |
| `description` | TEXT | Descripción libre |

---

### `credit_transactions`

Historial de movimientos del saldo de créditos del cliente.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `client_id` | INT FK → clients.id | |
| `order_id` | INT (nullable) | Orden asociada |
| `amount` | DECIMAL(10,2) | Monto del movimiento |
| `transaction_type` | VARCHAR | `recharge`, `deduction`, `refund` |
| `description` | TEXT | Motivo |

## Notas de diseño

- **Soft delete solo en `users`**: preserva integridad del histórico de órdenes cuando se elimina un usuario.
- **UUIDs en todas las tablas principales**: permiten exponer IDs públicos sin revelar la secuencia interna.
- **`driver_id` nullable en orders**: la orden existe sin conductor hasta que alguien la tome.
- **`wallet_movements` como ledger**: el saldo del conductor no se guarda — siempre se calcula con `SUM(amount)`.
- **`zone_pricing_matrix` con INSERT IGNORE**: los precios manuales se preservan al reconstruir la matriz.
