/**
 * MapService — Fachada única (Singleton) para el manejo de mapas.
 *
 * Es el único punto de acceso al mapa desde los componentes Vue.
 * Ningún componente debe importar GoogleProvider directamente.
 *
 * Patrón de diseño: Facade + Singleton
 * - Se exporta como instancia única (`export default new MapService()`),
 *   garantizando que toda la app comparte el mismo objeto de mapa.
 * - Delega todas las operaciones al proveedor activo (GoogleProvider).
 */
import GoogleProvider from './GoogleProvider';

class MapService {
  constructor() {
    this.provider = null;
  }

  /**
   * Inicializa el mapa en el contenedor indicado.
   * Crea el proveedor si aún no existe (lazy initialization).
   * @param {string} containerId - ID del div contenedor del mapa.
   * @param {object} options     - Opciones: center, zoom, etc.
   */
  initialize(containerId, options = {}) {
    if (!this.provider) {
      this.provider = new GoogleProvider();
    }
    
    return this.provider.initialize(containerId, options);
  }

  /**
   * Alias de initialize() para compatibilidad con el simulador de conductor.
   * @param {string} containerId - ID del div contenedor del mapa.
   * @param {object} options     - Opciones: center, zoom, etc.
   */
  initMap(containerId, options = {}) {
    return this.initialize(containerId, options);
  }

  /**
   * Carga el SDK de Google Maps sin inicializar ningún mapa.
   * Útil para componentes que solo necesitan la Places API
   * (autocompletado de direcciones) sin mostrar un mapa.
   */
  ensureSDKLoaded() {
    if (!this.provider) {
      this.provider = new GoogleProvider();
    }
    return this.provider._loadSDK();
  }

  addMarker(id, position, options = {}) {
    if (!this.provider) return null;
    return this.provider.addMarker(id, position, options);
  }

  updateMarker(id, position, options = {}) {
    if (!this.provider) return;
    return this.provider.updateMarker(id, position, options);
  }

  removeMarker(id) {
    if (!this.provider) return;
    return this.provider.removeMarker(id);
  }

  clearMarkers() {
    if (!this.provider) return;
    return this.provider.clearMarkers();
  }

  drawRoute(id, points, options = {}) {
    if (!this.provider) return Promise.resolve(null);
    return this.provider.drawRoute(id, points, options);
  }

  clearRoutes() {
    if (!this.provider) return;
    return this.provider.clearRoutes();
  }

  centerOn(position, zoom = 13) {
    if (!this.provider) return;
    return this.provider.centerOn(position, zoom);
  }

  /**
   * Ajusta el zoom y centro del mapa para que todos los puntos sean visibles.
   * Solo disponible en GoogleProvider. En otros proveedores es un no-op.
   * @param {Array} points - Array de coordenadas [{lat,lng}, ...].
   */
  fitToPoints(points) {
    if (typeof this.provider.fitToPoints === 'function') {
      return this.provider.fitToPoints(points);
    }
  }

  /**
   * Destruye el mapa y limpia todos los marcadores y rutas.
   * Llamar al desmontar el componente Vue que contiene el mapa.
   */
  destroy() {
    if (this.provider) {
      this.provider.destroy()
    }
  }

  /**
   * Retorna la instancia nativa del mapa (google.maps.Map o L.Map).
   * Usar solo cuando se necesita acceso directo a la API nativa.
   */
  getNativeMap() {
    return this.provider ? this.provider.map : null;
  }
}

// Exportamos una instancia única (Singleton) para toda la app
export default new MapService();
