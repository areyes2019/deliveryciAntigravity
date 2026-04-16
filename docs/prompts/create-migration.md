# Prompt: Crear migración en CI4

Usa este prompt cuando necesites generar una migración nueva para el sistema.

---

## Prompt base

```
Eres un desarrollador senior de CodeIgniter 4. Necesito crear una migración para el sistema de delivery.

TIPO DE MIGRACIÓN: [crear tabla / agregar columna / eliminar columna / modificar columna / crear índice]

CONTEXTO:
- La migración debe seguir el naming: YYYYMMDDHHMMSS_DescripciónClara.php
- Namespace: App\Database\Migrations
- Siempre implementar up() y down()
- Las tablas de entidades principales necesitan: id (INT PK AI), uuid (VARCHAR 36 UNIQUE), created_at y updated_at (DATETIME NULL)
- Las llaves foráneas usan CASCADE en DELETE y UPDATE salvo indicación contraria
- Coordenadas GPS: DECIMAL(10,8) para lat, DECIMAL(11,8) para lng
- Precios: DECIMAL(10,2)
- Flags booleanos: TINYINT(1) DEFAULT 0

DETALLE DEL CAMBIO:
[Describe exactamente qué necesitas cambiar]

TABLA AFECTADA: [nombre_tabla]

GENERA el archivo completo de migración listo para guardar en:
app/Database/Migrations/[timestamp]_[Nombre].php
```

---

## Ejemplos de uso

### Ejemplo 1: Nueva tabla con relaciones

```
TIPO DE MIGRACIÓN: crear tabla
TABLA AFECTADA: route_history

DETALLE DEL CAMBIO:
Crear tabla route_history para guardar el rastro GPS completo de cada viaje.
Campos:
- id: PK autoincremental
- order_id: FK a orders.id con CASCADE
- driver_id: FK a drivers.id con CASCADE
- lat: DECIMAL(10,8) NOT NULL
- lng: DECIMAL(11,8) NOT NULL
- recorded_at: DATETIME NOT NULL (timestamp de la coordenada)
Sin updated_at (es un log inmutable)
```

### Ejemplo 2: Agregar columna

```
TIPO DE MIGRACIÓN: agregar columna
TABLA AFECTADA: orders

DETALLE DEL CAMBIO:
Agregar columna cancelled_reason VARCHAR(255) NULL
Colocarla después de la columna 'status'
En down() eliminar la columna
```

### Ejemplo 3: Modificar ENUM

```
TIPO DE MIGRACIÓN: modificar columna
TABLA AFECTADA: orders

DETALLE DEL CAMBIO:
El campo status actualmente tiene: ['pendiente','publicado','tomado','arribado','en_camino','entregado','rechazado','cancelado']
Agregar el valor 'devuelto' al ENUM
Usar ALTER TABLE con MODIFY COLUMN (no se puede hacer con $forge en ENUMs)
En down() revertir al ENUM original sin 'devuelto'
```

---

## Tipos de datos de referencia

```php
// Entero autoincremental (PK)
'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true]

// UUID
'uuid' => ['type' => 'VARCHAR', 'constraint' => 36, 'unique' => true]

// FK a otra tabla
'client_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true]
// + $this->forge->addForeignKey('client_id', 'clients', 'id', 'CASCADE', 'CASCADE')

// Precio
'amount' => ['type' => 'DECIMAL', 'constraint' => '10,2', 'default' => 0.00]

// Coordenada GPS
'lat' => ['type' => 'DECIMAL', 'constraint' => '10,8', 'null' => true]
'lng' => ['type' => 'DECIMAL', 'constraint' => '11,8', 'null' => true]

// Flag booleano
'is_active' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1]

// ENUM
'status' => ['type' => 'ENUM', 'constraint' => ['valor1', 'valor2'], 'default' => 'valor1']

// JSON
'polygon_coordinates' => ['type' => 'JSON']

// Timestamp nullable (para created_at / updated_at / deleted_at)
'created_at' => ['type' => 'DATETIME', 'null' => true]
```

---

## Buenas prácticas recordatorio

1. El timestamp del filename debe ser único — nunca reutilizar uno existente.
2. Prueba siempre: `php spark migrate` → `php spark migrate:rollback` → `php spark migrate`.
3. Si la migración modifica datos (UPDATE), documentarlo en el `down()` aunque no sea reversible.
4. No uses raw SQL si CI4 Forge lo puede hacer — es más portable.
5. Para ENUMs complejos o JSON, raw SQL es aceptable.
