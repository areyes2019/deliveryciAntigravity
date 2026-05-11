<?php

namespace App\Services;

use Pusher\Pusher;

/**
 * Wrapper singleton del SDK de Pusher.
 *
 * Una sola instancia por proceso PHP. Los errores de red son no-fatales:
 * se registran en log pero nunca interrumpen el flujo de negocio.
 *
 * Convención de canales:
 *   trips.{client_id}           → cola de viajes por flota
 *   orders.{client_id}          → cambios de estado de órdenes
 *   driver.{driver_id}          → eventos individuales de conductor
 *   admin.{client_id}           → notificaciones de administrador
 */
class PusherService
{
    private static ?Pusher $client = null;

    private static function client(): Pusher
    {
        if (self::$client === null) {
            self::$client = new Pusher(
                env('PUSHER_APP_KEY'),
                env('PUSHER_APP_SECRET'),
                env('PUSHER_APP_ID'),
                [
                    'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
                    'useTLS'  => true,
                ]
            );
        }
        return self::$client;
    }

    /**
     * Emite un evento en un canal público.
     *
     * @param string $channel  Nombre del canal (ej: "trips.42")
     * @param string $event    Nombre del evento (ej: "trip-taken")
     * @param array  $data     Payload — mantener mínimo, sin datos sensibles
     */
    public static function trigger(string $channel, string $event, array $data): void
    {
        try {
            self::client()->trigger($channel, $event, $data);
        } catch (\Throwable $e) {
            log_message('error', '[PusherService] trigger failed — channel=' . $channel . ' event=' . $event . ' err=' . $e->getMessage());
        }
    }
}
