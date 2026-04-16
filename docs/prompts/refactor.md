# refactor.md

## 🎯 Objetivo

Definir las reglas y estándares de calidad para refactorizar código en el proyecto **Delivery (CI4 + Vue 3)**.

Este documento NO es para escribir código ni para pegar implementaciones.  
Es una guía global que se aplica a cualquier módulo del sistema.

---

## 🧠 Principios generales

- El código debe ser **simple, legible y mantenible**
- Evitar duplicidad (DRY)
- Separación clara de responsabilidades
- Priorizar claridad sobre “magia”
- Todo código debe poder escalar sin romperse

---

## ⚙️ Backend (CodeIgniter 4)

### 1. Controladores

- NO deben contener lógica de negocio
- Solo reciben request y retornan response
- Delegar lógica a Services o Models

✔ Correcto:
- Validar request
- Llamar servicio
- Retornar JSON

❌ Incorrecto:
- Procesar cálculos complejos
- Acceder directamente a múltiples modelos con lógica mezclada

---

### 2. Servicios (Services)

- Aquí vive la lógica de negocio
- Deben ser reutilizables
- Métodos claros y pequeños

✔ Ejemplo de responsabilidad:
- Calcular totales
- Procesar pedidos
- Validar reglas de negocio

---

### 3. Modelos

- Solo acceso a base de datos
- NO lógica de negocio
- Métodos simples y directos

---

### 4. Respuestas

- Todas las respuestas deben ser JSON estructurado

Formato estándar:

```json
{
  "status": "success",
  "message": "",
  "data": {}
}