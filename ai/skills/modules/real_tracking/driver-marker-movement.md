# SPEC: Driver Marker Movement — Motito en tiempo real

**Estado:** Pendiente de implementación
**Fecha de análisis:** 2026-06-05
**Proyectos afectados:** `delivery` (admin dashboard) · `panda_expess` (driver PWA)

---

## Problema

El ícono del conductor (motito) en el mapa del admin dashboard **nunca se mueve**, ni en modo simulación ni en producción. El conductor puede desplazarse kilómetros y el pin permanece en la posición inicial.

**Síntoma en consola (build anterior):**
```
[TRACKING-AUDIT] _updateDriverMarker → lat=20.5193 lng=-100.8639 driver_id=1
[TRACKING-AUDIT] ✅ updateMarker ejecutado
```
Las coordenadas son siempre idénticas — la función se llama, pero siempre con el valor original.

---

## Arquitectura del flujo de posición

```
[Driver PWA - panda_expess]
  GPS real  → useRealTracking.onUpdate()  → POST /driver/location
  Simulador → useSimulator.moveStep()     → ❌ NADA (bug #1)

[Backend]
  POST /driver/location → actualiza DB + emite Pusher "driver-location" en trips.{clientId}

[Admin Dashboard - delivery]
  Pusher "driver-location" → onDriverLocation() → driver.current_lat/lng → updateMapMarkers()
  Polling cada 5s         → drivers.value = API data completo ← ❌ SOBREESCRIBE (bug #2)
  updateMapMarkers()      → GoogleProvider.updateMarker()     → marker.setPosition() → ❌ SALTA (bug #3)
  focusDriver()           → MapService.flyTo()                → ❌ NO EXISTE (bug #4)
```

---

## Bug 1 — Simulador no emite posiciones al backend

**Severidad:** CRÍTICO
**Proyecto:** `panda_expess`
**Archivo:** `src/composables/useSimulator.js`
**Líneas:** 59–65 (función interna `moveStep`)

### Causa raíz
`moveStep()` avanza `location.currentPos` punto por punto dentro del store local de Vue, pero nunca envía la posición al backend. `useRealTracking` está detenido en modo simulación, por lo que ninguna petición llega al servidor. El admin nunca recibe eventos Pusher de posición durante una simulación.

### Fix

```js
// 1. Agregar import al inicio del archivo
import api from '../services/api'

// 2. Dentro de moveStep(), DESPUÉS de actualizar currentPos (línea ~60):
location.currentIndex++
location.currentPos = location.routePoints[location.currentIndex]

// ── AGREGAR ESTO ──
if (location.currentPos) {
  api.post('/driver/location', {
    lat: location.currentPos.lat,
    lng: location.currentPos.lng,
    orderId: trip.activeOrder?.id ?? null,
  }).catch(() => {}) // Silencia errores de red para no romper la simulación
}
// ── FIN ──
```

### Contratos que no romper
- `location.currentPos` debe seguir siendo un objeto `{ lat, lng }` — es lo que usa `useMapController` para mover el pin en el mapa del conductor.
- El `catch(() => {})` es intencional: un fallo de red no debe detener la simulación.
- El intervalo de simulación ya está definido por `intervalMs` — no agregar delays adicionales.

---

## Bug 2 — Polling destruye posiciones en tiempo real

**Severidad:** CRÍTICO
**Proyecto:** `delivery`
**Archivos:**
- `frontend/src/composables/useRealtimeSync.js` — línea 37
- `frontend/src/views/DashboardView.vue` — handler `onDriverLocation` (~línea 147)

### Causa raíz
El polling silencioso ejecuta `drivers.value = driversRes.data.data` cada 5 segundos. Este reemplazo total destruye cualquier actualización de `current_lat/current_lng` que Pusher haya establecido entre ciclos. Si el backend no actualiza las columnas `current_lat/current_lng` en la DB al recibir el POST de ubicación, la API siempre devuelve la posición original y el polling la reimplanta permanentemente.

### Fix — Parte A: marcar timestamp en `DashboardView.vue`

Dentro del handler `onDriverLocation` (aproximadamente línea 147):

```js
onDriverLocation: ({ driver_id, lat, lng }) => {
  const driver = drivers.value.find(d => d.id === driver_id)
  if (driver) {
    driver.current_lat = lat
    driver.current_lng = lng
    driver._pusherTs = Date.now() // ── AGREGAR: timestamp de última actualización Pusher
    updateMapMarkers(mapCtx())
  }
}
```

### Fix — Parte B: merge inteligente en `useRealtimeSync.js`

Reemplazar la línea 37:

```js
// ANTES (línea 37):
drivers.value = driversRes.data.data

// DESPUÉS:
const freshDrivers = driversRes.data.data
freshDrivers.forEach(fresh => {
  const idx = drivers.value.findIndex(d => d.id === fresh.id)
  if (idx !== -1) {
    const existing = drivers.value[idx]
    // Si Pusher actualizó este conductor hace menos de 10 segundos,
    // conservar sus coordenadas en tiempo real en lugar de las de la DB.
    const pusherIsRecent = existing._pusherTs &&
      (Date.now() - existing._pusherTs) < 10_000
    drivers.value[idx] = {
      ...fresh,
      current_lat: pusherIsRecent ? existing.current_lat : fresh.current_lat,
      current_lng: pusherIsRecent ? existing.current_lng : fresh.current_lng,
      _pusherTs: existing._pusherTs ?? null,
    }
  } else {
    drivers.value.push(fresh) // Conductor nuevo: agregar sin filtro
  }
})
// Eliminar conductores que ya no existen en la API
drivers.value = drivers.value.filter(d => freshDrivers.some(f => f.id === d.id))
```

