/**
 * MapService.js
 * Facade principal de la aplicación.
 */
import GoogleProvider from './GoogleProvider';

class MapService {
  constructor() {
    this.provider = null;
  }

  /**
   * Inicializa el mapa con el proveedor configurado (Google Maps)
   */
  initialize(containerId, options = {}) {
    if (!this.provider) {
      this.provider = new GoogleProvider();
    }
    
    return this.provider.initialize(containerId, options);
  }

  /**
   * Alias for initialize (compatibility/simulator)
   */
  initMap(containerId, options = {}) {
    return this.initialize(containerId, options);
  }

  /**
   * Solo carga el SDK sin inicializar ningún mapa.
   * Útil para componentes que solo necesitan Places API.
   */
  ensureSDKLoaded() {
    if (!this.provider) {
      this.provider = new GoogleProvider();
    }
    return this.provider._loadSDK();
  }

  addMarker(id, position, options = {}) {
    return this.provider.addMarker(id, position, options);
  }

  updateMarker(id, position, options = {}) {
    return this.provider.updateMarker(id, position, options);
  }

  removeMarker(id) {
    return this.provider.removeMarker(id);
  }

  clearMarkers() {
    return this.provider.clearMarkers();
  }

  drawRoute(id, points, options = {}) {
    return this.provider.drawRoute(id, points, options);
  }

  clearRoutes() {
      return this.provider.clearRoutes();
  }

  centerOn(position, zoom = 13) {
    return this.provider.centerOn(position, zoom);
  }

  fitToPoints(points) {
    if (typeof this.provider.fitToPoints === 'function') {
      return this.provider.fitToPoints(points);
    }
  }

  getNativeMap() {
    return this.provider.map;
  }
}

// Exportamos una instancia única (Singleton) para toda la app
export default new MapService();
