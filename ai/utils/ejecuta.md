Actúa como Arquitecto Senior del proyecto Delivery.

Lee completamente:
ai\promps\auditoria\fase_2_backend_queries.md
ai\promps\auditoria\fase_3_gps_writes.md
ai\promps\auditoria\fase_4_pusher_batch.md
ai\promps\auditoria\fase_5_redis_cache.md
ai\promps\auditoria\fase_6_polling_fallback.md

Antes de modificar código:

1. Analiza el estado actual.
2. Identifica diferencias entre código y spec.
3. Genera plan de implementación.
4. Enumera riesgos.
5. Enumera archivos afectados.
6. Propón estrategia de rollback.

RESTRICCIONES:

- No romper producción.
- No modificar funcionalidades existentes.
- Mantener compatibilidad.
- Realizar cambios incrementales.
- Validar después de cada paso.

IMPORTANTE:

Implementar exclusivamente lo definido en el spec.

Implementar siempre con base a global_criticador.md

No realizar mejoras no solicitadas.
No refactorizar áreas fuera del alcance.
No introducir nuevas tecnologías.