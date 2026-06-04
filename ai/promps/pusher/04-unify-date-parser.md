Actúa como desarrollador senior Vue 3 Composition API.

CONTEXTO:

Existen dos parsers de fecha distintos en el proyecto para el mismo
formato que viene del backend (`Y-m-d H:i:s` en timezone America/Mexico_City):

Parser A — DashboardView.vue:44 (split manual, sin timezone):
```js
const p = o.scheduled_at.split(/[- :]/)
return new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]),
  parseInt(p[3]), parseInt(p[4]), parseInt(p[5] || 0)) > now
```

Parser B — useActivityFeed.js:311 (replace + UTC suffix):
```js
const then = new Date(refDate.replace(' ', 'T') + 'Z')
```

El Parser B es incorrecto para fechas locales (agrega 'Z' → las trata como UTC).
El Parser A es correcto para fechas locales pero frágil si el formato cambia.

TAREA:

Crear un helper compartido con el parser correcto y reemplazar los dos usos.

PASO 1 — Crear el archivo:

frontend/src/utils/parseBackendDate.js

```js
export function parseBackendDate(dateStr) {
  if (!dateStr) return null
  // El backend devuelve fechas en America/Mexico_City sin timezone explícita.
  // Reemplazamos el espacio por 'T' para que Date() lo interprete como hora local,
  // sin agregar 'Z' (que lo convertiría a UTC incorrectamente).
  const normalized = dateStr.replace(' ', 'T')
  const date = new Date(normalized)
  return isNaN(date.getTime()) ? null : date
}
```

PASO 2 — Reemplazar en DashboardView.vue (líneas 41-47):

ANTES:
```js
const pendingOrders = computed(() => {
  const now = new Date()
  return orders.value.filter(o => o.status === 'pendiente' && o.scheduled_at && (() => {
    const p = o.scheduled_at.split(/[- :]/)
    return new Date(parseInt(p[0]), parseInt(p[1]) - 1, parseInt(p[2]), parseInt(p[3]), parseInt(p[4]), parseInt(p[5] || 0)) > now
  })())
})
```

DESPUÉS:
```js
const pendingOrders = computed(() => {
  const now = new Date()
  return orders.value.filter(o => {
    if (o.status !== 'pendiente' || !o.scheduled_at) return false
    const scheduled = parseBackendDate(o.scheduled_at)
    return scheduled && scheduled > now
  })
})
```

Agregar el import al inicio del script de DashboardView.vue:
```js
import { parseBackendDate } from '../utils/parseBackendDate'
```

PASO 3 — Reemplazar en useActivityFeed.js (líneas 308-313):

ANTES:
```js
const refDate = order.updated_at || order.created_at
if (refDate) {
  const then = new Date(refDate.replace(' ', 'T') + 'Z')
  if (!isNaN(then.getTime())) {
    const diffMs = now - then
    return Math.max(0, Math.floor(diffMs / 60000))
  }
}
```

DESPUÉS:
```js
const refDate = order.updated_at || order.created_at
if (refDate) {
  const then = parseBackendDate(refDate)
  if (then) {
    const diffMs = now - then
    return Math.max(0, Math.floor(diffMs / 60000))
  }
}
```

Agregar el import al inicio de useActivityFeed.js:
```js
import { parseBackendDate } from '../utils/parseBackendDate'
```

REQUISITOS:

- No cambiar ninguna lógica adicional en ambos archivos.
- No mover ni reorganizar imports existentes.
- No tocar la lógica de filtrado más allá del parser.
- El helper debe ser una función pura sin dependencias externas.

VALIDACIÓN ESPERADA:

- `pendingOrders` filtra correctamente órdenes futuras con `scheduled_at`.
- `calcElapsedTime` calcula tiempos correctos sin sesgo de timezone.
- Ambos archivos usan el mismo parser.

Devuelve los tres archivos completos: el helper nuevo y los dos archivos modificados.
