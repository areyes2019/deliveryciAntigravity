/**
 * BaseProvider (Interface)
 * Define el contrato que deben seguir todos los proveedores de mapas.
 */
export default class BaseProvider {
  constructor() {
    this.map = null;
    this.markers = new Map();
    this.routes = new Map();
  }

  // Métodos que deben ser implementados por las subclases
  initialize(containerId, options = {}) {
    throw new Error('Method "initialize" must be implemented');
  }

  addMarker(id, position, options = {}) {
    throw new Error('Method "addMarker" must be implemented');
  }

  updateMarker(id, position) {
    throw new Error('Method "updateMarker" must be implemented');
  }

  removeMarker(id) {
    throw new Error('Method "removeMarker" must be implemented');
  }

  clearMarkers() {
    throw new Error('Method "clearMarkers" must be implemented');
  }

  drawRoute(id, points, options = {}) {
    throw new Error('Method "drawRoute" must be implemented');
  }

  clearRoutes() {
      throw new Error('Method "clearRoutes" must be implemented');
  }

  centerOn(position, zoom = 13) {
    throw new Error('Method "centerOn" must be implemented');
  }
}
