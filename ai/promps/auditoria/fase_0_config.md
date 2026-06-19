# SPEC — Fase 0: Correcciones de Configuración

**Origen**: Auditoría técnica 2026-06-18  
**Alcance**: Tres cambios de configuración. Cero lógica de negocio tocada.  
**Riesgo**: Ninguno — son ajustes de archivos de configuración existentes.  
**Tiempo estimado**: 15 minutos en total.

---

## Contexto

La auditoría detectó tres problemas de configuración que generan overhead en cada
request sin ningún beneficio funcional:

1. `CI_ENVIRONMENT = development` activo en el servidor — activa debug toolbar,
   logging de nivel 9 (escribe todo en disco) y desactiva optimizaciones de CI4.
2. Doble filtro CORS por request — el filtro nativo de CI4 en `$required` Y un
   filtro personalizado `corsFilter` en el grupo de rutas `api/v1`.
3. Sesiones de archivo activas en una API que usa exclusivamente JWT — I/O de
   disco innecesario si CI4 intenta iniciar sesión en algún punto del ciclo de vida.

---

## ~~Tarea 0.1 — Cambiar CI_ENVIRONMENT a production~~ ✓ YA APLICADO

> **El `.env` remoto ya tiene `CI_ENVIRONMENT = production`.**  
> El `.env` local del repositorio muestra `development`, pero ese archivo no se
> despliega al servidor — cada entorno mantiene su propio `.env` fuera del control
> de versiones. Esta tarea no requiere ningún cambio.

### Verificación (confirmar que el entorno remoto está correcto)

Hacer un request a la API en el servidor de producción y confirmar que:
- La cabecera `X-DebugBar-*` **no aparece** en la respuesta.
- El archivo `writable/logs/log-YYYY-MM-DD.log` no contiene mensajes de nivel
  `DEBUG` ni `INFO` en cada request normal.
- Los errores reales (nivel 4+) siguen escribiéndose en el log.

---

## Tarea 0.2 — Eliminar el filtro corsFilter duplicado en Routes.php

### Contexto

Hay dos sistemas CORS activos simultáneamente:

| Sistema | Archivo | Alcance |
|---|---|---|
| Filtro nativo CI4 `cors` | `app/Config/Filters.php:55` — `$required['before']` | TODOS los requests |
| Filtro personalizado `corsFilter` | `app/Config/Routes.php:13` — grupo `api/v1` | Solo `api/v1` |

El filtro nativo ya maneja preflight OPTIONS globalmente (también lo confirma la ruta
OPTIONS en `Routes.php:90`). El `corsFilter` personalizado es redundante.

### Archivo
`/app/Config/Routes.php` línea 13

### Cambio exacto

```diff
- $routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1', 'filter' => 'corsFilter'], static function($routes) {
+ $routes->group('api/v1', ['namespace' => 'App\Controllers\Api\V1'], static function($routes) {
```

> Conservar el archivo `app/Filters/CorsFilter.php` sin tocar — solo se deja de
> registrar en las rutas. No eliminarlo por si hay otros usos.

### Verificación
Hacer una request OPTIONS de preflight desde el frontend (o con curl):

```bash
curl -i -X OPTIONS http://delivery.test/api/v1/orders \
  -H "Origin: http://localhost:5173" \
  -H "Access-Control-Request-Method: GET"
```

Confirmar que la respuesta sigue devolviendo `Access-Control-Allow-Origin` correctamente
con código 204. Si los headers CORS desaparecen, significa que el filtro nativo no está
configurado — en ese caso revertir este cambio y diagnosticar `app/Config/Cors.php`.

---

## Tarea 0.3 — Confirmar que sesiones de archivo no se inician en requests de API

### Contexto

`app/Config/Session.php:25` usa `FileHandler`. Este sistema es una API REST pura con
autenticación JWT. Las sesiones de CI4 no deberían iniciarse en ningún request de la API.

Esta tarea es de **diagnóstico primero, cambio solo si se confirma el problema**.

### Paso 1 — Diagnosticar

Buscar en todo el proyecto si hay alguna llamada explícita a sesiones:

```bash
grep -r "session()" app/ --include="*.php"
grep -r "\$session" app/ --include="*.php"
grep -r "session_start" app/ --include="*.php"
```

Si el resultado está vacío o solo aparecen comentarios: las sesiones no se usan
explícitamente. Pasar al Paso 2.

### Paso 2 — Verificar si CI4 inicia sesión automáticamente

Revisar si algún filtro global o BaseController llama al servicio de sesión.
En `app/Controllers/BaseController.php` verificar si existe:

```php
$this->session = \Config\Services::session();
```

Si existe, es la fuente del I/O de archivo en cada request.

### Paso 3 — Cambio (solo si se confirma que hay I/O de sesión innecesario)

**Opción A** — Cambiar el driver a `ArrayHandler` (sin persistencia, sin I/O):

En `app/Config/Session.php` línea 25:

```diff
- public string $driver = FileHandler::class;
+ public string $driver = \CodeIgniter\Session\Handlers\ArrayHandler::class;
```

**Opción B** — Si BaseController inicializa `$this->session`, eliminar esa línea
en `app/Controllers/BaseController.php` si no se usa en ningún controller.

### Verificación
Después del cambio, confirmar que el directorio `writable/session/` deja de recibir
archivos nuevos durante los requests de la API.

---

## Criterio de éxito global de la Fase 0

Todos los siguientes puntos deben cumplirse antes de cerrar esta fase:

- [x] `CI_ENVIRONMENT = production` en el `.env` remoto (ya estaba aplicado)
- [ ] Confirmar que requests de API en producción no generan cabeceras de debug toolbar
- [ ] Confirmar que el log solo registra eventos de nivel 4+ en producción
- [ ] Un preflight OPTIONS a cualquier endpoint de `api/v1` responde con headers CORS correctos
- [ ] No hay doble procesamiento CORS (verificable con un solo filtro activo)
- [ ] Directorio `writable/session/` no recibe archivos nuevos durante uso normal de la API
  (o se confirma que la sesión nunca se inicia y la tarea 0.3 se cierra sin cambio)

---

## Archivos involucrados

| Archivo | Tarea | Tipo de cambio |
|---|---|---|
| `/.env` | 0.1 | Editar una línea |
| `/app/Config/Routes.php` | 0.2 | Eliminar atributo `filter` del grupo |
| `/app/Config/Session.php` | 0.3 | Editar solo si diagnóstico lo confirma |
| `/app/Controllers/BaseController.php` | 0.3 | Revisar, cambiar solo si aplica |
