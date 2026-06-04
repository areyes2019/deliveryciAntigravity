# Prompts — Integración Pusher & Correcciones de Arquitectura

Fuente: `AUDITORIA_ARQUITECTONICA.md` + `AUDITORIA_PUSHER_REALTIME.md` + `MAPA_EVENTOS_REALTIME.md`

Ejecutar en orden. Cada prompt es independiente pero depende del anterior donde se indica.

---

## ORDEN DE EJECUCIÓN

| # | Archivo | Qué hace | Archivo modificado | Prerequisito |
|---|---|---|---|---|
| 01 | `01-fix-mapservice-destroy.md` | Agrega `this.provider = null` en `destroy()` | `MapService.js` | Ninguno |
| 02 | `02-fix-dead-code-dashboard.md` | Elimina `setTimeout(800)` muerto en `fetchDashboardData` | `DashboardView.vue` | Ninguno |
| 03 | `03-fix-onunmounted-polling.md` | Elimina `onUnmounted` incorrecto dentro de `startPolling` | `useRealtimeSync.js` | Ninguno |
| 04 | `04-unify-date-parser.md` | Crea `parseBackendDate` helper y unifica parsers de fecha | `parseBackendDate.js` (nuevo), `DashboardView.vue`, `useActivityFeed.js` | Ninguno |
| 05 | `05-fix-realtime-event-names.md` | Corrige nombres de eventos en `setupRealtimeListeners` | `useRealtimeSync.js` | 03 aplicado |
| 06 | `06-conectar-dashboard-pusher.md` | Conecta Pusher al Dashboard con 3 handlers reales | `DashboardView.vue` | 05 aplicado |
| 07 | `07-reducir-polling-fallback.md` | Reduce polling de 3s a 15s (solo después de validar Pusher) | `useRealtimeSync.js` | 06 aplicado y validado |

---

## RIESGOS POR PROMPT

| # | Riesgo | Mitigación |
|---|---|---|
| 01 | Muy bajo | Solo agrega una línea |
| 02 | Muy bajo | Elimina código que nunca se ejecuta |
| 03 | Bajo | DashboardView ya tiene su propio cleanup |
| 04 | Bajo | Lógica pura, sin tocar DOM ni mapa |
| 05 | Bajo | Ningún componente usa `setupRealtimeListeners` aún |
| 06 | Medio | Conecta nueva infraestructura — validar en staging |
| 07 | **Alto si se aplica sin 06** | Solo aplicar cuando Pusher esté validado en producción |

---

## VALIDACIÓN GLOBAL

Después de aplicar todos los prompts:

1. Alternar Mapa ↔ ActivityFeed 10+ veces → mapa nunca queda en blanco
2. Crear una orden → Dashboard actualiza sin esperar el polling
3. Driver toma un viaje → mapa refleja el cambio en tiempo real
4. Cancelar una orden → marcador desaparece del mapa inmediatamente
5. Bloquear WebSocket en DevTools → polling de 15s sigue actualizando
6. En Network: `/orders` y `/drivers` se llaman cada 15s, no cada 3s
