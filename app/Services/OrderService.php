<?php

namespace App\Services;

use App\Models\OrderModel;
use App\Models\OrderStatusLogModel;
use App\Models\ClientModel;
use App\Models\GeofenceModel;
use App\Helpers\GeoHelper;
use App\Services\PusherService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Servicio de órdenes de entrega.
 *
 * Orquesta todo el ciclo de vida de una orden: creación y cancelación.
 * Es el punto central de lógica de negocio que coordina precios, créditos
 * y auditoría de estados dentro de transacciones atómicas de base de datos.
 *
 * Dependencias internas:
 * - PricingService  : calcula el costo del viaje según el modo de tarifa del cliente.
 * - CreditService   : descuenta y devuelve créditos del cliente.
 * - OrderStatusLogModel : registra cada cambio de estado para auditoría.
 */
class OrderService
{
    private BaseConnection $db;
    private OrderModel $orderModel;
    private OrderStatusLogModel $statusLogModel;
    private CreditService $creditService;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->orderModel = new OrderModel();
        $this->statusLogModel = new OrderStatusLogModel();
        $this->creditService = new CreditService();
    }

    /**
     * Crea una nueva orden de entrega.
     *
     * Proceso completo (transacción atómica):
     *  1. Verifica que el cliente exista y tenga créditos suficientes.
     *  2. Calcula la distancia: usa `distance_km` del frontend (Google Maps)
     *     o Haversine como fallback si no se envió.
     *  3. Llama a PricingService para calcular el costo según el modo de tarifa.
     *  4. Determina `total_to_collect` según el tipo de pago:
     *     - prepaid          → el conductor no cobra nada (ya pagó con créditos)
     *     - cash_on_delivery → el conductor cobra solo el costo del servicio
     *     - cash_full        → el conductor cobra servicio + valor del producto
     *  5. Inserta la orden con estado `publicado`.
     *  6. Descuenta los créditos del cliente via CreditService.
     *  7. Registra el cambio de estado en order_status_log.
     *
     * Si cualquier paso falla, se hace rollback completo.
     *
     * @param  int   $clientId ID del cliente que crea la orden.
     * @param  array $data     Datos del formulario: coordenadas, direcciones, tipo de pago, etc.
     * @return array { status: bool, message: string, data?: array }
     */
    public function createOrder(int $clientId, array $data): array
    {
        $clientModel = new ClientModel();
        $client = $clientModel->find($clientId);

        if (!$client) {
            return ['status' => false, 'message' => 'Client not found'];
        }

        $costPerTrip = (float)($client['cost_per_trip'] ?? 1);
        if ($client['credits_balance'] < $costPerTrip) {
            $tripsAvailable = floor($client['credits_balance'] / ($costPerTrip ?: 1));
            return [
                'status' => false, 
                'message' => "Saldo insuficiente. Este viaje requiere {$costPerTrip} créditos. Tu saldo actual es de {$client['credits_balance']} créditos (aprox. {$tripsAvailable} viajes)."
            ];
        }

        // Prefer the real route distance sent by the frontend (Google Directions).
        // Fall back to haversine when it is absent (e.g. API calls without a map).
        if (!empty($data['distance_km']) && (float)$data['distance_km'] > 0) {
            $distanceKm = (float)$data['distance_km'];
            $distanceSource = 'route';
        } else {
            $distanceKm = GeoHelper::haversineDistance(
                ['lat' => (float)$data['pickup_lat'], 'lng' => (float)$data['pickup_lng']],
                ['lat' => (float)$data['drop_lat'],   'lng' => (float)$data['drop_lng']]
            ) / 1000.0;
            $distanceSource = 'haversine';
        }

        log_message('info', "[OrderService] distanceKm={$distanceKm} source={$distanceSource} pickup=({$data['pickup_lat']},{$data['pickup_lng']}) drop=({$data['drop_lat']},{$data['drop_lng']})");
        
        $geofenceResult = $this->validateGeofencePoints(
            $clientId,
            (float)$data['pickup_lat'], (float)$data['pickup_lng'],
            (float)$data['drop_lat'],   (float)$data['drop_lng']
        );
        if (!$geofenceResult['status']) {
            return ['status' => false, 'message' => $geofenceResult['message']];
        }

        $pricingService = new PricingService();
        $priceResult = $pricingService->calculatePrice($clientId, (float)$data['pickup_lat'], (float)$data['pickup_lng'], (float)$data['drop_lat'], (float)$data['drop_lng'], $distanceKm);

        if (!$priceResult['status']) {
            return ['status' => false, 'message' => $priceResult['message']];
        }

        $cost = $priceResult['price'];

        // ── Payment responsibility calculation ────────────────────────────────
        $paymentType = $data['payment_type'] ?? 'prepaid';
        $productAmount  = null;
        $totalToCollect = 0;

        switch ($paymentType) {
            case 'prepaid':
                // Sender already paid via credits — driver collects nothing
                $totalToCollect = 0;
                $productAmount  = null;
                break;

            case 'cash_on_delivery':
                // Receiver pays delivery fee in cash to driver
                $totalToCollect = $cost;
                $productAmount  = null;
                break;

            case 'cash_full':
                // Receiver pays delivery fee + product value in cash
                $productAmount  = (float)($data['product_amount'] ?? 0);
                $totalToCollect = $cost + $productAmount;
                break;
        }

        // Scheduled orders start as 'pendiente' until their time arrives.
        // Credits are deducted immediately to reserve funds.
        $scheduledAt    = !empty($data['scheduled_at']) ? $data['scheduled_at'] : null;
        $isScheduled    = $scheduledAt && strtotime($scheduledAt) > time();
        $initialStatus  = $isScheduled ? 'pendiente' : 'publicado';

        $this->db->transStart();

        $orderData = [
            'client_id'        => $clientId,
            'pickup_lat'       => $data['pickup_lat'],
            'pickup_lng'       => $data['pickup_lng'],
            'pickup_address'   => $data['pickup_address'],
            'drop_lat'         => $data['drop_lat'],
            'drop_lng'         => $data['drop_lng'],
            'drop_address'     => $data['drop_address'],
            'receiver_name'    => $data['receiver_name'] ?? null,
            'receiver_phone'   => $data['receiver_phone'] ?? null,
            'description'      => $data['description'] ?? null,
            'scheduled_at'     => $scheduledAt,
            'status'           => $initialStatus,
            'payment_type'     => $paymentType,
            'cost'             => $cost,
            'distance_km'      => $distanceKm,
            'product_amount'   => $productAmount,
            'total_to_collect' => $totalToCollect,
            'paid'             => 0,
        ];

        $orderId = $this->orderModel->insert($orderData);

        if (!$orderId) {
            $this->db->transRollback();
            return ['status' => false, 'message' => 'Failed to create order'];
        }

        $creditDeducted = $this->creditService->deductCredit($clientId, $orderId, 'Order created');

        if (!$creditDeducted) {
            $this->db->transRollback();
            return ['status' => false, 'message' => 'Error al descontar saldo: Créditos insuficientes para completar la transacción.'];
        }

        $this->statusLogModel->insert([
            'order_id'        => $orderId,
            'previous_status' => null,
            'new_status'      => $initialStatus,
        ]);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return ['status' => false, 'message' => 'Database transaction failed'];
        }

        $order = $this->orderModel->find($orderId);

        if ($initialStatus === 'publicado') {
            PusherService::trigger(
                'trips.' . $clientId,
                'new-trip',
                ['trip_id' => (int) $orderId]
            );
        }

        return ['status' => true, 'message' => 'Order created successfully', 'data' => $order];
    }

    /**
     * Cancela una orden existente y devuelve los créditos al cliente.
     *
     * Restricciones:
     * - Solo se pueden cancelar órdenes en estado `publicado`.
     *   Una vez que un conductor la tomó (`tomado`), ya no es cancelable desde aquí.
     * - Solo el cliente propietario de la orden puede cancelarla.
     *
     * Proceso (transacción atómica):
     *  1. Cambia el estado de la orden a `cancelado`.
     *  2. Devuelve los créditos al cliente via CreditService.
     *  3. Registra el cambio de estado en order_status_log.
     *
     * @param  int   $orderId  ID de la orden a cancelar.
     * @param  int   $clientId ID del cliente que solicita la cancelación.
     * @return array { status: bool, message: string }
     */
    public function cancelOrder(int $orderId, int $clientId): array
    {
        $order = $this->orderModel->find($orderId);

        if (!$order) {
            return ['status' => false, 'message' => 'Order not found'];
        }

        if ($order['client_id'] != $clientId) {
            return ['status' => false, 'message' => 'Unauthorized to cancel this order'];
        }

        if (!in_array($order['status'], ['publicado', 'tomado'])) {
            return ['status' => false, 'message' => 'Solo se pueden cancelar órdenes en estado publicado o tomado'];
        }

        $this->db->transStart();

        $updateData = ['status' => 'cancelado'];
        if ($order['status'] === 'tomado') {
            $updateData['driver_id'] = null;
        }
        $this->orderModel->update($orderId, $updateData);

        // Refund credit
        $refunded = $this->creditService->refundCredit($clientId, $orderId, 'Refund for cancelled order');

        if (!$refunded) {
            $this->db->transRollback();
            return ['status' => false, 'message' => 'Failed to refund credits'];
        }

        $this->statusLogModel->insert([
            'order_id'        => $orderId,
            'previous_status' => $order['status'],
            'new_status'      => 'cancelado'
        ]);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return ['status' => false, 'message' => 'Database transaction failed'];
        }

        return ['status' => true, 'message' => 'Order cancelled successfully'];
    }

    /**
     * Valida que pickup y drop estén dentro de la geocerca del cliente.
     * Si el cliente no tiene geocerca configurada, no hay restricción geográfica.
     */
    public function validateGeofencePoints(
        int   $clientId,
        float $pickupLat, float $pickupLng,
        float $dropLat,   float $dropLng
    ): array {
        $geofenceModel = new GeofenceModel();
        $geofence      = $geofenceModel->where('client_id', $clientId)->first();

        log_message('debug', "[GeofenceValidation] clientId={$clientId} geofence=" . ($geofence ? 'found' : 'none'));

        // Sin geocerca configurada = sin restricción geográfica
        if (!$geofence) {
            log_message('debug', '[GeofenceValidation] Sin geocerca → operación permitida.');
            return ['status' => true, 'message' => 'OK'];
        }

        $polygon = json_decode($geofence['polygon_coordinates'], true);

        if (!is_array($polygon) || count($polygon) < 3) {
            log_message('debug', '[GeofenceValidation] Polígono inválido → operación permitida.');
            return ['status' => true, 'message' => 'OK'];
        }

        $pickupInside = GeoHelper::isPointInPolygon(['lat' => $pickupLat, 'lng' => $pickupLng], $polygon);
        $dropInside   = GeoHelper::isPointInPolygon(['lat' => $dropLat,   'lng' => $dropLng],   $polygon);

        if (!$pickupInside) {
            return ['status' => false, 'message' => 'La dirección de recogida está fuera del área de operación.'];
        }
        if (!$dropInside) {
            return ['status' => false, 'message' => 'La dirección de entrega está fuera del área de operación.'];
        }

        return ['status' => true, 'message' => 'OK'];
    }

    /**
     * Publica las órdenes programadas cuya hora ya llegó.
     * Se llama en cada fetch de órdenes — no requiere cron.
     */
    public function publishDueOrders(): void
    {
        $now = date('Y-m-d H:i:s');

        $due = $this->orderModel
            ->where('status', 'pendiente')
            ->where('scheduled_at IS NOT NULL')
            ->where('scheduled_at <=', $now)
            ->findAll();

        foreach ($due as $order) {
            $this->db->transStart();

            $this->orderModel->update($order['id'], ['status' => 'publicado']);

            $this->statusLogModel->insert([
                'order_id'        => $order['id'],
                'previous_status' => 'pendiente',
                'new_status'      => 'publicado',
            ]);

            $this->db->transComplete();

            log_message('info', "[OrderService] Orden #{$order['id']} publicada automáticamente (scheduled_at={$order['scheduled_at']})");

            PusherService::trigger(
                'trips.' . $order['client_id'],
                'new-trip',
                ['trip_id' => (int) $order['id']]
            );
        }
    }
}
