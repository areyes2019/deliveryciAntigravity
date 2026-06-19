# SPEC — Fase 2: Optimización de Consultas y Migración de Índices

**Origen**: Auditoría técnica 2026-06-18  
**Alcance**: Tres archivos PHP de backend + dos nuevas migraciones.  
**Riesgo**: Bajo en código PHP. Medio en migraciones (requieren `php spark migrate` en el
servidor con la BD activa). Ejecutar en horario de bajo tráfico.  
**Tiempo estimado**: 3-4 horas incluyendo migraciones y pruebas.

---

## Contexto

La auditoría detectó cuatro problemas de rendimiento en el backend:

1. **Subquery correlacionada** en `GET /drivers` — ejecuta una subquery por cada conductor.
2. **`findAll()` sin filtro** en `GET /orders` para superadmin — crece con el historial.
3. **N transacciones individuales** en `publishDueOrders()` — una transacción por orden.
4. **`wallet_type` sin índice** — columna añadida en migración posterior sin crear índice.
5. **`DATE()` en WHERE de `getTodayStats()`** — función que impide uso de índices existentes.

---

## Tarea 2.1 — Reemplazar subquery correlacionada en DriverController

### Problema

**`app/Controllers/Api/V1/DriverController.php` líneas 28–31:**

```php
$guaranteeSubquery = "(SELECT COALESCE(SUM(amount), 0) FROM wallet_movements
                       WHERE driver_id = drivers.id
                       AND wallet_type = 'guarantee') as saldo_garantia";
```

Esta subquery corre una vez por cada fila de conductor que devuelve la query principal.
Con 20 conductores = 20 subqueries contra `wallet_movements` por cada llamada a `GET /drivers`.

El endpoint se llama:
- Al montar el dashboard (carga inicial).
- Cada 30 segundos por el polling de fallback.
- Tras cada evento Pusher `trip-taken` y `trip-updated` (actualmente — se elimina en Fase 1).

### Corrección — rama `client_admin` (líneas 33–43)

Reemplazar la variable `$guaranteeSubquery` y su uso con un LEFT JOIN de tabla derivada:

```php
if ($userData['role'] === 'client_admin') {
    $clientModel = new ClientModel();
    $client      = $clientModel->where('user_id', $userData['id'])->first();
    $billing     = $billingModel->getByClient($client['id']);

    $db = \Config\Database::connect();

    $drivers = $db->table('drivers')
        ->select('drivers.*, users.name, users.email, COALESCE(wm_agg.saldo_garantia, 0) AS saldo_garantia')
        ->join('users', 'users.id = drivers.user_id')
        ->join(
            "(SELECT driver_id, SUM(amount) AS saldo_garantia
              FROM wallet_movements
              WHERE wallet_type = 'guarantee'
              GROUP BY driver_id) wm_agg",
            'wm_agg.driver_id = drivers.id',
            'left'
        )
        ->where('drivers.client_id', $client['id'])
        ->get()
        ->getResultArray();

    $drivers = $this->enrichDrivers($drivers, $billing);
```

### Corrección — rama `superadmin` (líneas 44–57)

```php
} else {
    $allBillings = [];
    foreach ($billingModel->findAll() as $b) {
        $allBillings[$b['client_id']] = $b;
    }

    $db = \Config\Database::connect();

    $drivers = $db->table('drivers')
        ->select('drivers.*, users.name, users.email, clients.business_name, COALESCE(wm_agg.saldo_garantia, 0) AS saldo_garantia')
        ->join('users', 'users.id = drivers.user_id')
        ->join('clients', 'clients.id = drivers.client_id')
        ->join(
            "(SELECT driver_id, SUM(amount) AS saldo_garantia
              FROM wallet_movements
              WHERE wallet_type = 'guarantee'
              GROUP BY driver_id) wm_agg",
            'wm_agg.driver_id = drivers.id',
            'left'
        )
        ->get()
        ->getResultArray();

    $drivers = $this->enrichDrivers($drivers, null, $allBillings);
}
```

### Impacto esperado

| Antes | Después |
|---|---|
| 1 query principal + N subqueries (una por conductor) | 1 query con JOIN — MySQL hace la agregación una sola vez |
| Con 20 conductores: ~21 roundtrips internos a MySQL | 1 roundtrip a MySQL |

### Nota sobre el import

