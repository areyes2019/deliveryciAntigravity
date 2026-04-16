# Módulo: Users (Usuarios)

## Problema que resuelve

Centraliza la identidad y acceso de todos los actores del sistema. Un solo modelo de usuario con tres roles bien diferenciados controla qué puede hacer cada persona en la plataforma.

## Funcionalidades principales

- Autenticación con email y contraseña → JWT.
- Tres roles con permisos distintos: `superadmin`, `client_admin`, `driver`.
- Creación de usuarios como parte de la creación de clientes y conductores.
- Suspensión administrativa de usuarios (sin eliminarlos).
- Soft delete para preservar integridad referencial en histórico de órdenes.
- Perfil de usuario con datos adicionales según el rol.

## Entidades involucradas

| Entidad | Tabla | Rol |
|---|---|---|
| User | `users` | Identidad base de todos los actores |
| Client | `clients` | Perfil extendido del `client_admin` |
| Driver | `drivers` | Perfil extendido del `driver` |

## Roles y permisos

### `superadmin`
- Acceso total a la plataforma.
- Gestión de clientes (CRUD + asignación de créditos).
- Vista de todas las órdenes y conductores.
- Gestión de billeteras de conductores.
- No tiene perfil en `clients` ni `drivers`.

### `client_admin`
- Acceso al panel de su empresa.
- Gestión de sus conductores.
- Creación y seguimiento de sus órdenes.
- Configuración de tarifas y zonas.
- Configuración del esquema de cobro a conductores.
- Tiene un registro en `clients` con `user_id` como FK.

### `driver`
- Acceso solo a la app móvil (PWA).
- Ver y aceptar viajes de su empresa.
- Actualizar estados y enviar GPS.
- Consultar su billetera.
- Tiene un registro en `drivers` con `user_id` como FK.

## Reglas de negocio

1. El email es único en todo el sistema — no puede repetirse entre roles.
2. Las contraseñas nunca se almacenan en texto plano (bcrypt via `password_hash`).
3. Un usuario con `is_suspended = 1` no puede hacer login (`Auth::login` retorna 403).
4. Al crear un `client_admin`, se crea simultáneamente su registro en `clients` (transacción).
5. Al crear un `driver`, se crea simultáneamente su registro en `drivers` (transacción).
6. Al eliminar un cliente o conductor, se elimina también el usuario (transacción).
7. Los usuarios eliminados usan soft delete (`deleted_at`) para no romper el histórico de órdenes.
8. El UUID se genera automáticamente al crear el usuario — es el identificador público seguro.

## Casos de uso

### UC-01: Login
1. Usuario envía `email` y `password`.
2. El sistema verifica credenciales y estado de suspensión.
3. Genera JWT con `{ id, uuid, email, role }` y lo retorna junto con el perfil completo.
4. El frontend guarda token y usuario en `localStorage` (Pinia store).

### UC-02: Superadmin crea un cliente
1. Superadmin completa el formulario con datos del negocio y del admin.
2. El sistema crea el usuario con rol `client_admin` y el registro en `clients` en una transacción.

### UC-03: Client_admin crea un conductor
1. `client_admin` completa el formulario del conductor.
2. El sistema crea el usuario con rol `driver` y el registro en `drivers` vinculado a su cliente.

### UC-04: Superadmin suspende a un usuario
1. Superadmin marca al usuario como suspendido.
2. El usuario no puede hacer login hasta que se levante la suspensión.
3. Si es un conductor conectado, no puede aceptar más viajes (`toggleAvailability` lo rechaza).

## Endpoints relacionados

| Método | Ruta | Rol | Descripción |
|---|---|---|---|
| POST | `/api/v1/auth/login` | público | Login → JWT |
| GET | `/api/v1/auth/me` | autenticado | Datos del usuario actual |
| GET | `/api/v1/clients` | superadmin | Listar clientes |
| POST | `/api/v1/clients` | superadmin | Crear cliente + usuario |
| PUT | `/api/v1/clients/:id` | superadmin | Actualizar cliente |
| DELETE | `/api/v1/clients/:id` | superadmin | Eliminar cliente + usuario |
| GET | `/api/v1/drivers` | client_admin | Listar conductores |
| POST | `/api/v1/drivers` | client_admin | Crear conductor + usuario |
| PUT | `/api/v1/drivers/:id` | client_admin | Actualizar conductor |
| DELETE | `/api/v1/drivers/:id` | client_admin | Eliminar conductor + usuario |
