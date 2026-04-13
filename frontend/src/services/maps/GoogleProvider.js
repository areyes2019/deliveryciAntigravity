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
      script.src = `https://maps.googleapis.com/maps/api/js?key=${this.apiKey}&libraries=geometry,places,drawing&loading=async&callback=__initGoogleMaps`;
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

    // Remove any existing marker to prevent ghost duplicates
    if (this.markers.has(id)) {
        this.markers.get(id).setMap(null);
        this.markers.delete(id);
    }

    const customIcon = this._normalizeIcon(options.icon);

    const marker = new google.maps.Marker({
        position: coords,
        map: this.map,
        icon: customIcon,
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

  updateMarker(id, position, options = {}) {
    if (!this.markers) return;
    const marker = this.markers.get(id);
    const coords = this._parsePosition(position);
    if (marker && coords) {
      marker.setPosition(coords);
      // Optionally update the icon if provided
      if (options.icon) {
          marker.setIcon(this._normalizeIcon(options.icon));
      }
    } else if (!marker && coords) {
      // Fallback: if marker doesn't exist, create it
      this.addMarker(id, coords, options);
    }
  }

  removeMarker(id) {
      if (!this.markers) return;
      const marker = this.markers.get(id);
      if (marker) {
          marker.setMap(null);
          this.markers.delete(id);
      }
  }

  clearMarkers() {
      if (this.markers) {
          this.markers.forEach(marker => {
              if (marker) marker.setMap(null);
          });
          this.markers.clear();
      }
  }

  clearRoutes() {
      if (this.routes) {
          this.routes.forEach(route => {
              if (route && route.setMap) route.setMap(null);
          });
          this.routes.clear();
      }
      // Destroy and reset renderer so it gets recreated fresh on next drawRoute
      if (this.directionsRenderer) {
          this.directionsRenderer.setMap(null);
          this.directionsRenderer = null;
      }
      this.directionsService = null;
  }

  drawRoute(id, points, options = {}) {
    return new Promise((resolve) => {
        if (!this.map || typeof google === 'undefined') { console.warn('No map instance'); return resolve(null); }
        const path = points.map(p => this._parsePosition(p)).filter(p => p !== null);
        console.log('🗺️ drawRoute path:', path);
        if (path.length < 2) { console.warn('Less than 2 valid points'); return resolve(null); }

        if (!this.directionsService) {
            this.directionsService = new google.maps.DirectionsService();
            this.directionsRenderer = new google.maps.DirectionsRenderer({
                map: this.map,
                suppressMarkers: true,
                polylineOptions: {
                    strokeColor: options.color || '#6366F1',
                    strokeWeight: 5
                }
            });
        }

        const origin = path[0];
        const destination = path[path.length - 1];
        console.log('📡 Calling Directions API:', origin, '->', destination);

        this.directionsService.route({
            origin,
            destination,
            travelMode: google.maps.TravelMode.DRIVING
        }, (result, status) => {
            console.log('📬 Directions API response status:', status);
            if (status === 'OK') {
                this.directionsRenderer.setDirections(result);
                if (options.fitBounds !== false) {
                    this.map.fitBounds(result.routes[0].bounds);
                }
                const routeLeg = result.routes[0].legs[0];
                this.routes.set(id, 'directions');
                resolve({ distance: routeLeg.distance.text, duration: routeLeg.duration.text });
            } else {
                console.warn(`⚠️ Directions API falló (${status}). Usando polyline directo como fallback.`);
                // Fallback: draw direct polyline
                const polyline = new google.maps.Polyline({
                    path, map: this.map,
                    strokeColor: options.color || '#6366F1',
                    strokeWeight: 5, strokeOpacity: 0.8, geodesic: true
                });
                this.routes.set(id, polyline);
                // Fit both points in view
                const bounds = new google.maps.LatLngBounds();
                path.forEach(p => bounds.extend(p));
                this.map.fitBounds(bounds);
                resolve(null);
            }
        });
    });
  }

  destroy() {
    this.clearMarkers()
    this.clearRoutes()
    this.map = null
  }

  centerOn(position, zoom = null) {
    if (!this.map || typeof google === 'undefined') return;
    const coords = this._parsePosition(position);
    if (coords) {
        this.map.setCenter(coords);
        if (zoom) this.map.setZoom(zoom);
    }
  }

  fitToPoints(points) {
    if (!this.map || typeof google === 'undefined') return;
    const bounds = new google.maps.LatLngBounds();
    points.forEach(p => {
        const c = this._parsePosition(p);
        if (c) bounds.extend(c);
    });
    this.map.fitBounds(bounds, 50); // 50px visual padding
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

  _normalizeIcon(icon) {
    if (!icon || typeof google === 'undefined') return null;

    if (typeof icon === 'object' && icon.url) {
      return {
        ...icon,
        scaledSize: icon.scaledSize
          ? new google.maps.Size(icon.scaledSize.width, icon.scaledSize.height)
          : undefined,
        anchor: icon.anchor
          ? new google.maps.Point(icon.anchor.x, icon.anchor.y)
          : undefined
      };
    }

    if (typeof icon !== 'string') return null;

    if (icon.startsWith('http') || icon.startsWith('data:image/')) {
      return icon;
    }

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"><text x="0" y="38" font-size="38">${icon}</text></svg>`;
    return {
      url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg),
      scaledSize: new google.maps.Size(48, 48),
      anchor: new google.maps.Point(24, 24)
    };
  }
}