Al usar `\Config\Database::connect()` directamente ya no se necesita el `DriverModel`
para este método. Verificar que `DriverModel` sigue usándose en otros métodos del
controlador (`create`, `update`, `delete`, `goOffline`, `toggleAvailability`) antes
de eliminar el `use` del encabezado.

---

## Tarea 2.2 — Migración: índice compuesto en wallet_movements(driver_id, wallet_type)

### Diagnóstico previo

La columna `wallet_type` fue añadida en `20260414000002_AddWalletTypeToWalletMovements.php`
**sin crear ningún índice**. Los índices existentes en `wallet_movements` son:

| Índice | Columnas | Creado en |
|---|---|---|
| PRIMARY | `id` | `20240411000001_CreateWalletMovements.php` |
| FK implícita | `driver_id` | Ídem |
| Simple | `driver_id` | `$forge->addKey('driver_id')` — Ídem |
| Simple | `created_at` | `$forge->addKey('created_at')` — Ídem |
| `idx_wallet_driver_created` | `(driver_id, created_at)` | Ídem |
| `idx_wallet_reference` | `(reference_type, reference_id)` | Ídem |
| `uq_wallet_driver_type_reference` | `(driver_id, type, reference_type, reference_id)` | Ídem |
| **`wallet_type`** | **— ninguno —** | columna añadida sin índice |

### Queries que se benefician del nuevo índice

```sql
-- subquery JOIN en DriverController (tarea 2.1)
WHERE wallet_type = 'guarantee' GROUP BY driver_id

-- WalletMovementModel::getGuaranteeBalance()
WHERE driver_id = ? AND wallet_type = 'guarantee'

-- WalletMovementModel::getEarningsBalance()
WHERE driver_id = ? AND wallet_type = 'earnings'

-- WalletMovementModel::getTodayStats() (earnings query)
WHERE driver_id = ? AND type = 'ingreso' AND wallet_type = 'earnings' AND ...
```

### Migración a crear

