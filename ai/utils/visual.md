Analiza exclusivamente el archivo:

src\views\Dashboard.vue

Objetivo:

Generar una especificación visual AS-IS (estado actual) basada únicamente en la estructura visual observable dentro del código.

Restricciones:

- No propongas mejoras.
- No propongas cambios.
- No hagas refactorización.
- No hagas recomendaciones.
- No inventes elementos visuales.
- No asumas comportamientos no visibles en el código.
- No describas funcionalidades de negocio.
- No describas lógica interna salvo cuando afecte directamente la visualización.

Regla de evidencia:

Toda afirmación debe poder rastrearse directamente al código.

Si algún detalle visual no puede determinarse con certeza, documentarlo como:

NO DETERMINADO

Analizar únicamente:

- Estructura visual de la pantalla.
- Jerarquía de contenedores.
- Distribución de elementos.
- Componentes visibles.
- Regiones principales de la interfaz.
- Condiciones que alteran la composición visual.
- Clases CSS utilizadas.
- Layout responsive observable.
- Elementos fijos, flotantes o superpuestos.

Generar las siguientes secciones:

# 1. Descripción general de la pantalla

Resumen objetivo de la composición visual.

# 2. Estructura jerárquica

Representar la estructura visual mediante árbol:

Pantalla
├── Contenedor A
│   ├── Elemento
│   └── Elemento
└── Contenedor B

# 3. Regiones principales

Identificar áreas visuales:

- Header
- Sidebar
- Contenido principal
- Footer
- Mapa
- Panel flotante
- Bottom Sheet
- Modales

(Solo las que realmente existan)

# 4. Componentes visibles

Lista de componentes renderizados y su ubicación visual.

# 5. Capas visuales

Identificar elementos:

- Fijos
- Flotantes
- Superpuestos
- Absolutos
- Modales
- Overlays

# 6. Reglas de visibilidad

Qué elementos aparecen o desaparecen según condiciones detectadas.

# 7. Responsive observable

Documentar únicamente comportamientos responsive evidentes en:

- Tailwind
- CSS
- Media Queries
- Clases dinámicas

# 8. Clases visuales relevantes

Listar únicamente clases que afectan:

- Layout
- Posicionamiento
- Tamaño
- Espaciado
- Z-index
- Overflow
- Display

# 9. Estados visuales detectados

Variantes de la interfaz según condiciones observables.

Ejemplo:

- Sin pedido activo
- Pedido activo
- Modal abierto
- Bottom Sheet expandido
- Bottom Sheet colapsado

(Solo si existen en el código)

# 10. Esquema visual ASCII

Representar la pantalla mediante un diagrama textual aproximado.

Ejemplo:

┌──────────────────────┐
│ Header               │
├──────────────────────┤
│                      │
│       Mapa           │
│                      │
├──────────────────────┤
│ Bottom Sheet         │
└──────────────────────┘

# 11. Elementos NO DETERMINADOS

Todo detalle visual que no pueda inferirse con certeza desde el archivo.

Formato:

- Redacción técnica.
- Lenguaje objetivo.
- Sin opiniones.
- Sin recomendaciones.
- Sin código.
- Sin pseudocódigo.
- Basado exclusivamente en el archivo analizado.