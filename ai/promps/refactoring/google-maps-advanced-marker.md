# SPEC: Migración google.maps.Marker → AdvancedMarkerElement

## Objetivo

Eliminar el uso de `google.maps.Marker` (deprecado desde feb 2024) y reemplazarlo con
`google.maps.marker.AdvancedMarkerElement` en `GoogleProvider.js`.

---

## Contexto

`GoogleProvider.js` es el proveedor de mapas del sistema. Todos los componentes
que usan mapas (`ActivityFeed.vue`, `DashboardView.vue`) pasan por su interfaz pública.
La advertencia aparece en runtime porque `addMarker()` instancia `google.maps.Marker`.

**Archivo a modificar:** `frontend/src/services/maps/GoogleProvider.js`
**Archivos que NO se tocan:** `ActivityFeed.vue`, `DashboardView.vue`, `MapService.js`,
`useActivityFeed.js`, `useDashboardMap.js`.

La interfaz pública del provider (`addMarker`, `updateMarker`, `removeMarker`,
`clearMarkers`, `destroy`) **no cambia** — ningún caller necesita ajustes.

---

## Riesgos identificados

| # | Riesgo | Severidad |
|---|--------|-----------|
| 1 | `AdvancedMarkerElement` exige `mapId` en el mapa — sin él los marcadores no aparecen sin error claro | Crítica |
| 2 | SDK no incluye la librería `marker` — `google.maps.marker` es `undefined` en runtime | Crítica |
| 3 | `_animateMarker()` usa `marker.getPosition()` / `marker.setPosition()` — solo existen en `Marker` legado | Alta |
| 4 | `updateMarker()` llama `marker.setIcon()` — no existe en `AdvancedMarkerElement` | Alta |
| 5 | `clearMarkers()` / `removeMarker()` usan `marker.setMap(null)` — no existe en `AdvancedMarkerElement` | Alta |
| 6 | `_normalizeIcon()` devuelve strings/objetos de icono — `AdvancedMarkerElement` solo acepta `HTMLElement` en `content` | Alta |
| 7 | `infoWindow.open(this.map, marker)` — firma legada puede no funcionar con `AdvancedMarkerElement` | Media |

---

## Cambios requeridos

### 1 — Agregar librería `marker` al SDK

**Línea 58** — URL del script:

```js
// ANTES
`...&libraries=geometry,places&loading=async&callback=__initGoogleMaps`

// DESPUÉS
`...&libraries=geometry,places,marker&loading=async&callback=__initGoogleMaps`
```

---

### 2 — Agregar `mapId` a la inicialización del mapa

**Líneas 108-120** — `initialize()`:

```js
// ANTES
this.map = new google.maps.Map(mapElement, {
  center,
  zoom: options.zoom || 14,
  disableDefaultUI: true,
  zoomControl: true,
  styles: [...]
})

// DESPUÉS
this.map = new google.maps.Map(mapElement, {
  center,
  zoom: options.zoom || 14,
  mapId: import.meta.env.VITE_GOOGLE_MAPS_MAP_ID || 'DEMO_MAP_ID',
  disableDefaultUI: true,
  zoomControl: true,
  styles: [...]
})
```

> `DEMO_MAP_ID` es el ID especial de Google para testing — acepta `styles` y
> `AdvancedMarkerElement` simultáneamente. En producción usar `VITE_GOOGLE_MAPS_MAP_ID`.

---

### 3 — Agregar `_buildContent()` para construir el contenido del marcador

Nuevo método privado que reemplaza `_normalizeIcon()` para marcadores.
`AdvancedMarkerElement` usa `content: HTMLElement`, no `icon: string/object`.

