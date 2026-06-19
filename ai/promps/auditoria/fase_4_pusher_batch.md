# SPEC — Fase 4: Batch de Eventos Pusher y Eliminación de Triggers en Transacción

**Origen**: Auditoría técnica 2026-06-18  
**Alcance**: `PusherService.php` (nuevo método) + `OrderService.php` (usar batch).  
**Riesgo**: Bajo. El SDK de Pusher ya soporta `triggerBatch()` nativamente.  
**Tiempo estimado**: 2 horas incluyendo prueba de publicación automática de órdenes.

---

## Contexto

### Problema 1 — trigger sincrónico en cada evento

**`app/Services/PusherService.php` línea 49:**

```php
self::client()->trigger($channel, $event, $data);
```

Esta llamada abre una conexión TCP a `api.pusher.com`, espera la respuesta HTTP 200 y
luego devuelve. El tiempo de ida y vuelta (RTT) a los servidores de Pusher en `us2`
desde México es típicamente 80–150ms.

Cada request al backend que dispara un evento Pusher queda bloqueado ese tiempo.

### Problema 2 — N triggers independientes en publishDueOrders

La fase 2 consolidó las transacciones de DB en un batch. Sin embargo, los triggers de
Pusher siguen siendo N llamadas HTTP individuales (una por orden publicada):

```php
// app/Services/OrderService.php — después de la corrección de Fase 2
foreach ($due as $order) {
    PusherService::trigger(       // ← 1 HTTP call por orden
        'trips.' . $order['client_id'],
        'new-trip',
        ['trip_id' => (int) $order['id']]
    );
}
```

Con 10 órdenes vencidas en el mismo minuto = 10 llamadas HTTP secuenciales a Pusher.

---

## Solución — triggerBatch en PusherService

El SDK de Pusher PHP soporta `triggerBatch()` que envía hasta **10 eventos** en una
sola llamada HTTP. Esto convierte N RTTs en 1 RTT (o ceil(N/10) RTTs).

### Tarea 4.1 — Añadir triggerBatch a PusherService

**Archivo**: `app/Services/PusherService.php`

```php
<?php

namespace App\Services;

use Pusher\Pusher;

class PusherService
{
    private static ?Pusher $client = null;

    private static function client(): Pusher
    {
        if (self::$client === null) {
            self::$client = new Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                [
                    'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                    'useTLS'  => true,
                ]
            );
        }
        return self::$client;
    }

    /**
     * Emite un evento en un canal público (un solo evento, una sola llamada HTTP).
     */
    public static function trigger(string $channel, string $event, array $data): void
    {
        try {
            self::client()->trigger($channel, $event, $data);
        } catch (\Throwable $e) {
            log_message('error', '[PusherService] trigger failed — channel=' . $channel . ' event=' . $event . ' err=' . $e->getMessage());
        }
    }

    /**
     * Emite múltiples eventos en una sola llamada HTTP a la API de Pusher.
     * Hasta 10 eventos por batch (límite de la API de Pusher).
     *
     * Formato de $events:
     *   [
     *     ['channel' => 'trips.42', 'name' => 'new-trip', 'data' => ['trip_id' => 7]],
     *     ['channel' => 'trips.42', 'name' => 'new-trip', 'data' => ['trip_id' => 8]],
     *   ]
     *
     * Si hay más de 10 eventos, se divide automáticamente en chunks de 10.
     */
    public static function triggerBatch(array $events): void
    {
        if (empty($events)) {
            return;
        }

        foreach (array_chunk($events, 10) as $chunk) {
            try {
                self::client()->triggerBatch($chunk);
            } catch (\Throwable $e) {
                log_message('error', '[PusherService] triggerBatch failed — count=' . count($chunk) . ' err=' . $e->getMessage());
            }
        }
    }
}
```

### Tarea 4.2 — Usar triggerBatch en publishDueOrders

**Archivo**: `app/Services/OrderService.php`

