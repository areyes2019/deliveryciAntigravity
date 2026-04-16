/**
 * BaseProvider — Interfaz (contrato) para proveedores de mapas.
 *
 * Define los métodos que CUALQUIER proveedor de mapas debe implementar.
 * Permite intercambiar GoogleProvider por LeafletProvider (u otro futuro)
 * sin modificar los componentes Vue que usan MapService.
 *
 * Patrón de diseño: Strategy
 * - Los componentes Vue solo conocen MapService (el contexto).
 * - MapService delega en el proveedor activo (la estrategia).
 * - BaseProvider define el contrato que toda estrategia debe cumplir.
 *
 * Si un proveedor no implementa algún método, lanza Error en tiempo
 * de ejecución al intentar usarlo.
 *
 * Estado compartido inicializado en el constructor:
 * - `map`     : instancia nativa del mapa (Google Map / Leaflet Map).
 * - `markers` : Map<id, marker> — registro de todos los pines activos.
 * - `routes`  : Map<id, route>  — registro de todas las rutas activas.
 */
export default class BaseProvider {
  constructor() {
    this.map = null;
    this.markers = new Map();
    this.routes = new Map();
  }

  /**
   * Inicializa el mapa dentro del div con el ID especificado.
   * @param {string} containerId - ID del elemento HTML contenedor.
   * @param {object} options     - Opciones: center {lat,lng}, zoom, etc.
   */
  initialize(_containerId, _options = {}) {
    throw new Error('Method "initialize" must be implemented');
  }

  /**
   * Agrega un marcador (pin) al mapa.
   * @param {string} id       - Identificador único del marcador.
   * @param {object} position - Coordenadas {lat, lng}.
   * @param {object} options  - Opciones: icon, popup, etc.
   */
  addMarker(_id, _position, _options = {}) {
    throw new Error('Method "addMarker" must be implemented');
  }

  /**
   * Mueve un marcador existente a una nueva posición.
   * @param {string} id       - ID del marcador a actualizar.
   * @param {object} position - Nuevas coordenadas {lat, lng}.
   */
  updateMarker(_id, _position) {
    throw new Error('Method "updateMarker" must be implemented');
  }

  /**
   * Elimina un marcador específico del mapa.
   * @param {string} id - ID del marcador a eliminar.
   */
  removeMarker(_id) {
    throw new Error('Method "removeMarker" must be implemented');
  }

  /**
   * Elimina todos los marcadores del mapa de una vez.
   */
  clearMarkers() {
    throw new Error('Method "clearMarkers" must be implemented');
  }

  /**
   * Dibuja una ruta (línea o direcciones) entre los puntos dados.
   * @param {string} id      - Identificador único de la ruta.
   * @param {Array}  points  - Array de coordenadas [{lat,lng}, ...].
   * @param {object} options - Opciones: color, weight, dashed, etc.
   */
  drawRoute(_id, _points, _options = {}) {
    throw new Error('Method "drawRoute" must be implemented');
  }

  /**
   * Elimina todas las rutas dibujadas en el mapa.
   */
  clearRoutes() {
    throw new Error('Method "clearRoutes" must be implemented');
  }

  /**
   * Centra el mapa en una posición con zoom opcional.
   * @param {object} position - Coordenadas {lat, lng}.
   * @param {number} zoom     - Nivel de zoom (por defecto 13).
   */
  centerOn(_position, _zoom = 13) {
    throw new Error('Method "centerOn" must be implemented');
  }
}
