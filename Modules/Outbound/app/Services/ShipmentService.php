<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\ShipmentRepository;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Jobs\ProcessShipmentHandOverJob;
use Modules\Outbound\Jobs\ProcessShipmentPickupJob;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\ShipmentOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Support\InstantOrderClassifier;

class ShipmentService
{
    public function __construct(
        protected ShipmentRepository $shipmentRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->shipmentRepository->getAllPaginated($limit);
    }

    public function getByCourier(string $courierCode, int $limit = 10)
    {
        return $this->shipmentRepository->getByCourier($courierCode, $limit);
    }

    public function getCompleted(string $type, ?string $courierIds = null, int $limit = 10)
    {
        $courierCodes = ($courierIds && strtolower($courierIds) !== 'all')
            ? array_filter(array_map('trim', explode(',', $courierIds)))
            : [];

        return $this->shipmentRepository->getCompleted($type, $courierCodes, $limit);
    }

    public function getInstantAll(int $limit = 10)
    {
        return $this->shipmentRepository->getByType('INSTANT', $limit);
    }

    public function getById(string $id): ?Shipment
    {
        return $this->shipmentRepository->findById($id);
    }

    public function getOrdersPaginated(string $id, int $limit = 20)
    {
        return $this->shipmentRepository->getOrdersPaginated($id, $limit);
    }

    public function create(array $data): Shipment
    {
        $shipmentNo = !empty($data['shipment_no'])
            ? $data['shipment_no']
            : $this->shipmentRepository->generateShipmentNo();

        return $this->shipmentRepository->create([
            'shipment_no' => $shipmentNo,
            'location_id' => $data['location_id'],
            'courier_name' => $data['courier_name'] ?? null,
            'courier_code' => $data['courier_code'] ?? null,
            'shipment_type' => $data['shipment_type'],
            'shipment_date' => $data['shipment_date'],
            'status' => Shipment::STATUS_SCHEDULED,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'],
            'shipper_id' => $data['shipper_id'] ?? null,
        ]);
    }

    public function addOrders(string $shipmentId, array $orderIds): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if (!$shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Order hanya bisa ditambah ke shipment SCHEDULED (saat ini: {$shipment->status}).");
        }

        $orders = Order::whereIn('id', $orderIds)
            ->where('status', 'packed')
            ->whereNull('cancel_requested_at')
            ->get();

        if ($orders->count() !== count($orderIds)) {
            $rejectedIds = array_values(array_diff($orderIds, $orders->pluck('id')->all()));
            $rejected = Order::whereIn('id', $rejectedIds)
                ->pluck('salesorder_no')
                ->implode(', ');

            throw new \Exception(
                $rejected !== ''
                    ? "Order berikut dibatalkan atau bukan status 'packed' dan tidak bisa dimanifestkan: {$rejected}"
                    : "Sebagian order tidak ditemukan atau bukan status 'packed'."
            );
        }

        DB::transaction(function () use ($shipment, $orders) {
            foreach ($orders as $order) {
                $packlist = Packlist::where('order_id', $order->id)
                    ->where('status', Packlist::STATUS_COMPLETED)
                    ->first();

                $this->shipmentRepository->createOrder([
                    'shipment_id' => $shipment->id,
                    'order_id' => $order->id,
                    'packlist_id' => $packlist?->id,
                ]);
            }
        });

        ProcessShipmentPickupJob::dispatch($shipmentId, $orders->pluck('id')->toArray());

