# Arquitectura del Sistema

## Visión general

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENTE (Browser)                     │
│                    Vue 3 + Vite + Pinia                      │
│           Panel Admin  /  App Conductor (PWA)                │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTPS + JWT (Authorization: Bearer)
                           │ Axios — api.js
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    BACKEND (CI4 API REST)                     │
│                                                              │
│  Routes.php → JwtFilter → Controller → Service → Model      │
│                                                              │
│  Filtros:    CorsFilter (global), JwtFilter (por ruta)       │
│  Servicios:  OrderService, PricingService, WalletService,    │
│              CreditService, SmsService, ZoneMatrixService    │
│  Helpers:    GeoHelper (Ray-Casting, Haversine)              │
└──────────────────────────┬──────────────────────────────────┘
                           │ MySQLi
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                      BASE DE DATOS (MySQL 8)                  │
│  users · clients · drivers · orders · order_status_log      │
│  pricing_zones · zone_pricing_matrix · wallet_movements      │
│  credit_transactions · driver_billing_config                 │
└─────────────────────────────────────────────────────────────┘
```

## Flujo de una petición típica

```
1. Vue component llama a api.post('/orders', data)
2. api.js interceptor adjunta el JWT en Authorization header
3. CorsFilter responde OPTIONS o agrega headers CORS
4. JwtFilter valida el token y verifica el rol requerido
5. JwtFilter inyecta el payload en $request->jwtPayload
6. Controller recibe la petición y lee $this->request->jwtPayload
7. Controller valida los datos de entrada
8. Controller llama al Service correspondiente
9. Service ejecuta la lógica de negocio (transacción DB si aplica)
10. Service retorna array { status, message, data }
11. Controller usa ApiResponseTrait para devolver JSON estandarizado
12. Vue component procesa la respuesta
```

## Organización de carpetas

### Backend — `app/`

```
app/
├── Config/
│   ├── Routes.php          # Todas las rutas de la API
│   ├── Filters.php         # Registro de filtros (jwt, corsFilter)
│   └── Pricing.php         # Parámetros del motor de tarifas
├── Controllers/Api/V1/
│   ├── Auth.php            # Login + token refresh
│   ├── ClientController.php
│   ├── DriverController.php
│   ├── OrderController.php
│   ├── PricingController.php
│   ├── WalletController.php
│   ├── DriverBillingConfigController.php
│   └── Driver/
│       └── DriverApiController.php   # Endpoints exclusivos de la app del conductor
├── Filters/
│   ├── JwtFilter.php       # Autenticación + autorización por rol
│   └── CorsFilter.php      # Headers CORS globales
├── Models/                 # Un modelo por tabla, con $allowedFields y callbacks
├── Services/               # Lógica de negocio (nunca en controllers)
├── Helpers/
│   └── GeoHelper.php       # Algoritmos geográficos (Ray-Casting, Haversine)
├── Libraries/
│   └── JwtLibrary.php      # Generación y validación de JWT
└── Traits/
    └── ApiResponseTrait.php # Formato estándar de respuestas JSON
```

### Frontend — `frontend/src/`

```
frontend/src/
├── main.js                 # Bootstrap: Vue + Pinia + Router
├── api.js                  # Instancia Axios + interceptores JWT/401
├── App.vue                 # Root component
├── router/
│   └── index.js            # Rutas + guards de autenticación y rol
├── stores/
│   └── auth.js             # Estado global: token, user, login(), logout()
├── services/maps/
│   ├── BaseProvider.js     # Interfaz (contrato) de proveedores de mapa
│   ├── GoogleProvider.js   # Implementación Google Maps
│   └── MapService.js       # Singleton facade — único acceso al mapa
├── views/                  # Una vista por ruta
└── components/             # Componentes reutilizables
```

## Convenciones del backend

### Controllers
- Solo orquestan: validan input, llaman servicios, devuelven JSON.
- Nunca contienen lógica de negocio ni queries directas.
- Siempre usan `ApiResponseTrait` para respuestas.
- Siempre verifican `$this->request->jwtPayload` para autorización adicional.

### Services
- Contienen toda la lógica de negocio.
- Las operaciones multi-tabla usan transacciones CI4 (`transStart` / `transComplete`).
- Retornan `['status' => bool, 'message' => string, 'data' => array]`.
- No dependen de `$request` ni del contexto HTTP.

### Models
- Un modelo por tabla.
- `$protectFields = true` siempre — protección contra mass assignment.
- UUID generado automáticamente en `beforeInsert`.
- Passwords hasheados automáticamente en `beforeInsert` y `beforeUpdate`.
- Soft deletes solo en `users` (para preservar integridad referencial).

### Rutas
- Todas bajo `/api/v1/`.
- Protegidas con `['filter' => 'jwt:rol1,rol2']`.
- Versionadas para permitir cambios sin romper clientes existentes.

## Convenciones del frontend

### Stores (Pinia)
- Un store por dominio de datos.
- El store `auth` persiste en `localStorage`.
- Los stores no hacen llamadas HTTP directamente — usan `api.js`.

### Componentes
- Composition API con `<script setup>`.
- Props tipadas con `defineProps`.
- Emits documentados con `defineEmits`.

### Manejo de errores HTTP
- `401` → logout automático vía interceptor de Axios.
- `400` → mostrar `error.response.data.message` al usuario.
- `500` → mostrar mensaje genérico de error del sistema.

## Seguridad

| Capa | Mecanismo |
|---|---|
| Transporte | HTTPS en producción |
| Autenticación | JWT firmado con HMAC-SHA256 |
| Autorización | Roles verificados en JwtFilter (backend) y router guards (frontend) |
| Contraseñas | `password_hash()` con `PASSWORD_DEFAULT` (bcrypt) |
| Mass assignment | `$protectFields = true` en todos los modelos |
| CORS | `CorsFilter` — restringir `Allow-Origin` en producción |
| Secreto JWT | Variable de entorno `encryption.key` — nunca hardcodeada |
