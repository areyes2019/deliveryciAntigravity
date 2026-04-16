# Módulo: Delivery (Ejecución de Entregas)

## Problema que resuelve

Gestiona el lado operativo de las entregas: los conductores ven los viajes disponibles, los aceptan, actualizan su estado en cada etapa y envían su posición GPS para que el panel del cliente pueda hacer seguimiento en tiempo real.

## Funcionalidades principales

- Ver lista de viajes publicados disponibles para tomar.
- Aceptar un viaje (asignación exclusiva al conductor).
- Actualizar el estado del viaje en cada etapa (llegué, recogí, entregué).
- Enviar coordenadas GPS periódicamente al servidor.
- Consultar el viaje activo actual.
- Conectarse / desconectarse de la plataforma (disponibilidad).

## Entidades involucradas

| Entidad | Tabla | Rol |
|---|---|---|
| Order | `orders` | Viaje a ejecutar |
| Driver | `drivers` | Ejecutor — almacena `current_lat/lng` |
| OrderStatusLog | `order_status_log` | Auditoría de cada cambio de estado |
| WalletMovement | `wallet_movements` | Registro de ingreso al completar |

## Reglas de negocio

1. Un conductor solo puede ver viajes del cliente al que pertenece.
2. Solo se puede aceptar un viaje en estado `publicado`.
3. Un conductor suspendido (`users.is_suspended = 1`) no puede conectarse ni aceptar viajes.
4. Un conductor desconectado (`drivers.is_active = 0`) no aparece disponible para viajes.
5. Al aceptar un viaje, el estado cambia a `tomado` y se asigna `driver_id` a la orden.
6. Los estados solo avanzan en el orden definido — no se puede saltar de `tomado` a `entregado`.
7. Al marcar `entregado` o `rechazado`:
   - Se registra el ingreso en la billetera del conductor (`WalletService`).
   - Si el esquema es `credito`, se descuenta el costo del viaje de la garantía.
8. La posición GPS (`current_lat`, `current_lng`) se actualiza en la tabla `drivers` con cada llamada al endpoint de ubicación.

## Transiciones de estado válidas por el conductor

| Estado actual | Acción del conductor | Nuevo estado |
|---|---|---|
| `publicado` | Acepta el viaje | `tomado` |
| `tomado` | Llega al pickup | `arribado` |
| `arribado` | Recoge el paquete | `en_camino` |
| `en_camino` | Completa la entrega | `entregado` |
| `en_camino` | No puede entregar | `rechazado` |

## Casos de uso

### UC-01: Conductor acepta un viaje
1. Conductor abre la app y ve el listado de viajes disponibles.
2. Selecciona un viaje y presiona "Aceptar".
3. El sistema asigna el viaje al conductor y cambia el estado a `tomado`.
4. El mapa muestra la ruta hacia el punto de pickup.

### UC-02: Conductor actualiza el estado del viaje
1. Conductor llega al punto de pickup → presiona "Llegué".
2. Recoge el paquete → presiona "En camino".
3. Entrega el paquete → presiona "Entregado".
4. Cada acción actualiza el estado en la BD y registra en el log.

### UC-03: Envío de ubicación GPS
1. La app del conductor envía coordenadas cada N segundos.
2. El backend actualiza `current_lat` / `current_lng` en la tabla `drivers`.
3. El panel del cliente consulta las posiciones para mostrarlas en el mapa.

### UC-04: Conductor se desconecta
1. Conductor presiona "Desconectarse" o cierra sesión.
2. El sistema llama a `go-offline` (cambia `is_active = 0`).
3. El conductor deja de aparecer como disponible.

## Endpoints relacionados

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| GET | `/api/v1/driver/trips/available` | driver | Ver viajes disponibles |
| GET | `/api/v1/driver/trips/current` | driver | Ver viaje activo actual |
| POST | `/api/v1/driver/trips/:id/accept` | driver | Aceptar un viaje |
| POST | `/api/v1/driver/trips/:id/status` | driver | Actualizar estado del viaje |
| POST | `/api/v1/driver/location` | driver | Enviar posición GPS |
| POST | `/api/v1/driver/toggle-availability` | driver | Conectar / desconectar |
| POST | `/api/v1/driver/go-offline` | driver | Forzar desconexión |
