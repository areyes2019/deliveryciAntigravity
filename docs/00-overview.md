# Sistema de Delivery — Visión General

## Descripción del sistema

Plataforma SaaS multi-tenant de gestión de entregas a último kilómetro. Permite a empresas (clientes) crear y gestionar órdenes de delivery, asignar conductores, calcular tarifas automáticamente y hacer seguimiento en tiempo real desde una sola interfaz web y móvil.

## Problema que resuelve

Las empresas que ofrecen delivery propio necesitan:
- Asignar entregas a conductores sin coordinación manual por WhatsApp o teléfono.
- Calcular el costo de cada viaje de forma automática y transparente.
- Saber en todo momento dónde está el conductor y en qué estado va la entrega.
- Llevar contabilidad de lo que cobra a sus conductores por usar la plataforma.
- Notificar al destinatario cuando su paquete está en camino.

## Usuarios del sistema

| Rol | Descripción | Acceso |
|---|---|---|
| `superadmin` | Administrador global de la plataforma. Crea y gestiona clientes, asigna créditos. | Panel web completo |
| `client_admin` | Administrador de una empresa cliente. Crea órdenes, gestiona sus conductores y tarifas. | Panel web del cliente |
| `driver` | Conductor de una empresa cliente. Acepta viajes, actualiza estados y envía ubicación GPS. | App móvil web (PWA) |

## Alcance

### Incluye
- Gestión completa de órdenes de entrega (crear, publicar, asignar, completar, cancelar).
- Cálculo automático de tarifas por distancia o por zonas geográficas.
- Seguimiento GPS en tiempo real del conductor.
- Sistema de créditos para clientes (prepago).
- Billetera de conductores (garantía + ganancias).
- Esquemas de cobro a conductores: por crédito o por porcentaje.
- Notificaciones SMS al destinatario vía Twilio.
- Historial de estados por orden (auditoría).
- Panel de reportes por cliente.

### No incluye (fuera de alcance actual)
- Pagos en línea a la plataforma (los créditos se cargan manualmente por el superadmin).
- Integración con e-commerce (Shopify, WooCommerce, etc.).
- App nativa iOS/Android (la app del conductor es una PWA).
- Chat entre conductor y cliente.
- Soporte multi-idioma.

## Objetivos técnicos

- **API REST stateless**: el backend CI4 solo sirve JSON; el frontend Vue 3 consume la API.
- **Autenticación JWT**: tokens con expiración configurable, sin sesiones en servidor.
- **Multi-tenant**: cada cliente tiene sus propios conductores, zonas y tarifas aisladas.
- **Extensibilidad**: arquitectura de proveedores para mapas y notificaciones (fácil de cambiar de Google Maps o Twilio).
- **Seguridad por capas**: guards en el router Vue + filtros JWT en CI4.
- **Trazabilidad**: toda orden tiene un log de cambios de estado con timestamp.

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Backend | CodeIgniter 4 (PHP 8+) |
| Frontend | Vue 3 + Vite + Pinia |
| Base de datos | MySQL 8 |
| Autenticación | JWT (firebase/php-jwt) |
| Mapas | Google Maps API (Maps, Directions, Places, Drawing) |
| SMS | Twilio |
| Servidor dev | XAMPP (Apache + PHP) |