Reemplazar el loop de triggers individuales al final de `publishDueOrders()` con una
sola llamada batch:

```php
// Preparar todos los eventos ANTES de hacer ninguna llamada HTTP
$pusherEvents = [];
foreach ($due as $order) {
    log_message('info', "[OrderService] Orden #{$order['id']} publicada automáticamente (scheduled_at={$order['scheduled_at']})");
    $pusherEvents[] = [
        'channel' => 'trips.' . $order['client_id'],
        'name'    => 'new-trip',
        'data'    => ['trip_id' => (int) $order['id']],
    ];
}

// Una sola llamada HTTP para todos los eventos (o ceil(N/10) si N > 10)
PusherService::triggerBatch($pusherEvents);
```

---

## Nota sobre el trigger sincrónico en el caso general

Los triggers individuales (`PusherService::trigger()`) en el resto del sistema
(aceptar viaje, actualizar estado, cancelar orden, etc.) siguen siendo síncronos.
Eliminar completamente esa latencia requeriría una cola de trabajos (job queue)
con un worker en background, lo cual es infraestructura nueva fuera del alcance
de esta fase.

Lo que sí es importante y ya se hace desde la Fase 2 es que todos los triggers
estén **fuera de transacciones de DB**. Verificar que no hay ningún
`PusherService::trigger()` entre un `transStart()` y un `transComplete()`:

```bash
grep -n "transStart\|transComplete\|PusherService::trigger" \
  app/Services/OrderService.php \
  app/Controllers/Api/V1/OrderController.php \
  app/Controllers/Api/V1/Driver/DriverApiController.php
```

Si aparece un `trigger` entre un `transStart` y un `transComplete` en algún archivo
que no fue tocado en Fase 2, moverlo fuera de la transacción siguiendo el mismo
patrón: commit primero, trigger después.

---

## Archivos modificados

| Archivo | Tarea | Tipo de cambio |
|---|---|---|
| `app/Services/PusherService.php` | 4.1 | Añadir método `triggerBatch()` |
| `app/Services/OrderService.php` | 4.2 | Reemplazar loop de triggers con `triggerBatch()` |

---

## Impacto esperado

| Escenario | Antes | Después |
|---|---|---|
| 5 órdenes programadas vencen simultáneamente | 5 llamadas HTTP a Pusher (secuenciales) | 1 llamada HTTP (batch de 5) |
| 12 órdenes programadas vencen simultáneamente | 12 llamadas HTTP a Pusher | 2 llamadas HTTP (chunks de 10 + 2) |
| Evento individual (trip-taken, trip-updated) | 1 llamada HTTP (sin cambio) | 1 llamada HTTP (sin cambio) |

---

## Criterio de éxito

### Verificar que triggerBatch existe en el SDK instalado

```bash
php -r "
  require 'vendor/autoload.php';
  \$p = new Pusher\Pusher('k','s','i',['cluster'=>'mt1']);
  var_dump(method_exists(\$p, 'triggerBatch'));
"
```

Debe imprimir `bool(true)`. Si imprime `bool(false)`, el SDK instalado es anterior
a v5. Verificar versión con `composer show pusher/pusher-php-server`.

### Prueba de publicación batch

1. Crear 3 órdenes con `status = 'pendiente'` y `scheduled_at` en el pasado (hace 5 min).
2. Hacer `GET /api/v1/orders` para disparar `publishDueOrders()`.
3. En el panel web (o en la consola del navegador con Network abierto) verificar que
   llegan 3 eventos `new-trip` en el canal `trips.{clientId}`.
4. En los logs del servidor buscar el mensaje de `[OrderService] Orden #X publicada` —
   deben aparecer 3 mensajes y **no** debe haber ningún error de `triggerBatch`.

### Regresión

- Eventos individuales (`trip-taken`, `trip-updated`, `order-cancelled`) siguen llegando
  al panel sin cambios — no fueron modificados.
- El grep de "trigger dentro de transacción" no muestra ningún caso nuevo.
