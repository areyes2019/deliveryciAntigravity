import Pusher from 'pusher-js'

/**
 * Singleton Pusher para toda la app Vue.
 *
 * Una sola conexión WebSocket por sesión de navegador, sin importar cuántos
 * componentes llamen a subscribe(). Pusher reutiliza automáticamente la conexión
 * para múltiples canales suscritos desde la misma instancia.
 *
 * Convención de canales (debe coincidir con backend PusherService.php):
 *   trips.{client_id}      → cola de viajes por flota
 *   orders.{client_id}     → cambios de estado de órdenes
 *   driver.{driver_id}     → eventos individuales de conductor
 *   admin.{client_id}      → notificaciones de administrador
 *
 * Uso típico en un componente Vue:
 *   import { subscribe, unsubscribe } from '@/services/realtime'
 *
 *   onMounted(() => {
 *     const ch = subscribe('trips.42')
 *     ch.bind('trip-taken', ({ trip_id }) => { ... })
 *   })
 *
 *   onUnmounted(() => {
 *     unsubscribe('trips.42')
 *   })
 */

let instance = null

function getInstance() {
    if (!instance) {
        instance = new Pusher(import.meta.env.VITE_PUSHER_APP_KEY, {
            cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
        })
    }
    return instance
}

/**
 * Suscribe al canal. Retorna el objeto Channel de Pusher para llamar .bind().
 * Idempotente: si ya estás suscrito, Pusher devuelve el canal existente.
 */
export function subscribe(channelName) {
    return getInstance().subscribe(channelName)
}

/**
 * Cancela la suscripción al canal y libera sus listeners.
 * Llamar en onUnmounted del componente que hizo subscribe().
 */
export function unsubscribe(channelName) {
    if (instance) {
        instance.unsubscribe(channelName)
    }
}

/**
 * Desconecta el WebSocket y destruye la instancia singleton.
 * Solo llamar al hacer logout o al desmontar la app completa.
 */
export function disconnectRealtime() {
    if (instance) {
        instance.disconnect()
        instance = null
    }
}
