# Prompts — Solución Pusher Dashboard (AUDITORIA_PUSHER_REALTIME.md)

Fuente: `AUDITORIA_PUSHER_REALTIME.md` — Plan de Migración Gradual (Sección 10)

Ejecutar en orden. Cada prompt depende del anterior donde se indica.
El polling sigue activo como fallback en todos los pasos — nada se rompe si Pusher falla.

---

## ORDEN DE EJECUCIÓN

| # | Archivo | Qué hace | Archivo modificado | Tiempo estimado | Prerequisito |
|---|---|---|---|---|---|
| 01 | `01-corregir-nombres-eventos.md` | Corrige los nombres de eventos y callbacks en `setupRealtimeListeners` | `useRealtimeSync.js` | 30 min | Ninguno |
| 02 | `02-activar-pusher-dashboard.md` | Conecta Pusher al Dashboard con los 3 handlers críticos | `DashboardView.vue` | 1-2 horas | 01 aplicado |
| 03 | `03-reducir-polling-fallback.md` | Reduce polling de 3s a 15s (solo cuando Pusher esté validado) | `useRealtimeSync.js` | 15 min | 02 aplicado y validado |
| 04 | `04-opcional-trigger-ubicacion.md` | Agrega trigger Pusher en `updateLocation()` — OPCIONAL | `DriverApiController.php` + `DashboardView.vue` | 2-3 horas | 02 aplicado |

---

## RIESGOS POR PROMPT

| # | Riesgo | Mitigación |
|---|---|---|
| 01 | Muy bajo — nadie usa `setupRealtimeListeners` todavía | Los handlers son callbacks, no se ejecutan hasta que DashboardView los conecte |
| 02 | Medio — nueva infraestructura activa | Polling de 3s sigue activo; si Pusher falla, el sistema continúa exactamente igual que hoy |
| 03 | **Alto si se aplica sin 02** | SOLO aplicar cuando Pusher esté validado en producción |
| 04 | Alto — riesgo de volumen en plan gratuito Pusher | Evaluar plan contratado antes de activar (10 conductores × 2s = 300 eventos/min) |

---

## PROBLEMA CENTRAL (de la auditoría)

El Dashboard usa únicamente polling a 3s. Pusher ya está activo en backend y en DriverAppView.
Los 3 eventos de mayor impacto para el Dashboard **ya existen en el backend** y no requieren trabajo PHP:

| Evento | Canal | Backend emite | Frontend necesita |
|---|---|---|---|
| `new-trip` | `trips.{client_id}` | ✅ 3 lugares en OrderService/OrderController | Suscribirse y recargar |
| `trip-taken` | `trips.{client_id}` | ✅ DriverApiController:140 | Suscribirse y actualizar mapa |
| `order-cancelled` | `orders.{client_id}` | ✅ OrderController:150 | Suscribirse y limpiar mapa |

El bloqueo actual: `setupRealtimeListeners` espera eventos con nombres incorrectos (`order-updated`, `driver-moved`) que el backend nunca emite. El prompt 01 corrige esto.

---

## VALIDACIÓN GLOBAL

Después de aplicar los 3 primeros prompts:

1. Crear una orden desde otra sesión → Dashboard actualiza sin esperar polling
2. Driver toma un viaje → mapa refleja el cambio en tiempo real
3. Cancelar una orden → marcador del mapa desaparece inmediatamente
4. Bloquear WebSocket en DevTools → polling de 15s sigue actualizando
5. En Network DevTools: `/orders` y `/drivers` se llaman cada 15s, no cada 3s
