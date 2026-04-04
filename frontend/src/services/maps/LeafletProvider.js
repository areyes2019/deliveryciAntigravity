import L from 'leaflet';
import BaseProvider from './BaseProvider';

/**
 * LeafletProvider
 * Implementación real de la interfaz usando la librería Leaflet.
 */
export default class LeafletProvider extends BaseProvider {
  constructor() {
    super();
    this.defaultCoords = [20.5222, -100.8122]; // Celaya, GTO
    this.defaultZoom = 14;
  }

  /**
   * Inicializa el mapa en el contenedor especificado.
   */
  initialize(containerId, options = {}) {
    if (this.map) return this.map;

    const coords = options.center || this.defaultCoords;
    const zoom = options.zoom || this.defaultZoom;

    // Crear el mapa de Leaflet
    this.map = L.map(containerId, {
      zoomControl: false,
      attributionControl: false,
      ...options
    }).setView(coords, zoom);

    // Usar una capa de mapa "Premium" (CartoDB Light es gratuita y elegante)
    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
      maxZoom: 19
    }).addTo(this.map);

    // Añadir el control de zoom en una posición más limpia (abajo derecha)
    L.control.zoom({ position: 'bottomright' }).addTo(this.map);

    return this.map;
  }

  /**
   * Añade un marcador personalizado al mapa.
   */
  addMarker(id, position, options = {}) {
    const {lat, lng} = this._parsePosition(position);
    
    // Crear icono personalizado si se provee
    const markerOptions = {};
    if (options.icon) {
        markerOptions.icon = L.divIcon({
            className: 'custom-div-icon',
            html: `<div class="marker-pin ${options.className || ''}"><span>${options.icon}</span></div>`,
            iconSize: [30, 42],
            iconAnchor: [15, 42]
        });
    }

    const marker = L.marker([lat, lng], markerOptions).addTo(this.map);
    
    if (options.popup) {
      marker.bindPopup(options.popup);
    }

    this.markers.set(id, marker);
    return marker;
  }

  /**
   * Actualiza la posición de un marcador existente.
   */
  updateMarker(id, position) {
    const marker = this.markers.get(id);
    if (marker) {
      const {lat, lng} = this._parsePosition(position);
      marker.setLatLng([lat, lng]);
    }
  }

  removeMarker(id) {
    const marker = this.markers.get(id);
    if (marker) {
      this.map.removeLayer(marker);
      this.markers.delete(id);
    }
  }

  /**
   * Dibuja una línea (ruta) entre varios puntos.
   */
  drawRoute(id, points, options = {}) {
    const latLngs = points.map(p => this._parsePosition(p));
    const polyline = L.polyline(latLngs, {
      color: options.color || '#3B82F6',
      weight: options.weight || 4,
      opacity: 0.7,
      dashArray: options.dashed ? '10, 10' : null,
      lineCap: 'round'
    }).addTo(this.map);

    this.routes.set(id, polyline);
    return polyline;
  }

  clearRoutes() {
      this.routes.forEach(route => this.map.removeLayer(route));
      this.routes.clear();
  }

  centerOn(position, zoom = null) {
    const {lat, lng} = this._parsePosition(position);
    this.map.setView([lat, lng], zoom || this.map.getZoom());
  }

  // Helper para normalizar formatos de coordenadas
  _parsePosition(pos) {
    if (Array.isArray(pos)) return { lat: pos[0], lng: pos[1] };
    return { lat: pos.lat || pos.latitude, lng: pos.lng || pos.longitude };
  }
}