```js
_buildContent(icon) {
  if (!icon) return null

  // Emoji o texto corto → div
  if (typeof icon === 'string' && !icon.startsWith('http') && !icon.startsWith('data:')) {
    const el = document.createElement('div')
    el.style.cssText = 'font-size:32px;line-height:1;cursor:pointer'
    el.textContent = icon
    return el
  }

  // URL de imagen → img
  if (typeof icon === 'string') {
    const img = document.createElement('img')
    img.src = icon
    img.style.cssText = 'width:32px;height:32px'
    return img
  }

  return null
}
```

> `_normalizeIcon()` se conserva sin cambios — lo usan rutas/polígonos, no marcadores.

---

### 4 — Reescribir `addMarker()`

**Líneas 128-162**

```js
// ANTES
const marker = new google.maps.Marker({
  position: coords, map: this.map, icon: customIcon,
  title: options.popup || '', animation: google.maps.Animation.DROP, zIndex: 999
})
this.markers.get(id).setMap(null)           // limpieza previa
infoWindow.open(this.map, marker)           // InfoWindow

// DESPUÉS
const marker = new google.maps.marker.AdvancedMarkerElement({
  position: coords,
  map: this.map,
  content: this._buildContent(options.icon),
  title: options.popup || '',
  zIndex: 999
})
this.markers.get(id).map = null             // limpieza previa
infoWindow.open({ map: this.map, anchor: marker })  // InfoWindow
```

> La animación `DROP` se elimina — no tiene equivalente en `AdvancedMarkerElement`.
> No hay regresión funcional, solo se pierde el efecto visual de caída.

---

### 5 — Reescribir `_animateMarker()`

**Líneas 164-202** — Reemplazar acceso a posición:

```js
// ANTES
const from = marker.getPosition()    // devuelve LatLng con .lat() .lng()
marker.setPosition({ lat, lng })

// DESPUÉS
const raw = marker.position          // devuelve LatLngLiteral | LatLng | null
const from = raw
  ? {
      lat: typeof raw.lat === 'function' ? raw.lat() : raw.lat,
      lng: typeof raw.lng === 'function' ? raw.lng() : raw.lng
    }
  : null
marker.position = { lat, lng }       // asignación directa
```

> El resto de la lógica (easing smoothstep, `requestAnimationFrame`,
> duración dinámica) no se modifica.

---

### 6 — Actualizar `updateMarker()`

**Líneas 204-216**

```js
// ANTES
marker.setIcon(this._normalizeIcon(options.icon))

// DESPUÉS
marker.content = this._buildContent(options.icon)
```

---

### 7 — Actualizar `removeMarker()` y `clearMarkers()`

**Líneas 218-234**

```js
// ANTES
marker.setMap(null)

// DESPUÉS
marker.map = null
```

---

## Variables de entorno

| Variable | Requerida | Valor dev | Descripción |
|---|---|---|---|
| `VITE_GOOGLE_MAPS_API_KEY` | Sí | (existente) | Sin cambios |
| `VITE_GOOGLE_MAPS_MAP_ID` | No | `DEMO_MAP_ID` | Map ID de Cloud Console para producción |

---

## Fuera de alcance

- No modificar `ActivityFeed.vue`.
- No modificar `DashboardView.vue`.
- No modificar `_normalizeIcon()` — sigue siendo usado por rutas y polígonos.
- No cambiar la interfaz pública del provider.
- No migrar `drawRoute()` ni `fitToPoints()` — no usan `Marker`.

---

## Criterios de aceptación

1. La advertencia `google.maps.Marker is deprecated` desaparece de la consola.
2. Los marcadores de pickup (verde), dropoff (rojo) y conductor (🏍️) se renderizan correctamente en el panel de detalle de `ActivityFeed`.
3. La animación suave de conductores sigue funcionando (el marcador se mueve interpolando entre coordenadas GPS).
4. Los marcadores se eliminan correctamente al cerrar el panel (`clearMarkers`, `destroy`).
5. Las InfoWindows aparecen al hacer click sobre los marcadores.
6. No hay errores en consola al abrir/cerrar el panel de detalle repetidamente.
