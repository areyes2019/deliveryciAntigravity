# AUDITORÍA ARQUITECTÓNICA PROFUNDA DEL DASHBOARD

Actúa como un Arquitecto de Software Senior especializado en:

* Vue 3 Composition API
* CodeIgniter 4
* Sistemas Delivery / Logística
* Aplicaciones Realtime
* Google Maps
* Refactorización de sistemas en producción

IMPORTANTE:

Estás ejecutándote dentro de VSCode y tienes acceso completo al proyecto.

NO analices únicamente el archivo solicitado.

Debes investigar todo el contexto relacionado antes de emitir conclusiones.

Debes actuar como si fueras un auditor técnico que acaba de incorporarse al proyecto.

---

# CONTEXTO DEL PROYECTO

Stack:

Backend:

* CodeIgniter 4

Frontend:

* Vue 3
* Vite

Arquitectura:

* frontend/
* app/
* docs/
* tests/

Sistema:

* Plataforma de delivery
* Conductores
* Pedidos
* Tracking
* Dashboard operativo
* Google Maps
* Notificaciones
* Modales
* Estados de pedido

El proyecto ya está funcionando.

NO buscamos reescribirlo.

NO buscamos cambiar de framework.

NO buscamos una arquitectura teórica.

Buscamos una refactorización progresiva y segura.

---

# OBJETIVO DEL ANÁLISIS

Analizar DashboardView.vue como parte de un sistema completo.

NO analizarlo de forma aislada.

Debes identificar:

* dependencias reales
* acoplamientos
* riesgos
* oportunidades de extracción
* orden seguro de refactorización

---

# ANTES DE ANALIZAR

Debes inspeccionar todo lo relacionado con DashboardView.vue:

## Componentes

Buscar:

* componentes importados
* componentes hijos
* componentes hermanos relacionados

## Services

Buscar:

* MapService
* realtime.js
* servicios relacionados con órdenes
* servicios relacionados con conductores

## Stores

Buscar:

* Pinia stores existentes
* auth store
* estado compartido

## API

Buscar:

* endpoints consumidos
* llamadas HTTP
* fetch
* axios
* wrappers

## Documentación

Buscar:

* docs/
* arquitectura
* refactors existentes
* notas técnicas

## Dependencias

Construir un mapa mental de:

Dashboard
↓
Componentes
↓
Servicios
↓
API
↓
Mapa
↓
Realtime

ANTES de emitir cualquier recomendación.

---

# ENTREGABLE REQUERIDO

Generar las siguientes secciones.

---

# 1. RESPONSABILIDADES ACTUALES

Identificar todas las responsabilidades del Dashboard.

Para cada una indicar:

* descripción
* complejidad
* riesgo
* dependencia

Formato:

| Responsabilidad | Complejidad | Riesgo | Debe separarse |
| --------------- | ----------- | ------ | -------------- |

---

# 2. MAPA DE DEPENDENCIAS

Construir un árbol real.

Ejemplo:

DashboardView
→ useOrders
→ MapService
→ realtime
→ OrderDetailPanel

Indicar:

* dependencias directas
* dependencias indirectas
* dependencias ocultas

---

# 3. ACOPLAMIENTOS CRÍTICOS

Identificar:

* métodos que dependen entre sí
* efectos secundarios
* secuencias obligatorias
* dependencias temporales
* dependencias de estado

Ejemplo:

selectOrder()
↓
redrawDrivers()
↓
updateMapMarkers()

Explicar por qué son peligrosos.

---

# 4. RIESGOS DE REFACTORIZACIÓN

Clasificar:

🔴 Alto
🟡 Medio
🟢 Bajo

Para cada riesgo indicar:

* qué podría romperse
* impacto
* mitigación

---

# 5. COMPONENTES CANDIDATOS

Identificar qué partes deberían convertirse en componentes.

Ordenarlos por prioridad.

Indicar:

* beneficio
* dificultad
* riesgo

---

# 6. COMPOSABLES CANDIDATOS

Identificar lógica reutilizable.

Indicar:

* responsabilidad
* dependencias
* riesgo de extracción

---

# 7. SERVICES CANDIDATOS

Identificar lógica que no pertenece a Vue.

Ejemplos:

* mapas
* realtime
* órdenes
* conductores

Indicar si ya existen o deben crearse.

---

# 8. ESTADO GLOBAL

Analizar si realmente se necesita Pinia.

NO asumir que todo debe ir a un store.

Indicar:

* qué estado puede permanecer local
* qué estado debería ser compartido
* qué estado debería ir a store

---

# 9. PLAN DE REFACTORIZACIÓN

Generar un plan por fases.

IMPORTANTE:

Priorizar seguridad sobre perfección.

Las fases deben ser:

FASE 1
Componentes visuales solamente

FASE 2
Composables

FASE 3
Mapa y realtime

FASE 4
Stores

Cada fase debe incluir:

* objetivo
* tareas
* riesgos
* validaciones

---

# 10. REGLAS OBLIGATORIAS

NO proponer:

* reescritura completa
* migración de framework
* microservicios
* TypeScript
* arquitectura enterprise innecesaria
* cambios que rompan producción

NO asumir.

NO inventar.

NO teorizar.

Basarse exclusivamente en el código encontrado.

Si una conclusión no puede demostrarse mediante el código, indicar explícitamente:

"NO SE ENCONTRÓ EVIDENCIA SUFICIENTE"

---

# RESULTADO ESPERADO

Quiero un documento técnico de auditoría arquitectónica.

No quiero propuestas genéricas.

No quiero opiniones.

Quiero un mapa real del estado actual del sistema y un plan seguro de evolución.