Archivo: `app/Database/Migrations/20260619000001_AddIndexWalletMovementsWalletType.php`

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexWalletMovementsWalletType extends Migration
{
    public function up(): void
    {
        // Acelera: WHERE driver_id = ? AND wallet_type = ?
        // Usado en getGuaranteeBalance(), getEarningsBalance() y la subquery de saldo
        $this->db->query(
            'ALTER TABLE wallet_movements ADD INDEX idx_wm_driver_wallet_type (driver_id, wallet_type)'
        );
    }

    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE wallet_movements DROP INDEX idx_wm_driver_wallet_type'
        );
    }
}
```

Ejecutar después de crear el archivo:

```bash
php spark migrate
```

---

## Tarea 2.3 — Filtrar órdenes activas para superadmin en GET /orders

### Problema

**`app/Controllers/Api/V1/OrderController.php` línea 41:**

```php
if ($userData['role'] === 'superadmin') {
    $orders = $orderModel->findAll();   // ← TODA la tabla, sin filtro
```

Con el tiempo, la tabla `orders` acumula miles de registros en estado `entregado` y
`cancelado` que el dashboard no necesita para operar en tiempo real. Esta query
transfiere datos históricos innecesariamente en cada carga del panel del superadmin.

### Corrección

Aplicar el mismo filtro de estados activos que ya usa `client_admin`:

```php
if ($userData['role'] === 'superadmin') {
    $orders = $orderModel
        ->whereIn('status', ['pendiente', 'publicado', 'tomado', 'arribado', 'en_camino', 'arribado_a_entrega'])
        ->findAll();
```

> Si el superadmin necesita consultar el historial completo (órdenes entregadas,
> canceladas), ese acceso debe implementarse como un endpoint separado con paginación:
> `GET /api/v1/orders/history?page=1&per_page=50`. No en el mismo endpoint que alimenta
> el dashboard en tiempo real.

---

## Tarea 2.4 — Consolidar transacciones en publishDueOrders

### Problema

**`app/Services/OrderService.php` líneas 335–367:**

```php
public function publishDueOrders(): void
{
    // ...
    $due = $this->orderModel->where(...)->findAll();

    foreach ($due as $order) {
        $this->db->transStart();                              // transacción abierta
        $this->orderModel->update($order['id'], [...]);       // UPDATE individual
        $this->statusLogModel->insert([...]);                 // INSERT individual
        $this->db->transComplete();                           // transacción cerrada
        PusherService::trigger(...);                          // HTTP externo individual
    }
}
```

Con N órdenes vencidas simultáneas: N transacciones + N calls HTTP a Pusher, todos
secuenciales, dentro del request HTTP del primer usuario que acceda en ese minuto.

### Corrección

Una sola transacción batch para todos los UPDATEs e INSERTs. Los triggers de Pusher
se ejecutan después del commit, fuera de la transacción:

```php
public function publishDueOrders(): void
{
    $tz  = new \DateTimeZone('America/Mexico_City');
    $now = date_create('now', $tz)->format('Y-m-d H:i:s');

    $due = $this->orderModel
        ->where('status', 'pendiente')
        ->where('scheduled_at IS NOT NULL')
        ->where('scheduled_at <=', $now)
        ->findAll();

    if (empty($due)) {
        return;
    }

    // Una sola transacción para todos los cambios de estado
    $this->db->transStart();

    $ids = array_column($due, 'id');

    $this->db->table('orders')
        ->whereIn('id', $ids)
        ->update(['status' => 'publicado']);

    foreach ($due as $order) {
        $this->statusLogModel->insert([
            'order_id'        => $order['id'],
            'previous_status' => 'pendiente',
            'new_status'      => 'publicado',
        ]);
    }

    $this->db->transComplete();

    if ($this->db->transStatus() === false) {
        log_message('error', '[OrderService] publishDueOrders: batch transaction failed');
        return;
    }

    // Triggers de Pusher FUERA de la transacción — si Pusher falla,
    // los datos en DB ya están correctos y el polling de 30s sincronizará.
    foreach ($due as $order) {
        log_message('info', "[OrderService] Orden #{$order['id']} publicada automáticamente (scheduled_at={$order['scheduled_at']})");
        PusherService::trigger(
            'trips.' . $order['client_id'],
            'new-trip',
            ['trip_id' => (int) $order['id']]
        );
    }
}
```

### Impacto esperado

| Antes | Después |
|---|---|
| N transacciones MySQL abiertas y cerradas | 1 transacción + 1 UPDATE batch |
| N INSERTs individuales en `order_status_log` | N INSERTs dentro de una sola transacción |
| N llamadas HTTP a Pusher bloqueantes en el request | N llamadas a Pusher post-commit (aún bloqueantes, pero fuera de la transacción) |

---

## Tarea 2.5 — Corregir uso de DATE() en getTodayStats que impide uso de índices

### Problema

**`app/Models/WalletMovementModel.php` líneas 151–165:**

```php
// Query 1 — conteo de viajes del día
->where('DATE(osl.log_time)', $today)   // ← DATE() impide uso del índice en log_time

// Query 2 — ganancias del día
->where('DATE(created_at)', $today)     // ← DATE() impide uso del índice en created_at
```

Envolver una columna en una función en la cláusula WHERE hace que MySQL no pueda usar
el índice de esa columna, aunque exista. MySQL debe leer toda la tabla y aplicar `DATE()`
fila por fila.

### Corrección

Reemplazar `DATE(col) = ?` con un rango explícito `col >= ? AND col < ?`:

```php
public function getTodayStats(int $driverId): array
{
    $db       = \Config\Database::connect();
    $todayStart = date('Y-m-d') . ' 00:00:00';
    $todayEnd   = date('Y-m-d') . ' 23:59:59';

    // Query 1: viajes entregados hoy — usa índice en log_time
    $tripCount = (int) $db->table('order_status_log osl')
        ->join('orders o', 'o.id = osl.order_id')
        ->where('o.driver_id', $driverId)
        ->where('osl.new_status', 'entregado')
        ->where('osl.log_time >=', $todayStart)
        ->where('osl.log_time <=', $todayEnd)
        ->countAllResults();

    // Query 2: ganancias de hoy — usa índice en created_at
    $earningsRow = $this->builder()
        ->select('COALESCE(SUM(amount), 0) AS total', false)
        ->where('driver_id', $driverId)
        ->where('type', self::TYPE_INCOME)
        ->where('wallet_type', self::WALLET_EARNINGS)
        ->where('created_at >=', $todayStart)
        ->where('created_at <=', $todayEnd)
        ->get()
        ->getRowArray();

    return [
        'earnings' => round((float)($earningsRow['total'] ?? 0.0), 2),
        'trips'    => $tripCount,
    ];
}
```

Con este cambio, la query de `created_at` puede usar el índice `idx_wallet_driver_created
(driver_id, created_at)` que ya existe desde la migración original, y la query de
`log_time` puede usar el índice implícito de la FK (si log_time está indexado) o uno
que se añada.

### Migración complementaria — índice en order_status_log.log_time

La tabla `order_status_log` tiene FK en `order_id` (índice implícito por InnoDB) pero
no tiene índice explícito en `log_time`. La corrección anterior solo aprovecha índices
si este existe.

Archivo: `app/Database/Migrations/20260619000002_AddIndexOrderStatusLogLogTime.php`

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIndexOrderStatusLogLogTime extends Migration
{
    public function up(): void
    {
        // Acelera: WHERE log_time >= ? AND log_time <= ? en getTodayStats()
        $this->db->query(
            'ALTER TABLE order_status_log ADD INDEX idx_osl_log_time (log_time)'
        );
    }

    public function down(): void
    {
        $this->db->query(
            'ALTER TABLE order_status_log DROP INDEX idx_osl_log_time'
        );
    }
}
```

---

## Orden de ejecución dentro de la Fase 2

```
1. Tarea 2.2 — crear y ejecutar migración de índice en wallet_movements  ← primero
2. Tarea 2.5 — crear y ejecutar migración de índice en order_status_log  ← segundo
   (las migraciones no dependen de cambios en código PHP)
3. Tarea 2.4 — cambiar publishDueOrders a batch                          ← sin dependencias
4. Tarea 2.3 — filtrar superadmin en GET /orders                         ← sin dependencias
5. Tarea 2.1 — reemplazar subquery en DriverController                   ← después de 2.2
   (el nuevo JOIN se beneficia del índice creado en 2.2)
6. Tarea 2.5 código — corregir DATE() en getTodayStats                   ← después de 2.5 migración
```

---

## Archivos modificados en esta fase

| Archivo | Tarea | Tipo de cambio |
|---|---|---|
| `app/Controllers/Api/V1/DriverController.php` | 2.1 | Reemplazar subquery con JOIN derivado |
| `app/Controllers/Api/V1/OrderController.php` | 2.3 | Añadir whereIn en rama superadmin |
| `app/Services/OrderService.php` | 2.4 | Consolidar loop en batch transaction |
| `app/Models/WalletMovementModel.php` | 2.5 | Reemplazar DATE() con rango explícito |
| `app/Database/Migrations/20260619000001_AddIndexWalletMovementsWalletType.php` | 2.2 | Nueva migración |
| `app/Database/Migrations/20260619000002_AddIndexOrderStatusLogLogTime.php` | 2.5 | Nueva migración |

---

## Criterio de éxito

### Verificación de migraciones

```bash
php spark migrate
php spark migrate:status
```

El status debe mostrar ambas migraciones en estado `Up`.

### Verificación de índices en BD

```sql
SHOW INDEX FROM wallet_movements;
-- Debe aparecer: idx_wm_driver_wallet_type en columnas (driver_id, wallet_type)

SHOW INDEX FROM order_status_log;
-- Debe aparecer: idx_osl_log_time en columna log_time
```

### Verificación de GET /drivers (tarea 2.1)

Con el CI_DEBUG activo en un entorno de desarrollo, el debug toolbar muestra el número
de queries por request. Antes de la corrección el endpoint ejecuta N+1 queries.
Después debe ejecutar exactamente 1 query de conductores (más la de billing y la de
client lookup):

```sql
-- Debe aparecer UNA query como esta, no N subqueries:
SELECT drivers.*, users.name, users.email, COALESCE(wm_agg.saldo_garantia, 0) AS saldo_garantia
FROM drivers
LEFT JOIN users ON users.id = drivers.user_id
LEFT JOIN (SELECT driver_id, SUM(amount) AS saldo_garantia
           FROM wallet_movements WHERE wallet_type = 'guarantee' GROUP BY driver_id) wm_agg
       ON wm_agg.driver_id = drivers.id
WHERE drivers.client_id = ?
```

### Verificación de publishDueOrders (tarea 2.4)

Crear manualmente dos órdenes en estado `pendiente` con `scheduled_at` en el pasado.
Ejecutar `GET /orders` y verificar en logs que ambas órdenes se publicaron en la misma
transacción (un solo log de inicio y fin de transacción, no dos).

### Prueba de regresión

- `GET /drivers` sigue devolviendo el campo `saldo_garantia` con el valor correcto.
- `GET /orders` para `client_admin` sigue devolviendo solo órdenes activas.
- `GET /orders` para `superadmin` ya NO incluye órdenes en estado `entregado` o `cancelado`.
- Los stats de ganancias del día en la app de conductor siguen siendo correctos.
