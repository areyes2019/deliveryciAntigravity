import BaseProvider from './BaseProvider';

/**
 * GoogleProvider
 * Implementación definitiva y robusta para Google Maps.
 */
export default class GoogleProvider extends BaseProvider {
  constructor() {
    super();
    this.apiKey = import.meta.env.VITE_GOOGLE_MAPS_API_KEY;
    this.loaderPromise = null;
    this.defaultCoords = { lat: 20.5222, lng: -100.8122 }; // Celaya center
  }

  /**
   * Cargador simplificado y robusto.
   */
  async _loadSDK() {
    if (this.loaderPromise) return this.loaderPromise;

    this.loaderPromise = new Promise((resolve, reject) => {
      if (window.google && window.google.maps && window.google.maps.Map) {
        resolve(window.google);
        return;
      }

      // Interceptar el error de autenticación de Google Maps ANTES de cargar el script.
      // Esto evita el alert bloqueante "Esta página no puede cargar Google Maps".
      // Solución: agregar localhost a los referentes permitidos en Google Cloud Console.
      window.gm_authFailure = () => {
        console.warn('⚠️ Google Maps: Error de autenticación. Verifica las restricciones de la API Key en Google Cloud Console (agrega localhost:5173/* a los referentes permitidos).');
        // Resolvemos de todas formas para que Places Autocomplete siga funcionando
        if (window.google) resolve(window.google);
      };

      window.__initGoogleMaps = () => {
        console.log('✅ Google Maps SDK cargado correctamente.');
        resolve(window.google);
      };

      const script = document.createElement('script');
      script.src = `https://maps.googleapis.com/maps/api/js?key=${this.apiKey}&libraries=geometry,places&callback=__initGoogleMaps`;
      script.async = true;
      script.defer = true;
      script.onerror = (err) => {
        console.error('❌ Error fatal cargando Google Maps:', err);
        reject(err);
      };
      document.head.appendChild(script);
    });

    return this.loaderPromise;
  }

  async initialize(containerId, options = {}) {
    await this._loadSDK();

    if (this.map) return this.map;

    const mapElement = document.getElementById(containerId);
    if (!mapElement) throw new Error(`Contenedor #${containerId} no encontrado.`);

    // BLINDAJE ABSOLUTO: Si no hay coordenadas válidas, CARTO/CELAYA de hierro.
    let center = this.defaultCoords;
    if (options.center) {
        const parsed = this._parsePosition(options.center);
        if (parsed && !isNaN(parsed.lat)) {
            center = parsed;
        }
    }

    try {
        console.log('🛰️ Inicializando Google Maps en:', center);
        this.map = new google.maps.Map(mapElement, {
            center: center,
            zoom: options.zoom || 14,
            disableDefaultUI: true,
            zoomControl: true,
            // Estilo Silver Premium
            styles: [
                { "elementType": "geometry", "stylers": [{ "color": "#f5f5f5" }] },
                { "elementType": "labels.icon", "stylers": [{ "visibility": "off" }] },
                { "featureType": "road", "elementType": "geometry", "stylers": [{ "color": "#ffffff" }] },
                { "featureType": "water", "elementType": "geometry", "stylers": [{ "color": "#c9c9c9" }] }
            ]
        });
    } catch (error) {
        console.error('❌ Error interno de Google Maps:', error);
    }

    return this.map;
  }

  addMarker(id, position, options = {}) {
    if (!this.map || typeof google === 'undefined') return null;
    const coords = this._parsePosition(position);
    if (!coords) return null;

    // Marcador ESTÁNDAR para garantizar visibilidad
    const marker = new google.maps.Marker({
        position: coords,
        map: this.map,
        label: options.icon ? { 
            text: options.icon, 
            fontSize: '18px',
            color: 'white'
        } : null,
        title: options.popup || '',
        animation: google.maps.Animation.DROP,
        zIndex: 999
    });

    // Añadir InfoWindow si hay popup
    if (options.popup) {
        const infoWindow = new google.maps.InfoWindow({
            content: `<div style="color: #111827; padding: 5px;">${options.popup}</div>`
        });
        marker.addListener('click', () => {
            infoWindow.open(this.map, marker);
        });
    }

    this.markers.set(id, marker);
    return marker;
  }

  drawRoute(id, points, options = {}) {
    if (!this.map || typeof google === 'undefined') return null;
    const path = points.map(p => this._parsePosition(p)).filter(p => p !== null);
    if (path.length < 2) return null;

    const polyline = new google.maps.Polyline({
        path,
        map: this.map,
        strokeColor: options.color || '#6366F1',
        strokeWeight: 5
    });

    this.routes.set(id, polyline);
    return polyline;
  }

  centerOn(position, zoom = null) {
    if (!this.map || typeof google === 'undefined') return;
    const coords = this._parsePosition(position);
    if (coords) {
        this.map.setCenter(coords);
        if (zoom) this.map.setZoom(zoom);
    }
  }

  _parsePosition(pos) {
    try {
        if (!pos) return null;
        let lat, lng;
        if (Array.isArray(pos)) {
            lat = pos[0]; lng = pos[1];
        } else {
            lat = pos.lat || pos.latitude; lng = pos.lng || pos.longitude;
        }
        const cLat = parseFloat(lat);
        const cLng = parseFloat(lng);
        return (isNaN(cLat) || isNaN(cLng)) ? null : { lat: cLat, lng: cLng };
    } catch (e) { return null; }
  }
}
