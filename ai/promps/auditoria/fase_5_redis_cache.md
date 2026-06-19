# SPEC — Fase 5: Migrar Cache de Archivo a Redis

**Origen**: Auditoría técnica 2026-06-18  
**Alcance**: Un cambio de una línea en configuración + verificación de prerequisitos.  
**Riesgo**: Medio — requiere que Redis esté instalado y corriendo. Si Redis no está
disponible, el `$backupHandler = 'file'` garantiza que la app siga funcionando.  
**Tiempo estimado**: 30 minutos si Redis ya está instalado, 2 horas si hay que instalarlo.

---

## Contexto

**`app/Config/Cache.php` línea 25:**

```php
public string $handler = 'file';
```

Todo acceso a caché usa el filesystem: leer o escribir una entrada de caché implica
abrir un archivo en `writable/cache/`, operaciones de `fopen` / `fread` / `fwrite` / `fclose`.

El único uso activo de caché en producción es la llave `publish_due_orders_ran` en
`OrderController.php:33-36`:

```php
if (!cache()->get('publish_due_orders_ran')) {
    $this->orderService->publishDueOrders();
    cache()->save('publish_due_orders_ran', true, 60);
}
```

Esta llave se lee y escribe en cada `GET /orders`. Con el handler de archivo:
- Cada `cache()->get()` = `file_get_contents()`
- Cada `cache()->save()` = `file_put_contents()`

Redis es puramente en memoria — latencia de cache hit < 1ms vs ~5-15ms de disco.

La configuración de Redis ya existe en `Cache.php` líneas 126–134:

```php
public array $redis = [
    'host'       => '127.0.0.1',
    'password'   => null,
    'port'       => 6379,
    'timeout'    => 0,
    'async'      => false,
    'persistent' => false,
    'database'   => 0,
];
```

Solo falta activarlo como handler primario.

---

## Prerequisito — verificar que Redis está instalado y activo

### Paso 1 — comprobar si Redis server está corriendo

```bash
redis-cli ping
```

Respuesta esperada: `PONG`

Si el comando no existe o devuelve "Connection refused", Redis no está corriendo.

### Paso 2 — comprobar que PHP tiene la extensión Redis

```bash
php -m | grep -i redis
```

Respuesta esperada: `redis`

CI4 usa la extensión nativa `phpredis` (no Predis) cuando el handler es `'redis'`.

### Paso 3 — comprobar conectividad desde PHP

```bash
php -r "
  \$r = new Redis();
  \$r->connect('127.0.0.1', 6379);
  echo \$r->ping() . PHP_EOL;
"
```

Respuesta esperada: `+PONG`

Si alguno de los tres pasos falla, resolver la instalación/configuración de Redis
antes de hacer el cambio en `Cache.php`. La app funciona con el handler de archivo
en ese ínterin — no hay urgencia.

### Si Redis no está instalado (Windows + Laragon)

Laragon incluye Redis. Verificar en el panel de Laragon → Menu → Redis → Start.
Si no aparece, descargar desde el panel de Laragon Extensions o desde
`https://github.com/tporadowski/redis/releases` (builds para Windows).

---

## Cambio en Cache.php

**Archivo**: `app/Config/Cache.php`

### Línea 25 — handler primario

```diff
- public string $handler = 'file';
+ public string $handler = 'redis';
```

### Línea 36 — backup handler (sin cambio, ya es correcto)

```php
public string $backupHandler = 'dummy';
```

> El `backupHandler = 'dummy'` hace que si Redis no responde, CI4 use un handler
> en memoria que no persiste entre requests — el comportamiento degrada silenciosamente
> pero la app no cae. Esto es correcto para producción.
>
> Si se prefiere un fallback con persistencia garantizada cambiar a `'file'`, pero
> entonces se pierde el beneficio de no tener I/O de disco en el path feliz.

---

## Cambio en .env (si se usa password en Redis)

Si el servidor de Redis en producción tiene contraseña, configurarla en `.env`:

```env
cache.redis.password = tu_password_aqui
```

En desarrollo local sin contraseña (configuración actual) no se necesita.

---

## Verificación posterior al cambio

### Verificar que CI4 conecta con Redis

```bash
php spark cache:info
```

Debe mostrar `Handler: redis` y el estado de la conexión.

### Verificar que el cache de publish_due_orders_ran funciona con Redis

1. Asegurarse de que hay órdenes en `pendiente` con `scheduled_at` en el pasado.
2. Hacer `GET /api/v1/orders` — verificar en logs que `publishDueOrders` se ejecutó.
3. Hacer un segundo `GET /api/v1/orders` inmediatamente.
4. Verificar en logs que `publishDueOrders` **NO** se ejecutó (cache hit).
5. Verificar la llave en Redis:
   ```bash
   redis-cli get "publish_due_orders_ran"
   ```
   Debe devolver `"1"` (el valor `true` serializado por CI4).

### Verificar que los archivos de caché viejos no interfieren

```bash
ls writable/cache/
```

Los archivos existentes del handler de archivo quedan huérfanos después del cambio —
no afectan el funcionamiento pero ocupan espacio. Se pueden borrar manualmente:

```bash
php spark cache:clear
```

Ejecutar este comando **antes** de cambiar el handler para que limpie con el handler
de archivo, o borrar manualmente `writable/cache/*` después.

---

## Archivos modificados

| Archivo | Tipo de cambio |
|---|---|
| `app/Config/Cache.php` | Cambiar `$handler` de `'file'` a `'redis'` |

---

## Criterio de éxito

- [ ] `redis-cli ping` responde `PONG`
- [ ] `php -m | grep redis` devuelve `redis`
- [ ] `php spark cache:info` muestra handler Redis
- [ ] Dos `GET /orders` consecutivos: el segundo no ejecuta `publishDueOrders`
- [ ] `redis-cli get "publish_due_orders_ran"` devuelve `"1"` durante 60 segundos
      y luego nil (el TTL expiró)
- [ ] El directorio `writable/cache/` no recibe archivos nuevos durante uso normal
