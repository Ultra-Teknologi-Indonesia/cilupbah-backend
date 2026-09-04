<?php

namespace Modules\Outbound\Services;

use App\Exceptions\UserFacingException;
use App\Support\ChannelWarehousePolicy;
use App\Support\WarehouseAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Outbound\Data\ShipmentScanResult;
use Modules\Outbound\Exceptions\OutboundValidationException;
use Modules\Outbound\Exceptions\ScanRejectedException;
use Modules\Outbound\Jobs\ProcessShipmentHandOverJob;
use Modules\Outbound\Jobs\ProcessShipmentPickupJob;
use Modules\Outbound\Jobs\RefreshInstantTrackingJob;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Repositories\ShipmentRepository;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Enums\OrderActivityEntity;
use Modules\Sales\Models\SalesOrder as Order;
use Modules\Sales\Services\SalesOrderService;
use Modules\Warehouse\Models\Location;

class ShipmentService
{
    public function __construct(
        protected ShipmentRepository $shipmentRepository,
        protected CourierMappingService $courierMapper,
        protected SalesOrderService $orderService,
        protected ChannelWarehousePolicy $channelWarehousePolicy,
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

    public function getForBulkManifestPdf(array $orderIds)
    {
        return $this->shipmentRepository->getForBulkManifestPdf($orderIds);
    }

    public function assertOrdersAccessibleForBulkManifest(array $orderIds): void
    {
        $this->shipmentRepository->assertOrdersAccessibleForBulkManifest($orderIds);
    }

    public function getTrackingEvents(string $shipmentId)
    {
        return $this->shipmentRepository->getTrackingEvents($shipmentId);
    }

    public function refreshTracking(string $shipmentId): void
    {
        try {
            RefreshInstantTrackingJob::dispatchSync($shipmentId);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function create(array $data): Shipment
    {
        $shipmentNo = ! empty($data['shipment_no'])
            ? $data['shipment_no']
            : $this->shipmentRepository->generateShipmentNo();

        $locationId = $data['location_id'] ?? $this->resolveDefaultLocationId();

        WarehouseAccess::assert($locationId);

        $courierName = $data['courier_name'] ?? null;
        $courierCode = $courierName
            ? ($this->courierMapper->resolveCode($courierName) ?: ($data['courier_code'] ?? null))
            : ($data['courier_code'] ?? null);

        return $this->shipmentRepository->create([
            'shipment_no' => $shipmentNo,
            'location_id' => $locationId,
            'courier_name' => $courierName,
            'courier_code' => $courierCode,
            'shipment_type' => $data['shipment_type'],
            'shipment_date' => $data['shipment_date'],
            'status' => Shipment::STATUS_SCHEDULED,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'],
            'shipper_id' => $data['shipper_id'] ?? null,
        ]);
    }

    public function addOrders(string $shipmentId, array $orderIds, bool $internalOnly = false): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if (! $shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Order hanya bisa ditambah ke shipment SCHEDULED (saat ini: {$shipment->status}).");
        }

        $ordersQuery = Order::whereIn('id', $orderIds)
            ->where('status', 'packed')
            ->where('is_canceled', false)
            ->whereNull('cancel_requested_at')
            ->where('location_id', $shipment->location_id);
        WarehouseAccess::apply($ordersQuery, 'location_id');
        $orders = $ordersQuery->get();

        if ($orders->count() !== count($orderIds)) {
            $rejectedIds = array_values(array_diff($orderIds, $orders->pluck('id')->all()));
            $rejectedQuery = Order::whereIn('id', $rejectedIds)
                ->where('location_id', $shipment->location_id);
            WarehouseAccess::apply($rejectedQuery, 'location_id');
            $rejected = $rejectedQuery
                ->pluck('salesorder_no')
                ->implode(', ');

            throw new \Exception(
                $rejected !== ''
                    ? "Order berikut dibatalkan atau bukan status 'packed' dan tidak bisa dimanifestkan: {$rejected}"
                    : "Sebagian order tidak ditemukan atau bukan status 'packed'."
            );
        }

        if ($internalOnly) {
            $externalOrders = $orders->filter(
                fn (Order $order): bool => ! $this->isInternalManualOrder($order),
            );

            if ($externalOrders->isNotEmpty()) {
                $externalOrderNumbers = $externalOrders->pluck('salesorder_no')->implode(', ');

                throw new OutboundValidationException(
                    'Buat Pengiriman ini hanya untuk pesanan internal/manual. '
                    ."Pesanan channel tidak dapat dimasukkan: {$externalOrderNumbers}"
                );
            }
        }

        $this->assertOrdersCompatibleWithShipment($shipment, $orders);

        $addedOrderIds = DB::transaction(function () use ($shipment, $orders): array {
            $shipmentQuery = Shipment::query()
                ->whereKey($shipment->id)
                ->lockForUpdate();
            WarehouseAccess::apply($shipmentQuery, 'location_id');
            $lockedShipment = $shipmentQuery->first();

            if (! $lockedShipment || $lockedShipment->status !== Shipment::STATUS_SCHEDULED) {
                throw new OutboundValidationException('Pengiriman sudah tidak tersedia untuk dijadwalkan.');
            }

            $lockedOrders = Order::query()
                ->whereIn('id', $orders->pluck('id')->all())
                ->where('location_id', $lockedShipment->location_id)
                ->lockForUpdate()
                ->get();

            $invalidOrder = $lockedOrders->first(
                fn (Order $order): bool => $order->status !== 'packed'
                    || $order->is_canceled
                    || $order->cancel_requested_at !== null,
            );

            if ($lockedOrders->count() !== $orders->count() || $invalidOrder) {
                $invalidOrderNumber = $invalidOrder?->salesorder_no;

                throw new OutboundValidationException(
                    $invalidOrderNumber
                        ? "Pesanan {$invalidOrderNumber} sudah tidak berstatus packed atau telah dibatalkan."
                        : 'Sebagian pesanan sudah tidak tersedia untuk dijadwalkan.'
                );
            }

            $this->assertOrdersCompatibleWithShipment($lockedShipment, $lockedOrders);

            $packlistIdByOrder = Packlist::whereIn('order_id', $lockedOrders->pluck('id'))
                ->where('status', Packlist::STATUS_COMPLETED)
                ->pluck('id', 'order_id');

            $addedOrderIds = [];
            $existingRelations = ShipmentOrder::query()
                ->whereIn('order_id', $lockedOrders->pluck('id')->all())
                ->lockForUpdate()
                ->get(['shipment_id', 'order_id']);

            $foreignOrderIds = $existingRelations
                ->where('shipment_id', '!=', $lockedShipment->id)
                ->pluck('order_id')
                ->map(fn ($orderId) => (string) $orderId)
                ->values();

            if ($foreignOrderIds->isNotEmpty()) {
                $foreignOrders = $lockedOrders
                    ->whereIn('id', $foreignOrderIds)
                    ->pluck('salesorder_no')
                    ->implode(', ');

                throw new \Exception(
                    "Order berikut sudah berada di pengiriman lain dan tidak bisa dimanifestkan ulang: {$foreignOrders}"
                );
            }

            $existingOrderIds = $existingRelations
                ->where('shipment_id', $lockedShipment->id)
                ->pluck('order_id')
                ->map(fn ($orderId) => (string) $orderId)
                ->flip();

            foreach ($lockedOrders as $order) {
                if ($existingOrderIds->has((string) $order->id)) {
                    continue;
                }

                $this->shipmentRepository->createOrder([
                    'shipment_id' => $lockedShipment->id,
                    'order_id' => $order->id,
                    'packlist_id' => $packlistIdByOrder[$order->id] ?? null,
                ]);

                $this->logShipmentActivity(
                    $order,
                    $lockedShipment,
                    OrderActivityAction::ADDED_TO_SHIPMENT,
                    "Pesanan dimasukkan ke pengiriman {$lockedShipment->shipment_no}",
                    ['shipment_no' => $lockedShipment->shipment_no],
                );
                $addedOrderIds[] = $order->id;
            }

            return $addedOrderIds;
        });

        if ($addedOrderIds !== []) {
            ProcessShipmentPickupJob::dispatch($shipmentId, $addedOrderIds);
        }

        return $this->shipmentRepository->findById($shipmentId);
    }

    private function assertOrdersCompatibleWithShipment(Shipment $shipment, Collection $orders): void
    {
        foreach ($orders as $order) {
            try {
                $this->channelWarehousePolicy->assertOrderAndTargetLocation(
                    $order->source,
                    $order->location_id,
                    $shipment->location_id,
                    'Penjadwalan shipment',
                );
            } catch (UserFacingException $exception) {
                throw new OutboundValidationException($exception->getMessage(), 422, $exception);
            }
        }

        $shipmentIsInstant = in_array($shipment->shipment_type, ['INSTANT', 'SAME_DAY'], true);
        $mismatchedType = $orders->first(function (Order $order) use ($shipmentIsInstant): bool {
            return $order->is_instant
                !== $shipmentIsInstant;
        });

        if ($mismatchedType) {
            $expected = $shipmentIsInstant ? 'instant/same-day' : 'reguler';

            throw new OutboundValidationException(
                "Pesanan {$mismatchedType->salesorder_no} tidak sesuai dengan pengiriman {$expected}."
            );
        }

        $mismatchedLocation = $orders->first(
            fn (Order $order): bool => $shipment->location_id !== null
                && $order->location_id !== null
                && (string) $shipment->location_id !== (string) $order->location_id,
        );

        if ($mismatchedLocation) {
            throw new OutboundValidationException(
                "Pesanan {$mismatchedLocation->salesorder_no} berasal dari lokasi berbeda dengan pengiriman."
            );
        }

        if ($shipment->courier_name || $shipment->courier_code) {
            $shipmentCourierCode = $this->courierMapper->resolveCode((string) $shipment->courier_name)
                ?: (string) ($shipment->courier_code ?? '');

            $mismatchedCourier = $orders->first(function (Order $order) use ($shipmentCourierCode): bool {
                if ($shipmentCourierCode === '' || ! $order->shipping_provider) {
                    return false;
                }

                $orderCourierCode = $this->courierMapper->resolveCode((string) $order->shipping_provider);

                return $orderCourierCode !== '' && $orderCourierCode !== $shipmentCourierCode;
            });

            if ($mismatchedCourier) {
                throw new OutboundValidationException(
                    "Kurir pesanan {$mismatchedCourier->salesorder_no} tidak sesuai dengan pengiriman."
                );
            }
        }
    }

    public function removeOrders(string $shipmentId, array $orderIds): Shipment
    {
        $shipment = $this->shipmentRepository->findById($shipmentId);

        if (! $shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Order hanya bisa dihapus dari shipment SCHEDULED (saat ini: {$shipment->status}).");
        }

        DB::transaction(function () use ($shipment, $shipmentId, $orderIds): void {
            $shipmentOrders = ShipmentOrder::with('order')
                ->where('shipment_id', $shipmentId)
                ->whereIn('order_id', $orderIds)
                ->get();

            $this->shipmentRepository->removeOrders($shipmentId, $orderIds);

            foreach ($shipmentOrders as $shipmentOrder) {
                if ($shipmentOrder->order) {
                    $this->logShipmentActivity(
                        $shipmentOrder->order,
                        $shipment,
                        OrderActivityAction::REMOVED_FROM_SHIPMENT,
                        "Pesanan dikeluarkan dari pengiriman {$shipment->shipment_no}",
                        ['shipment_no' => $shipment->shipment_no],
                    );
                }
            }
        });

        return $this->shipmentRepository->findById($shipmentId);
    }

    public function handOver(string $id): Shipment
    {
        $shipment = $this->shipmentRepository->findById($id);

        if (! $shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Hanya shipment SCHEDULED yang bisa di-handover (saat ini: {$shipment->status}).");
        }

        if ($shipment->orders->isEmpty()) {
            throw new \Exception('Shipment tidak memiliki order. Tambahkan order terlebih dahulu.');
        }

        $shipment = DB::transaction(function () use ($id): Shipment {
            $shipmentQuery = Shipment::with('orders.order')->whereKey($id);
            WarehouseAccess::apply($shipmentQuery, 'location_id');
            $shipment = $shipmentQuery->lockForUpdate()->first();

            if (! $shipment) {
                throw new \Exception('Shipment tidak ditemukan.');
            }

            if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
                throw new \Exception("Hanya shipment SCHEDULED yang bisa di-handover (saat ini: {$shipment->status}).");
            }

            if ($shipment->orders->isEmpty()) {
                throw new \Exception('Shipment tidak memiliki order. Tambahkan order terlebih dahulu.');
            }

            $handedOverAt = now();
            $this->shipmentRepository->update($id, [
                'status' => Shipment::STATUS_HANDED_OVER,
                'handed_over_at' => $handedOverAt,
            ]);

            foreach ($shipment->orders as $shipmentOrder) {
                if ($shipmentOrder->order) {
                    $this->logShipmentActivity(
                        $shipmentOrder->order,
                        $shipment,
                        OrderActivityAction::SHIPMENT_HANDED_OVER,
                        "Pengiriman {$shipment->shipment_no} diserahkan ke kurir",
                        [
                            'shipment_no' => $shipment->shipment_no,
                            'handed_over_at' => $handedOverAt->toIso8601String(),
                        ],
                    );
                }
            }

            return $shipment;
        });

        ProcessShipmentHandOverJob::dispatch($id);

        $shipment->loadMissing('orders.order');
        foreach ($shipment->orders as $so) {
            if ($so->order) {
                $this->syncFromChannelStatus($so->order);
            }
        }

        return $this->shipmentRepository->findById($id);
    }

    public function scanShipment(string $barcode): ?Shipment
    {
        $shipmentQuery = Shipment::where('shipment_no', $barcode);
        WarehouseAccess::apply($shipmentQuery, 'location_id');
        $shipment = $shipmentQuery->first();

        if (! $shipment) {
            $shipmentOrder = ShipmentOrder::where('tracking_number', $barcode)->first();
            if ($shipmentOrder) {
                $shipment = $this->shipmentRepository->findById($shipmentOrder->shipment_id);
            }
        }

        if (! $shipment) {
            throw new \Exception("Shipment dengan barcode/nomor '{$barcode}' tidak ditemukan.");
        }

        return $this->shipmentRepository->findById($shipment->id);
    }

    public function updateTrackingNumber(string $shipmentId, string $orderId, string $trackingNumber): void
    {
        $shipmentOrder = ShipmentOrder::where('shipment_id', $shipmentId)
            ->where('order_id', $orderId)
            ->whereHas('shipment', fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->first();

        if (! $shipmentOrder) {
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
            ->whereHas('shipment', fn ($query) => WarehouseAccess::apply($query, 'location_id'))
            ->first();

        if (! $shipmentOrder) {
            throw new \Exception('Order tidak ditemukan dalam shipment ini.');
        }

        $shipment = $this->shipmentRepository->findById($shipmentId);

        if (! $shipment || ! in_array($shipment->status, [Shipment::STATUS_HANDED_OVER, Shipment::STATUS_SCHEDULED])) {
            throw new \Exception('Qty handover hanya bisa diupdate pada shipment SCHEDULED/HANDED_OVER.');
        }

        $shipmentOrder->update(['qty_given' => $qtyGiven]);
    }

    public function cancel(string $id): Shipment
    {
        $shipment = $this->shipmentRepository->findById($id);

        if (! $shipment) {
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

        if (! $shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
            throw new \Exception("Hanya shipment SCHEDULED yang bisa dihapus (saat ini: {$shipment->status}).");
        }

        return $this->shipmentRepository->delete($id);
    }

    public function scanAndAddOrder(string $shipmentId, string $barcode): ShipmentScanResult
    {
        $barcode = trim($barcode);

        $result = DB::transaction(function () use ($shipmentId, $barcode): ShipmentScanResult {
            $shipmentQuery = Shipment::query()->lockForUpdate();
            WarehouseAccess::apply($shipmentQuery, 'location_id');
            $shipment = $shipmentQuery->find($shipmentId);

            if (! $shipment) {
                throw new \Exception('Shipment tidak ditemukan.');
            }

            if ($shipment->status !== Shipment::STATUS_SCHEDULED) {
                throw new \Exception('Order hanya bisa ditambah ke shipment SCHEDULED.');
            }

            $order = Order::query()
                ->where('status', 'packed')
                ->where('location_id', $shipment->location_id)
                ->where(function ($q) use ($barcode) {
                    $q->where('salesorder_no', $barcode)
                        ->orWhere('tracking_number', $barcode);
                })
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new ScanRejectedException(
                    'not_found',
                    "Pesanan '{$barcode}' tidak ditemukan atau belum packed."
                );
            }

            $existing = ShipmentOrder::query()
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->shipment_id === $shipment->id) {
                    return new ShipmentScanResult(
                        $shipment,
                        $this->loadShipmentOrderForResponse($existing),
                        true,
                        $barcode,
                    );
                }

                throw new ScanRejectedException(
                    'duplicate',
                    "Pesanan {$order->salesorder_no} sudah ada di pengiriman lain."
                );
            }

            if ($order->is_canceled) {
                throw new ScanRejectedException(
                    'order_canceled',
                    "Pesanan {$order->salesorder_no} sudah DIBATALKAN — pisahkan paket fisik, jangan dimanifestkan."
                );
            }

            if ($order->cancel_requested_at !== null) {
                throw new ScanRejectedException(
                    'order_cancel_requested',
                    "Pesanan {$order->salesorder_no} sedang MINTA BATAL (req cancel) — cek dulu sebelum dimanifestkan."
                );
            }

            if ($shipment->courier_name && $order->shipping_provider) {
                $shipmentCode = $this->courierMapper->resolveCode($shipment->courier_name);
                $orderCode = $this->courierMapper->resolveCode($order->shipping_provider);

                if ($shipmentCode !== '' && $orderCode !== '' && $shipmentCode !== $orderCode) {
                    throw new ScanRejectedException(
                        'courier_mismatch',
                        "Kurir tidak sesuai. Pengiriman ini '{$shipment->courier_name}', "
                            ."pesanan menggunakan '{$order->shipping_provider}'."
                    );
                }
            }

            $orderIsInstant = $order->is_instant;
            $shipmentIsInstant = in_array($shipment->shipment_type, ['INSTANT', 'SAME_DAY'], true);
            if ($orderIsInstant !== $shipmentIsInstant) {
                throw new ScanRejectedException(
                    'shipment_type_mismatch',
                    $orderIsInstant
                        ? "Pesanan {$order->salesorder_no} adalah kurir INSTAN (panggil driver), "
                            ."tidak bisa masuk manifest reguler '{$shipment->shipment_type}'."
                        : "Pesanan {$order->salesorder_no} adalah kurir reguler, "
                            ."tidak bisa masuk manifest instan '{$shipment->shipment_type}'."
                );
            }

            if ($shipment->location_id && $order->location_id
                && $shipment->location_id !== $order->location_id) {
                throw new ScanRejectedException('location_mismatch', 'Pesanan berasal dari lokasi berbeda.');
            }

            $packlist = Packlist::query()
                ->where('order_id', $order->id)
                ->where('status', Packlist::STATUS_COMPLETED)
                ->first();

            $shipmentOrder = $this->shipmentRepository->createOrder([
                'shipment_id' => $shipment->id,
                'order_id' => $order->id,
                'packlist_id' => $packlist?->id,
                'tracking_number' => $order->tracking_number,
            ]);

            if ($shipment->shipper_id === null && Auth::id() !== null) {
                $shipment->shipper_id = Auth::id();
                $shipment->save();
            }

            $this->logShipmentActivity(
                $order,
                $shipment,
                OrderActivityAction::ADDED_TO_SHIPMENT,
                "Pesanan dimasukkan ke pengiriman {$shipment->shipment_no}",
                ['shipment_no' => $shipment->shipment_no],
            );

            return new ShipmentScanResult(
                $shipment,
                $this->loadShipmentOrderForResponse($shipmentOrder),
                false,
                $barcode,
            );
        }, 3);

        if (! $result->alreadyAdded) {
            ProcessShipmentPickupJob::dispatch($shipmentId, [$result->shipmentOrder->order_id]);
        }

        return $result;
    }

    private function loadShipmentOrderForResponse(ShipmentOrder $shipmentOrder): ShipmentOrder
    {
        return $shipmentOrder->load([
            'order:id,salesorder_no,customer_name,status,grand_total,shipping_provider,tracking_number,source,channel_order_no,order_weight_gram,channel_status',
            'packlist:id,packlist_no',
        ]);
    }

    private function logShipmentActivity(
        Order $order,
        Shipment $shipment,
        OrderActivityAction $action,
        string $note,
        array $newValues = [],
        array $prevValues = [],
    ): void {
        $metadata = [
            'entity_no' => $shipment->shipment_no,
            'shipment_id' => $shipment->id,
            'shipment_no' => $shipment->shipment_no,
            'note' => $note,
        ];

        if ($newValues !== []) {
            $metadata['new_values'] = $newValues;
        }
        if ($prevValues !== []) {
            $metadata['prev_values'] = $prevValues;
        }

        $this->orderService->logStatusHistory(
            $order,
            $action,
            $metadata,
            auth()->user(),
            OrderActivityEntity::ORDER,
            $shipment->id,
        );
    }

    private const CHANNEL_TO_SHIPMENT_STATUS = [
        'IN_TRANSIT' => Shipment::STATUS_IN_TRANSIT,
        'SHIPPED' => Shipment::STATUS_IN_TRANSIT,
        'PARTIALLY_SHIPPING' => Shipment::STATUS_IN_TRANSIT,
        'TO_CONFIRM_RECEIVE' => Shipment::STATUS_DELIVERED,
        'DELIVERED' => Shipment::STATUS_DELIVERED,
        'COMPLETED' => Shipment::STATUS_DELIVERED,
    ];

    private const SHIPMENT_STATUS_RANK = [
        Shipment::STATUS_SCHEDULED => 1,
        Shipment::STATUS_HANDED_OVER => 2,
        Shipment::STATUS_IN_TRANSIT => 3,
        Shipment::STATUS_DELIVERED => 4,
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
            'shipment_id' => $shipment->id,
            'order_id' => $order->id,
            'channel_status' => $order->channel_status,
            'from' => $shipment->status,
            'to' => $targetStatus,
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
            'driver_name' => $data['driver_name'] ?? null,
            'driver_phone' => $data['driver_phone'] ?? null,
            'driver_vehicle_plate' => $data['driver_vehicle_plate'] ?? null,
            'driver_booking_code' => $data['driver_booking_code'] ?? null,
            'driver_call_method' => Shipment::DRIVER_CALL_METHOD_MANUAL,
            'driver_call_status' => Shipment::DRIVER_STATUS_CALLED,
            'driver_called_at' => now(),
            'driver_called_by' => $data['driver_called_by'] ?? auth()->user()?->email,
            'shipper_id' => $data['shipper_id'] ?? null,
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
            'driver_name' => $data['driver_name'] ?? null,
            'driver_phone' => $data['driver_phone'] ?? null,
            'driver_vehicle_plate' => $data['driver_vehicle_plate'] ?? null,
            'driver_booking_code' => $data['driver_booking_code'] ?? null,
            'driver_call_status' => $data['driver_call_status'] ?? null,
            'shipper_id' => $data['shipper_id'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($updates)) {
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

        if (! in_array($shipment->status, [Shipment::STATUS_HANDED_OVER, Shipment::STATUS_IN_TRANSIT])) {
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

        if (! in_array($shipment->status, [Shipment::STATUS_HANDED_OVER, Shipment::STATUS_IN_TRANSIT, Shipment::STATUS_DELIVERED])) {
            throw new \Exception('Reconcile hanya untuk shipment yang sudah di-handover.');
        }

        $shipment->load('orders.order');
        $summary = ['total' => 0, 'delivered' => 0, 'in_transit' => 0, 'anomaly' => 0, 'details' => []];

        foreach ($shipment->orders as $so) {
            $order = $so->order;
            if (! $order) {
                continue;
            }

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
                'order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'status' => $order->status,
                'channel_status' => $order->channel_status,
                'category' => $category,
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

        if (! $shipment) {
            throw new \Exception('Shipment tidak ditemukan.');
        }

        return $shipment;
    }

    private function guardDriverCallEligible(Shipment $shipment): void
    {
        if (! in_array($shipment->shipment_type, ['INSTANT', 'SAME_DAY'])) {
            throw new \Exception('Panggilan driver manual hanya untuk shipment tipe INSTANT atau SAME_DAY.');
        }

        $courierKey = $shipment->courier_name ?? $shipment->courier_code ?? '';
        if (! preg_match(self::MANUAL_DRIVER_COURIER_REGEX, $courierKey)) {
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

    private function isInternalManualOrder(Order $order): bool
    {
        $hasChannelIdentity = filled($order->channel_order_no)
            || filled($order->channel_shop_id)
            || filled($order->commerce_platform);

        return (bool) $order->is_manual && ! $hasChannelIdentity;
    }

    private function resolveDefaultLocationId(): string
    {
        $id = Location::getOfficialSmallWarehouseId();

        if (! $id) {
            throw new \Exception('Lokasi gudang kecil tidak ditemukan. Pastikan ada gudang dengan tipe gudang kecil (is_small_warehouse = true).');
        }

        return $id;
    }
}
