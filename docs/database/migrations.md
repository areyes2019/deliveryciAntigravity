# Base de Datos — Migraciones

## Convenciones en CI4

### Nomenclatura de archivos

```
YYYYMMDDHHMMSS_NombreDescriptivo.php
```

Ejemplos reales del proyecto:
```
20240404000001_Users.php
20260406000001_AddPricingConfigToClients.php
20260414000002_AddWalletTypeToWalletMovements.php
```

**Reglas:**
- La fecha determina el orden de ejecución.
- El nombre describe claramente qué hace la migración.
- Una migración = un cambio atómico. No agrupar cambios no relacionados.
- Siempre implementar `up()` y `down()` para poder revertir.

### Comandos útiles

```bash
# Ejecutar migraciones pendientes
php spark migrate

# Verificar estado de todas las migraciones
php spark migrate:status

# Revertir la última migración
php spark migrate:rollback

# Crear nueva migración
php spark make:migration AddCampoATabla
```

---

## Estructura de una migración

```php
<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCampoATabla extends Migration
{
    public function up()
    {
        // Agregar columna
        $this->forge->addColumn('nombre_tabla', [
            'nuevo_campo' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
                'after'      => 'campo_existente',
            ],
        ]);
    }

    public function down()
    {
        // Revertir: eliminar la columna
        $this->forge->dropColumn('nombre_tabla', 'nuevo_campo');
    }
}
```

---

## Ejemplos reales del proyecto

### Crear tabla completa

```php
// 20240404000001_Users.php
public function up()
{
    $this->forge->addField([
        'id' => [
            'type'           => 'INT',
            'constraint'     => 11,
            'unsigned'       => true,
            'auto_increment' => true,
        ],
        'uuid' => [
            'type'       => 'VARCHAR',
            'constraint' => 36,
            'unique'     => true,
        ],
        'email' => [
            'type'       => 'VARCHAR',
            'constraint' => 150,
            'unique'     => true,
        ],
        'role' => [
            'type'       => 'ENUM',
            'constraint' => ['superadmin', 'client_admin', 'driver'],
        ],
        'deleted_at' => [
            'type' => 'DATETIME',
            'null' => true,
        ],
    ]);
    $this->forge->addKey('id', true);
    $this->forge->createTable('users', true);
}

public function down()
{
    $this->forge->dropTable('users', true);
}
```

### Agregar columna a tabla existente

```php
// 20260410000003_AddIsSuspendedToUsers.php
public function up()
{
    $this->forge->addColumn('users', [
        'is_suspended' => [
            'type'       => 'TINYINT',
            'constraint' => 1,
            'default'    => 0,
            'after'      => 'role',
        ],
    ]);
}

public function down()
{
    $this->forge->dropColumn('users', 'is_suspended');
}
```

### Eliminar columna

```php
// 20260410000005_DropIsActiveFromUsers.php
public function up()
{
    $this->forge->dropColumn('users', 'is_active');
}

public function down()
{
    $this->forge->addColumn('users', [
        'is_active' => [
            'type'    => 'TINYINT',
            'constraint' => 1,
            'default' => 1,
        ],
    ]);
}
```

### Crear tabla con llaves foráneas

```php
// 20240404000003_Drivers.php
public function up()
{
    $this->forge->addField([
        'id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
        'user_id'   => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        'client_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
        'current_lat' => ['type' => 'DECIMAL', 'constraint' => '10,8', 'null' => true],
        'current_lng' => ['type' => 'DECIMAL', 'constraint' => '11,8', 'null' => true],
    ]);
    $this->forge->addKey('id', true);
    $this->forge->addForeignKey('user_id',   'users',   'id', 'CASCADE', 'CASCADE');
    $this->forge->addForeignKey('client_id', 'clients', 'id', 'CASCADE', 'CASCADE');
    $this->forge->createTable('drivers', true);
}

public function down()
{
    $this->forge->dropTable('drivers', true);
}
```

---

## Tipos de datos más usados

| CI4 type | MySQL | Uso |
|---|---|---|
| `INT` | INT | IDs, contadores |
| `VARCHAR` | VARCHAR | Textos cortos con constraint |
| `TEXT` | TEXT | Textos largos |
| `DECIMAL` | DECIMAL(10,2) | Precios, coordenadas |
| `TINYINT` | TINYINT(1) | Flags booleanos (0/1) |
| `DATETIME` | DATETIME | Timestamps (created_at, etc.) |
| `ENUM` | ENUM | Valores fijos predefinidos |
| `JSON` | JSON | Estructuras complejas (polígonos) |

---

## Buenas prácticas

1. **Una migración por cambio**: no agrupar "agregar columna A" y "crear tabla B" en la misma migración.
2. **Siempre implementar `down()`**: permite hacer rollback limpio en cualquier entorno.
3. **No modificar migraciones ya ejecutadas en producción**: crea una nueva migración para corregir.
4. **Usar `true` en `createTable` y `dropTable`**: el segundo argumento `IF NOT EXISTS` / `IF EXISTS` evita errores en entornos inconsistentes.
5. **Coordenadas GPS**: usar `DECIMAL(10,8)` para latitud y `DECIMAL(11,8)` para longitud (8 decimales = precisión de ~1mm).
6. **Timestamps automáticos**: declarar `created_at` y `updated_at` como `DATETIME NULL` — CI4 los rellena solo cuando el modelo tiene `$useTimestamps = true`.
7. **Probar rollback localmente** antes de hacer deploy: `php spark migrate:rollback` seguido de `php spark migrate`.
