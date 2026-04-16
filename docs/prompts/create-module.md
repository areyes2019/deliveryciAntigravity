# Prompt: Crear módulo completo en CI4 + Vue 3

Usa este prompt cuando necesites crear un nuevo módulo desde cero (controller, model, migration, rutas y vista Vue).

---

## Prompt para el backend (CI4)

```
Eres un desarrollador senior de CodeIgniter 4. Necesito crear un nuevo módulo para el sistema de delivery.

CONTEXTO DEL SISTEMA:
- Backend: CodeIgniter 4 con arquitectura REST API
- Auth: JWT con roles (superadmin, client_admin, driver)
- Todos los controllers extienden BaseController y usan ApiResponseTrait
- Todos los models usan $protectFields = true y generan UUID en beforeInsert
- Los servicios contienen la lógica de negocio (nunca en controllers)
- Las respuestas siempre usan respondSuccess() / respondError() / respondUnauthorized()
- Las operaciones multi-tabla usan transacciones CI4 (transStart / transComplete)

MÓDULO A CREAR: [NOMBRE DEL MÓDULO]
TABLA PRINCIPAL: [nombre_tabla]
ROLES QUE ACCEDEN: [superadmin / client_admin / driver]

CAMPOS DE LA TABLA:
- campo1: tipo, descripción
- campo2: tipo, descripción
- ...

OPERACIONES REQUERIDAS:
- [ ] Listar (GET)
- [ ] Ver uno (GET /:id)
- [ ] Crear (POST)
- [ ] Actualizar (PUT /:id)
- [ ] Eliminar (DELETE /:id)
- [ ] [Operación especial si aplica]

REGLAS DE NEGOCIO:
1. [Regla 1]
2. [Regla 2]

GENERA:
1. Migration: app/Database/Migrations/FECHA_Create[Nombre]Table.php
2. Model: app/Models/[Nombre]Model.php con $allowedFields, UUID callback, timestamps
3. Service: app/Services/[Nombre]Service.php con la lógica de negocio
4. Controller: app/Controllers/Api/V1/[Nombre]Controller.php
5. Rutas para agregar en app/Config/Routes.php

CONVENCIONES OBLIGATORIAS:
- $protectFields = true en el model
- UUID autogenerado en beforeInsert
- Transacciones DB para operaciones multi-tabla
- Verificación de jwtPayload en cada método del controller
- respondSuccess/respondError/respondUnauthorized para todas las respuestas
- Validar que el recurso pertenece al cliente del usuario antes de modificar
```

---

## Prompt para el frontend (Vue 3)

```
Eres un desarrollador senior de Vue 3. Necesito crear la vista para el módulo [NOMBRE].

CONTEXTO DEL FRONTEND:
- Vue 3 con Composition API y <script setup>
- Pinia para estado global (solo hay un store: auth.js)
- Axios centralizado en api.js con interceptor JWT automático
- Tailwind CSS para estilos
- Google Maps via MapService singleton (si el módulo necesita mapa)
- Estructura de respuesta API: { status, message, data, errors }
- El rol del usuario está en: useAuthStore().userRole
- El token se adjunta automáticamente — no lo envíes manual

MÓDULO: [NOMBRE]
RUTA VUE ROUTER: /[ruta]
ROLES QUE ACCEDEN: [roles]

FUNCIONALIDADES DE LA VISTA:
- Tabla con listado de [entidad]
- Modal para crear nuevo [entidad]
- Modal para editar [entidad]
- Botón de eliminar con confirmación
- [Funcionalidad adicional si aplica]

ENDPOINTS QUE CONSUME:
- GET /api/v1/[recurso]
- POST /api/v1/[recurso]
- PUT /api/v1/[recurso]/:id
- DELETE /api/v1/[recurso]/:id

GENERA:
1. Vista: frontend/src/views/[Nombre]View.vue
2. Agrega la ruta en frontend/src/router/index.js con meta: { requiresAuth: true, roles: [...] }

CONVENCIONES OBLIGATORIAS:
- <script setup> con Composition API
- Manejo de errores: mostrar error.response.data.message al usuario
- Estados de carga (loading) en operaciones async
- Confirmación antes de eliminar
- Limpiar formulario al cerrar modal
```

---

## Checklist post-generación

Después de generar el módulo, verifica:

- [ ] La migración tiene `up()` y `down()` correctos
- [ ] El model tiene todos los campos en `$allowedFields`
- [ ] Las rutas tienen el filtro `['filter' => 'jwt:rol']` correcto
- [ ] El controller verifica que el recurso pertenece al cliente del usuario
- [ ] La vista maneja el estado de loading
- [ ] La vista maneja errores del API
- [ ] La ruta Vue tiene `meta.roles` configurado
- [ ] `php spark migrate` corre sin errores