### Contratos que no romper
- `_pusherTs` es una propiedad interna de la UI — no enviarla al backend.
- La ventana de 10 segundos (10_000 ms) debe ser mayor que el intervalo del polling (5_000 ms) para garantizar que una actualización Pusher sobreviva al menos un ciclo de polling.
- Al eliminar conductores (`filter` final), respetar que `d.id === f.id` es comparación de números — no mezclar tipos string/number.

---

## Bug 3 — Marcador teleporta en vez de deslizarse

**Severidad:** UX
**Proyecto:** `delivery`
**Archivo:** `frontend/src/services/maps/GoogleProvider.js`
**Método:** `updateMarker` (~línea 159)

### Causa raíz
`marker.setPosition(coords)` aplica el cambio de coordenadas de forma instantánea. En Google Maps 3.65.3 con la API legacy (`google.maps.Marker`), el movimiento suave requiere interpolación manual vía `requestAnimationFrame`.

### Fix

Agregar el método privado `_animateMarker` a la clase `GoogleProvider`, y usarlo en `updateMarker`:

```js
// Agregar ANTES de updateMarker():
_animateMarker(marker, to, durationMs = 600) {
  const from = marker.getPosition()
  if (!from) {
    marker.setPosition(to)
    return
  }
  const startLat = from.lat()
  const startLng = from.lng()
  const startTime = performance.now()

  const step = (now) => {
    const t = Math.min((now - startTime) / durationMs, 1)
    // Interpolación lineal (lerp)
    marker.setPosition({
      lat: startLat + (to.lat - startLat) * t,
      lng: startLng + (to.lng - startLng) * t,
    })
    if (t < 1) requestAnimationFrame(step)
  }
  requestAnimationFrame(step)
}

// Modificar updateMarker() — reemplazar marker.setPosition(coords) por:
updateMarker(id, position, options = {}) {
  if (!this.markers) return
  const marker = this.markers.get(id)
  const coords = this._parsePosition(position)
  if (marker && coords) {
    this._animateMarker(marker, coords)       // ← reemplaza marker.setPosition(coords)
    if (options.icon) {
      marker.setIcon(this._normalizeIcon(options.icon))
    }
  } else if (!marker && coords) {
    this.addMarker(id, coords, options)
  }
}
```

### Contratos que no romper
- `_animateMarker` no debe cancelar una animación en curso antes de iniciar una nueva. Si las actualizaciones llegan muy seguido (< 600 ms), la animación anterior puede terminar en una posición ligeramente incorrecta. Esto es aceptable para el caso de uso actual.
- `durationMs = 600` está calibrado para actualizaciones de ~1–3 segundos. Si el intervalo de GPS baja a menos de 600 ms, reducir `durationMs` proporcionalmente.
- Este método solo aplica a marcadores con API legacy (`google.maps.Marker`). Si en el futuro se migra a `AdvancedMarkerElement`, `getPosition()` no existe — usar `marker.position` en su lugar.

---

## Bug 4 — `MapService.flyTo()` no existe

**Severidad:** Secundario (rompe `focusDriver` silenciosamente)
**Proyecto:** `delivery`
**Archivo:** `frontend/src/composables/useDashboardMap.js`
**Línea:** 178

### Causa raíz
`MapService` no define el método `flyTo()`. Solo existe `centerOn(position, zoom)`. La llamada lanza un `TypeError` silencioso que impide centrar el mapa al hacer clic en un conductor del FleetSidebar.

### Fix

```js
// ANTES (línea 178):
MapService.flyTo([lat, lng], 16)

// DESPUÉS:
MapService.centerOn([lat, lng], 16)
```

---

## Tabla de cambios

| # | Archivo | Cambio | Tipo |
|---|---------|--------|------|
| 1 | `panda_expess/src/composables/useSimulator.js` | `api.post('/driver/location')` en `moveStep()` | Agregar |
| 2A | `delivery/frontend/src/views/DashboardView.vue` | `driver._pusherTs = Date.now()` en `onDriverLocation` | Agregar |
| 2B | `delivery/frontend/src/composables/useRealtimeSync.js` | Merge inteligente en lugar de reemplazo total | Modificar |
| 3 | `delivery/frontend/src/services/maps/GoogleProvider.js` | `_animateMarker()` + usarlo en `updateMarker()` | Agregar + Modificar |
| 4 | `delivery/frontend/src/composables/useDashboardMap.js` | `flyTo` → `centerOn` | Modificar |

---

## Cómo verificar que el fix funciona

1. **Simulación:** Activar modo simulador en `panda_expess`. El pin de la motito en el admin dashboard debe moverse suavemente siguiendo la ruta, sin necesidad de refrescar.
2. **Producción:** Con un conductor en movimiento real, el pin debe actualizarse en el admin dashboard con retraso máximo de 10 segundos, deslizándose suavemente.
3. **Polling no rompe posición:** Verificar en DevTools → Network que las peticiones `GET /drivers` cada 5 s no causan saltos del pin al último valor de la DB.
4. **focusDriver:** Hacer clic en un conductor del FleetSidebar — el mapa debe centrarse sin errores en consola.

---

## Nota de backend (fuera de scope de este spec)

Para que el Bug 2 desaparezca completamente a largo plazo, el endpoint `POST /driver/location` en el backend PHP debe:
1. Actualizar las columnas `current_lat` y `current_lng` de la tabla `drivers`.
2. Emitir el evento Pusher `driver-location` en el canal `trips.{client_id}` con payload `{ driver_id, lat, lng }`.

Sin esto, el polling siempre devolverá la posición original de la DB y el merge del Bug 2B solo mitiga el problema durante los primeros 10 segundos.
