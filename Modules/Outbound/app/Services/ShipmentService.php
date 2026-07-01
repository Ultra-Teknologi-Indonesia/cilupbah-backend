<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\ShipmentRepository;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Jobs\ProcessShipmentHandOverJob;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\ShipmentOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $courierCodes = $courierIds ? explode(',', $courierIds) : [];

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

        $orders = Order::whereIn('id', $orderIds)->where('status', 'packed')->get();

        if ($orders->isEmpty()) {
            throw new \Exception("Tidak ada order dengan status 'packed' yang ditemukan.");
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
            $shipmentOrder = \Modules\Outbound\Models\ShipmentOrder::where('tracking_number', $barcode)->first();
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
        $shipmentOrder = \Modules\Outbound\Models\ShipmentOrder::where('shipment_id', $shipmentId)
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

        return $this->shipmentRepository->findById($shipmentId);
    }

    private const CHANNEL_SHIPPED_STATUSES = [
        'AWAITING_COLLECTION', 'PROCESSED', 'PARTIALLY_SHIPPING',
        'IN_TRANSIT', 'SHIPPED', 'TO_CONFIRM_RECEIVE',
        'DELIVERED', 'COMPLETED',
    ];

    public function autoCreateForChannelOrder(Order $order): ?Shipment
    {
        if ($order->status !== 'packed') {
            return null;
        }

        if (! in_array($order->channel_status, self::CHANNEL_SHIPPED_STATUSES, true)) {
            return null;
        }

        if (empty($order->tracking_number) || empty($order->location_id)) {
            return null;
        }

        if (ShipmentOrder::where('order_id', $order->id)->exists()) {
            return null;
        }

        $shipment = $this->create([
            'location_id'   => $order->location_id,
            'courier_name'  => $order->shipping_provider ?? 'Marketplace',
            'courier_code'  => $order->shipping_provider
                ? strtolower(preg_replace('/\s+/', '-', $order->shipping_provider))
                : null,
            'shipment_type' => 'REGULAR',
            'shipment_date' => now()->toDateString(),
            'notes'         => 'Auto: channel ' . $order->channel_status,
            'created_by'    => 'SYSTEM',
        ]);

        $packlist = Packlist::where('order_id', $order->id)
            ->where('status', Packlist::STATUS_COMPLETED)
            ->first();

        $this->shipmentRepository->createOrder([
            'shipment_id'     => $shipment->id,
            'order_id'        => $order->id,
            'packlist_id'     => $packlist?->id,
            'tracking_number' => $order->tracking_number,
        ]);

        Log::info('Auto-created shipment for channel order', [
            'order_id'       => $order->id,
            'shipment_id'    => $shipment->id,
            'channel_status' => $order->channel_status,
        ]);

        return $shipment;
    }
}
