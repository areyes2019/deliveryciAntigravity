# Módulo: Payments (Pagos y Cobros)

## Problema que resuelve

Gestiona dos flujos de dinero independientes:
1. **Créditos del cliente**: el cliente prepaga créditos y cada orden los consume.
2. **Billetera del conductor**: el conductor acumula ganancias y paga por usar la plataforma.

## Funcionalidades principales

- Sistema de créditos prepago para clientes.
- Recarga manual de créditos por el superadmin.
- Descuento automático de créditos al crear una orden.
- Reembolso automático al cancelar una orden.
- Billetera del conductor con dos bolsillos: garantía y ganancias.
- Registro de ingresos al conductor por viaje completado.
- Descuento de garantía por viaje (esquema crédito).
- Retiro / liquidación de ganancias del conductor.
- Historial de movimientos con trazabilidad completa.

## Entidades involucradas

| Entidad | Tabla | Rol |
|---|---|---|
| Client | `clients` | Tiene `credits_balance` |
| CreditTransaction | `credit_transactions` | Historial de créditos del cliente |
| WalletMovement | `wallet_movements` | Movimientos de la billetera del conductor |
| DriverBillingConfig | `driver_billing_config` | Esquema de cobro al conductor |

## Créditos del cliente

### Concepto
El cliente compra créditos por adelantado. Cada orden creada consume `cost_per_trip` créditos del saldo. Si cancela, los créditos se devuelven.

### Tipos de transacción (`credit_transactions.transaction_type`)
| Tipo | Cuándo ocurre | Efecto en saldo |
|---|---|---|
| `recharge` | Superadmin asigna créditos | + (aumenta) |
| `deduction` | Se crea una orden | - (disminuye) |
| `refund` | Se cancela una orden | + (aumenta) |

### Regla de saldo
```
Saldo mínimo requerido para crear una orden = client.cost_per_trip
Si credits_balance < cost_per_trip → la orden no se crea
```

## Billetera del conductor

### Dos bolsillos independientes

| Bolsillo (`wallet_type`) | Descripción |
|---|---|
| `guarantee` | Saldo de garantía precargado por el admin. Se descuenta con cada viaje en esquema `credito`. |
| `earnings` | Ganancias del conductor por viajes completados. Se acumula en esquema `porcentaje`. |

### Tipos de movimiento (`wallet_movements.type`)
| Tipo | Descripción | Signo |
|---|---|---|
| `ingreso` | Ganancia por viaje completado | Positivo |
| `retiro` | Liquidación/retiro del conductor | Negativo |
| `ajuste` | Corrección manual del admin | Positivo o negativo |
| `comision` | Descuento de crédito de garantía por viaje | Negativo |

## Esquemas de cobro al conductor

### Esquema `credito`
- El conductor tiene un saldo de garantía precargado.
- Cada viaje que acepta consume `precio_credito` de su garantía.
- Si la garantía llega a 0, el conductor no puede aceptar más viajes.
- El panel muestra `viajes_disponibles = floor(garantia / precio_credito)`.

### Esquema `porcentaje`
- El conductor no paga por adelantado.
- Al completar un viaje, el sistema retiene `porcentaje_comision`% del valor.
- El resto se deposita en `earnings`.
- `viajes_disponibles` no aplica → el panel muestra `—`.

## Reglas de negocio

1. Los movimientos de billetera son inmutables — solo se insertan, nunca se modifican.
2. El ingreso por viaje es idempotente: si ya existe un `ingreso` para ese `trip_id`, no se duplica.
3. Los ingresos (`ingreso`) siempre son positivos. Los retiros (`retiro`) siempre son negativos.
4. El saldo total de un conductor = `SUM(amount)` en `wallet_movements`.
5. El saldo de garantía = `SUM(amount) WHERE wallet_type = 'guarantee'`.
6. El saldo de ganancias = `SUM(amount) WHERE wallet_type = 'earnings'`.

## Casos de uso

### UC-01: Superadmin recarga créditos a un cliente
1. Superadmin selecciona el cliente y el monto.
2. El sistema suma el monto a `credits_balance` y registra en `credit_transactions`.

### UC-02: Orden consume créditos
1. Al crear una orden, `CreditService.deductCredit()` descuenta `cost_per_trip`.
2. Registra la transacción como `deduction`.

### UC-03: Admin recarga garantía a un conductor
1. `client_admin` ingresa el monto en la billetera del conductor.
2. `WalletService.addGuaranteeRecharge()` registra en `wallet_movements` con `wallet_type = guarantee`.

### UC-04: Conductor completa un viaje y recibe ingreso
1. Al marcar el viaje como `entregado`, `DriverApiController` llama a `WalletService.addIncomeFromTrip()`.
2. Si el esquema es `credito`, también llama a `WalletService.deductGuaranteeForTrip()`.

### UC-05: Admin liquida al conductor
1. `client_admin` registra el retiro desde el panel de billetera.
2. `WalletService.addWithdrawal()` registra en `wallet_movements` como `retiro` (monto negativo).

## Endpoints relacionados

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| POST | `/api/v1/clients/:id/add-credits` | superadmin | Recargar créditos al cliente |
| GET | `/api/v1/wallet/balance/:driverId` | superadmin, driver | Saldo del conductor |
| GET | `/api/v1/wallet/movements/:driverId` | superadmin, driver | Historial de movimientos |
| GET | `/api/v1/wallet/today/:driverId` | superadmin, driver | Estadísticas del día |
| POST | `/api/v1/wallet/recharge` | client_admin | Recargar garantía del conductor |
| POST | `/api/v1/wallet/withdraw` | superadmin | Registrar retiro/liquidación |
| POST | `/api/v1/wallet/add-income` | superadmin | Registrar ingreso manual |
