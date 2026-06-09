<?php

namespace Modules\Outbound\Services;

use Modules\Outbound\Repositories\ShipmentRepository;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Jobs\ProcessShipmentHandOverJob;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Outbound\Models\Packlist;
use Illuminate\Support\Facades\DB;

class ShipmentService
{
    public function __construct(
        protected ShipmentRepository $shipmentRepository,
    ) {}

    public function getAllPaginated(int $limit = 10)
    {
        return $this->shipmentRepository->getAllPaginated($limit);
    }

    public function getById(string $id): ?Shipment
    {
        return $this->shipmentRepository->findById($id);
    }

    public function create(array $data): Shipment
    {
        $shipmentNo = $this->shipmentRepository->generateShipmentNo();

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
}