        return $this->shipmentRepository->findById($shipmentId);
    }

    public function removeOrders(string $shipmentId, array $orderIds): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if (!$shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Order hanya bisa dihapus dari shipment SCHEDULED (saat ini: {$shipment->status}).");
        }

        foreach ($orderIds as $orderId) {
            $this->shipmentRepository->removeOrder($shipmentId, $orderId);
        }

        return $this->shipmentRepository->findById($shipmentId);
    }

    public function handOver(string $id): Shipment
    {
        $shipment = $this->shipmentRepository->findById($id);

        if (!$shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Hanya shipment SCHEDULED yang bisa di-handover (saat ini: {$shipment->status}).");
        }

        if ($shipment->orders->isEmpty()) {
            throw new \Exception('Shipment tidak memiliki order. Tambahkan order terlebih dahulu.');
        }

        $this->shipmentRepository->update($id, [
            'status' => Shipment::STATUS_HANDED_OVER,
            'handed_over_at' => now(),
        ]);

        ProcessShipmentHandOverJob::dispatch($id);

        return $this->shipmentRepository->findById($id);
    }

    public function scanShipment(string $barcode): ?Shipment
    {
        $shipment = Shipment::where('shipment_no', $barcode)->first();

        if (!$shipment) {
            $shipmentOrder = ShipmentOrder::where('tracking_number', $barcode)->first();
            if ($shipmentOrder) {
                $shipment = $this->shipmentRepository->findById($shipmentOrder->shipment_id);
            }
        }

        if (!$shipment) {
            throw new \Exception("Shipment dengan barcode/nomor '{$barcode}' tidak ditemukan.");
        }

        return $this->shipmentRepository->findById($shipment->id);
    }

    public function updateTrackingNumber(string $shipmentId, string $orderId, string $trackingNumber): void
    {
        $shipmentOrder = ShipmentOrder::where('shipment_id', $shipmentId)
            ->where('order_id', $orderId)
            ->first();

        if (!$shipmentOrder) {
            throw new \Exception('Order tidak ditemukan dalam shipment ini.');
        }

        $shipmentOrder->update(['tracking_number' => $trackingNumber]);
    }

    public function createInstant(array $data): Shipment
    {
        $data['shipment_type'] = 'INSTANT';

        return $this->create($data);
    }

    public function updateHandoverQty(string $shipmentId, string $orderId, int $qtyGiven): void
    {
        $shipmentOrder = ShipmentOrder::where('shipment_id', $shipmentId)
            ->where('order_id', $orderId)
            ->first();

        if (!$shipmentOrder) {
            throw new \Exception('Order tidak ditemukan dalam shipment ini.');
        }

        $shipment = $this->shipmentRepository->findById($shipmentId);

        if (!$shipment || !in_array($shipment->status, [Shipment::STATUS_HANDED_OVER, Shipment::STATUS_SCHEDULED])) {
            throw new \Exception('Qty handover hanya bisa diupdate pada shipment SCHEDULED/HANDED_OVER.');
        }

        $shipmentOrder->update(['qty_given' => $qtyGiven]);
    }

    public function cancel(string $id): Shipment
    {
        $shipment = $this->shipmentRepository->findById($id);

        if (!$shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if (in_array($shipment->status, [Shipment::STATUS_HANDED_OVER, Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_DELIVERED])) {
            throw new \Exception('Shipment yang sudah di-handover tidak bisa di-cancel.');
        }

        $this->shipmentRepository->update($id, [
            'status' => Shipment::STATUS_CANCELLED,
        ]);

        return $this->shipmentRepository->findById($id);
    }

    public function delete(string $id): bool
    {
        $shipment = $this->shipmentRepository->findById($id);

        if (!$shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Hanya shipment SCHEDULED yang bisa dihapus (saat ini: {$shipment->status}).");
        }

        return $this->shipmentRepository->delete($id);
    }

    public function scanAndAddOrder(string $shipmentId, string $barcode): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if (! $shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception('Order hanya bisa ditambah ke shipment SCHEDULED.');
        }

        $order = Order::where('status', 'packed')
            ->where(function ($q) use ($barcode) {
                $q->where('salesorder_no', $barcode)
                  ->orWhere('tracking_number', $barcode);
            })
            ->first();

        if (! $order) {
            throw new \Exception("Pesanan '{$barcode}' tidak ditemukan atau belum packed.");
        }

        if ($order->cancel_requested_at !== null || $order->is_canceled) {
            throw new \Exception(
                "Pesanan {$order->salesorder_no} sedang dibatalkan — pisahkan paket fisik, jangan dimanifestkan."
            );
        }

        if (ShipmentOrder::where('order_id', $order->id)->exists()) {
            throw new \Exception("Pesanan {$order->salesorder_no} sudah ada di pengiriman lain.");
        }

        if ($shipment->courier_name && $order->shipping_provider) {
            $normalize = fn(string $s) => strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
            $sc = $normalize($shipment->courier_name);
            $oc = $normalize($order->shipping_provider);
            if (! str_contains($oc, $sc) && ! str_contains($sc, $oc)) {
                throw new \Exception(
                    "Kurir tidak sesuai. Pengiriman ini '{$shipment->courier_name}', "
                    . "pesanan menggunakan '{$order->shipping_provider}'."
                );
            }
        }

        if ($shipment->location_id && $order->location_id
            && $shipment->location_id !== $order->location_id) {
            throw new \Exception('Pesanan berasal dari lokasi berbeda.');
        }

        $packlist = Packlist::where('order_id', $order->id)
            ->where('status', Packlist::STATUS_COMPLETED)
            ->first();

        $this->shipmentRepository->createOrder([
            'shipment_id'     => $shipment->id,
            'order_id'        => $order->id,
            'packlist_id'     => $packlist?->id,
            'tracking_number' => $order->tracking_number,
        ]);

        if ($shipment->shipper_id === null && Auth::id() !== null) {
            $shipment->shipper_id = Auth::id();
            $shipment->save();
        }

        ProcessShipmentPickupJob::dispatch($shipmentId, [$order->id]);

        return $this->shipmentRepository->findById($shipmentId);
    }

    private const CHANNEL_TO_SHIPMENT_STATUS = [
        'IN_TRANSIT'         => Shipment::STATUS_IN_TRANSIT,
        'SHIPPED'            => Shipment::STATUS_IN_TRANSIT,
        'PARTIALLY_SHIPPING' => Shipment::STATUS_IN_TRANSIT,
        'TO_CONFIRM_RECEIVE' => Shipment::STATUS_DELIVERED,
        'DELIVERED'          => Shipment::STATUS_DELIVERED,
        'COMPLETED'          => Shipment::STATUS_DELIVERED,
    ];

    private const SHIPMENT_STATUS_RANK = [
        Shipment::STATUS_SCHEDULED   => 1,
        Shipment::STATUS_HANDED_OVER => 2,
        Shipment::STATUS_IN_TRANSIT  => 3,
        Shipment::STATUS_DELIVERED   => 4,
    ];

    public function syncFromChannelStatus(Order $order): ?Shipment
    {
        $targetStatus = self::CHANNEL_TO_SHIPMENT_STATUS[$order->channel_status] ?? null;
        if ($targetStatus === null) {
            return null;
        }

        $shipmentOrder = ShipmentOrder::where('order_id', $order->id)->first();
        if (! $shipmentOrder) {
            return null;
        }

        $shipment = $this->shipmentRepository->findById($shipmentOrder->shipment_id);
        if (! $shipment) {
            return null;
        }

        $currentRank = self::SHIPMENT_STATUS_RANK[$shipment->status] ?? 0;
        $targetRank = self::SHIPMENT_STATUS_RANK[$targetStatus] ?? 0;

        // Manifest SCHEDULED masih terbuka dan milik operator: hanya handOver() manual
        // yang boleh menutupnya, supaya resi lost-scan tetap bisa digabungkan.
        if ($currentRank < self::SHIPMENT_STATUS_RANK[Shipment::STATUS_HANDED_OVER]) {
            return $shipment;
        }

        if ($targetRank <= $currentRank) {
            return $shipment;
        }

        $update = ['status' => $targetStatus];
        if (empty($shipment->handed_over_at)) {
            $update['handed_over_at'] = now();
        }

        $this->shipmentRepository->update($shipment->id, $update);

        Log::info('Shipment status advanced from channel_status', [
            'shipment_id'    => $shipment->id,
            'order_id'       => $order->id,
            'channel_status' => $order->channel_status,
            'from'           => $shipment->status,
            'to'             => $targetStatus,
        ]);

        return $this->shipmentRepository->findById($shipment->id);
    }

    private const MANUAL_DRIVER_COURIER_REGEX = '/grab|gojek|gosend|gokilat|lalamove/i';
    private const BLOCKED_CHANNEL_SOURCES = ['shopee', 'tiktok'];

    public function recordDriverCall(string $id, array $data, ?UploadedFile $idCardPhoto = null): Shipment
    {
        $shipment = $this->findOrFail($id);
        $this->guardDriverCallEligible($shipment);

        $shipment->update([
            'driver_name'          => $data['driver_name'] ?? null,
            'driver_phone'         => $data['driver_phone'] ?? null,
            'driver_vehicle_plate' => $data['driver_vehicle_plate'] ?? null,
            'driver_booking_code'  => $data['driver_booking_code'] ?? null,
            'driver_call_method'   => Shipment::DRIVER_CALL_METHOD_MANUAL,
            'driver_call_status'   => Shipment::DRIVER_STATUS_CALLED,
            'driver_called_at'     => now(),
            'driver_called_by'     => $data['driver_called_by'] ?? auth()->user()?->email,
            'shipper_id'           => $data['shipper_id'] ?? null,
        ]);

        if ($idCardPhoto) {
            $shipment->clearMediaCollection('driver_id_card');
            $shipment->addMedia($idCardPhoto)->toMediaCollection('driver_id_card');
        }

        return $this->shipmentRepository->findById($id);
    }

    public function updateDriverCall(string $id, array $data, ?UploadedFile $idCardPhoto = null): Shipment
    {
        $shipment = $this->findOrFail($id);

        if ($shipment->driver_call_status === Shipment::DRIVER_STATUS_NONE) {
            throw new \Exception('Belum ada panggilan driver yang tercatat untuk shipment ini.');
        }

        $updates = array_filter([
            'driver_name'          => $data['driver_name'] ?? null,
            'driver_phone'         => $data['driver_phone'] ?? null,
            'driver_vehicle_plate' => $data['driver_vehicle_plate'] ?? null,
            'driver_booking_code'  => $data['driver_booking_code'] ?? null,
            'driver_call_status'   => $data['driver_call_status'] ?? null,
            'shipper_id'           => $data['shipper_id'] ?? null,
        ], fn ($v) => $v !== null);

        if (!empty($updates)) {
            $shipment->update($updates);
        }

        if ($idCardPhoto) {
            $shipment->clearMediaCollection('driver_id_card');
            $shipment->addMedia($idCardPhoto)->toMediaCollection('driver_id_card');
        }

        return $this->shipmentRepository->findById($id);
    }

    public function markDelivered(string $id): Shipment
    {
        $shipment = $this->findOrFail($id);

        if (!in_array($shipment->status, [Shipment::STATUS_HANDED_OVER, Shipment::STATUS_IN_TRANSIT])) {
            throw new \Exception("Hanya shipment HANDED_OVER atau IN_TRANSIT yang bisa ditandai DELIVERED (saat ini: {$shipment->status}).");
        }

        $this->shipmentRepository->update($id, [
            'status' => Shipment::STATUS_DELIVERED,
        ]);

        return $this->shipmentRepository->findById($id);
    }

    public function reconcile(string $id): array
    {
        $shipment = $this->findOrFail($id);

        if (!in_array($shipment->status, [Shipment::STATUS_HANDED_OVER, Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_DELIVERED])) {
            throw new \Exception('Reconcile hanya untuk shipment yang sudah di-handover.');
        }

        $shipment->load('orders.order');
        $summary = ['total' => 0, 'delivered' => 0, 'in_transit' => 0, 'anomaly' => 0, 'details' => []];

        foreach ($shipment->orders as $so) {
            $order = $so->order;
            if (!$order) continue;

            $summary['total']++;
            $status = $order->channel_status ?? $order->status;

            if (in_array($status, ['DELIVERED', 'COMPLETED', 'TO_CONFIRM_RECEIVE', 'done'])) {
                $summary['delivered']++;
                $category = 'delivered';
            } elseif (in_array($status, ['SHIPPED', 'IN_TRANSIT', 'PROCESSED', 'shipped'])) {
                $summary['in_transit']++;
                $category = 'in_transit';
            } else {
                $summary['anomaly']++;
                $category = 'anomaly';
            }

            $summary['details'][] = [
                'order_id'       => $order->id,
                'salesorder_no'  => $order->salesorder_no,
                'status'         => $order->status,
                'channel_status' => $order->channel_status,
                'category'       => $category,
            ];
        }

        if ($summary['total'] > 0 && $summary['delivered'] === $summary['total']
            && $shipment->status !== Shipment::STATUS_DELIVERED) {
            $this->shipmentRepository->update($id, [
                'status' => Shipment::STATUS_DELIVERED,
            ]);
            $summary['auto_marked_delivered'] = true;
        }

        return $summary;
    }

    private function findOrFail(string $id): Shipment
    {
        $shipment = $this->shipmentRepository->findById($id);

        if (!$shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        return $shipment;
    }

    private function guardDriverCallEligible(Shipment $shipment): void
    {
        if (!in_array($shipment->shipment_type, ['INSTANT', 'SAME_DAY'])) {
            throw new \Exception('Panggilan driver manual hanya untuk shipment tipe INSTANT atau SAME_DAY.');
        }

        $courierKey = $shipment->courier_name ?? $shipment->courier_code ?? '';
        if (!preg_match(self::MANUAL_DRIVER_COURIER_REGEX, $courierKey)) {
            throw new \Exception('Panggilan driver manual hanya untuk kurir Grab/GoSend/Lalamove. Shopee menggunakan auto-call.');
        }

        $shipment->loadMissing('orders.order');
        foreach ($shipment->orders as $so) {
            $source = strtolower($so->order->source ?? '');
            if (in_array($source, self::BLOCKED_CHANNEL_SOURCES)) {
                throw new \Exception(
                    "Pesanan {$so->order->salesorder_no} berasal dari {$source} — gunakan auto-call channel, bukan input manual."
                );
            }
        }
    }
}
