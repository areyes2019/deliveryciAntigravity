Actúa como desarrollador senior Vue 3 + JavaScript.

CONTEXTO:

El sistema alterna entre la vista de Mapa y la vista de ActivityFeed.
Cuando el usuario regresa al mapa, se llama `MapService.initialize()`.
El problema: `destroy()` no nullifica `this.provider`, por lo que
`initialize()` reutiliza una instancia ya destruida.

ARCHIVO A MODIFICAR:

frontend/src/services/maps/MapService.js

CÓDIGO ACTUAL (líneas 97-101):

```js
destroy() {
  if (this.provider) {
    this.provider.destroy()
  }
}
```

CAMBIO REQUERIDO:

```js
destroy() {
  if (this.provider) {
    this.provider.destroy()
    this.provider = null
  }
}
```

REQUISITOS:

- Modificar únicamente el método `destroy()`.
- No tocar ningún otro método.
- No cambiar la firma de ninguna función.
- No agregar lógica adicional.
- No modificar GoogleProvider.js.

VALIDACIÓN ESPERADA:

Después del cambio, alternar entre Mapa y ActivityFeed 10 veces
no debe dejar el mapa en blanco ni con estado corrupto.

Devuelve únicamente el método `destroy()` modificado con su contexto inmediato.
