<?php

namespace Modules\Outbound\Services;

use Modules\Sales\Models\SalesOrder as Order;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Models\FulfillmentRemoval;
use Modules\Outbound\Repositories\OutboundFulfillmentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Outbound\Contracts\DriverCallResult;
use Modules\Outbound\Services\Logistics\LogisticsGateway;

class OutboundFulfillmentService
{
    private const FULFILLMENT_PUSH_OFF = 'Pengiriman ke marketplace dimatikan untuk toko ini.';

    public function __construct(
        protected \Modules\Sales\Services\SalesOrderService $orderService,
        protected ShopeeOrderService $shopeeOrderService,
        protected OutboundFulfillmentRepository $fulfillmentRepository,
        protected LogisticsGateway $logisticsGateway,
    ) {}

    public function resolveDriverCallOrderIds(array $orderIds, array $shipmentIds): array
    {
        if (! empty($shipmentIds)) {
            $fromShipments = ShipmentOrder::query()
                ->whereIn('shipment_id', $shipmentIds)
                ->pluck('order_id')
                ->all();
            $orderIds = array_merge($orderIds, $fromShipments);
        }

        return array_values(array_unique(array_map('strval', $orderIds)));
    }

    public function dispatchInstantDriverCalls(array $orderIds, int $shipperId): DriverCallResult
    {
        $orders = Order::query()
            ->whereIn('id', $orderIds)
            ->get(['id', 'source', 'driver_call_status', 'channel_shop_id', 'salesorder_no']);

        $blocked = [];
        $groupedFresh = [];
        $groupedRetry = [];
        foreach ($orders as $order) {
            if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'call_driver', $order->salesorder_no)) {
                $blocked[] = [
                    'order_id' => (string) $order->id,
                    'status' => DriverCallResult::STATUS_SKIPPED,
                    'message' => self::FULFILLMENT_PUSH_OFF,
                ];
                continue;
            }

            $key = strtolower((string) ($order->source ?? 'manual')) ?: 'manual';
            if ($order->driver_call_status === 'failed') {
                $groupedRetry[$key][] = (string) $order->id;
            } else {
                $groupedFresh[$key][] = (string) $order->id;
            }
        }

        $foundIds = array_map('strval', $orders->pluck('id')->all());
        $missing = array_values(array_diff($orderIds, $foundIds));

        $aggregate = new DriverCallResult(array_merge($blocked, array_map(fn ($id) => [
            'order_id' => $id,
            'status' => DriverCallResult::STATUS_FAILED,
            'message' => 'Pesanan tidak ditemukan.',
        ], $missing)));

        foreach ($groupedFresh as $source => $ids) {
            $aggregate = $aggregate->merge($this->logisticsGateway->for($source)->callDriver($ids, $shipperId));
        }

        foreach ($groupedRetry as $source => $ids) {
            $aggregate = $aggregate->merge($this->logisticsGateway->for($source)->retryCallDriver($ids, $shipperId));
        }

        return $aggregate;
    }

    public function readyToShip(array $orderIds): array
    {
        $results = [];

        $orders = $this->ordersByIds($orderIds);

        foreach ($orderIds as $orderId) {
            $order = $orders[$orderId] ?? null;

            if (!$order) {
                $results[] = [
                    'order_id'      => $orderId,
                    'salesorder_no' => null,
                    'source'        => null,
                    'status'        => 'failed',
                    'message'       => 'Order tidak ditemukan.',
                ];
                continue;
            }

            if ($skip = $this->alreadyHandledMessage((string) $order->channel_status)) {
                $results[] = $this->result($order, 'skipped', $skip);
                continue;
            }

            if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'ready_to_ship', $order->salesorder_no)) {
                $results[] = $this->result($order, 'skipped', self::FULFILLMENT_PUSH_OFF);
                continue;
            }

            $lock = \Illuminate\Support\Facades\Cache::lock("rts:{$order->id}", 30);
            if (! $lock->get()) {
                $results[] = $this->result($order, 'skipped', 'Order sedang diproses RTS.');
                continue;
            }

            try {
                $adapter = $this->logisticsGateway->for($order->source);
                $outcome = $adapter->readyToShip($order);
                $results[] = $this->result(
                    $order,
                    (string) ($outcome['status'] ?? 'failed'),
                    (string) ($outcome['message'] ?? ''),
                );
            } catch (\Throwable $e) {
                Log::error('readyToShip dispatcher gagal untuk order', [
                    'order_id'         => $order->id,
                    'salesorder_no'    => $order->salesorder_no,
                    'source'           => $order->source,
                    'channel_shop_id'  => (string) ($order->channel_shop_id ?? ''),
                    'channel_order_no' => (string) ($order->channel_order_no ?? ''),
                    'error'            => $e->getMessage(),
                ]);
                $results[] = $this->result($order, 'failed', \Modules\Channel\Support\UploadErrorPresenter::fromMessage((string) $order->source, $e->getMessage())['reason']);
            } finally {
                optional($lock)->release();
            }
        }

        return $results;
    }

    public function createBulkRtsBatch(?\App\Models\User $user, array $orderIds): \Modules\Outbound\Models\BulkRtsBatch
    {
        $orderIds = array_values(array_unique(array_filter($orderIds)));
        $orders = $this->ordersByIds($orderIds);

        $batch = DB::transaction(function () use ($user, $orderIds, $orders) {
            $batch = \Modules\Outbound\Models\BulkRtsBatch::create([
                'user_id'       => $user?->id,
                'status'        => \Modules\Outbound\Models\BulkRtsBatch::STATUS_PROCESSING,
                'total_count'   => count($orderIds),
                'success_count' => 0,
                'failed_count'  => 0,
                'skipped_count' => 0,
                'started_at'    => now(),
            ]);

            foreach ($orderIds as $orderId) {
                $order = $orders[$orderId] ?? null;
                \Modules\Outbound\Models\BulkRtsItem::create([
                    'batch_id'      => $batch->id,
                    'order_id'      => $orderId,
                    'salesorder_no' => $order?->salesorder_no,
                    'source'        => $order?->source,
                    'status'        => \Modules\Outbound\Models\BulkRtsItem::STATUS_PENDING,
                ]);
            }

            return $batch;
        });

        \Modules\Outbound\Jobs\ProcessBulkReadyToShipJob::dispatch($batch->id);

        return $batch;
    }

    public function retryPickup(array $orderIds): array
    {
        $results = [];

        $orders = $this->ordersByIds($orderIds);

        foreach ($orderIds as $orderId) {
            $order = $orders[$orderId] ?? null;

            if (! $order) {
                $results[] = ['order_id' => $orderId, 'salesorder_no' => null, 'source' => null, 'status' => 'failed', 'message' => 'Order tidak ditemukan.'];
                continue;
            }

            if ($order->channel_status !== 'RETRY_SHIP') {
                $results[] = $this->result($order, 'skipped', 'Order tidak dalam status RETRY_SHIP.');
                continue;
            }

            if (ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'retry_pickup', $order->salesorder_no)) {
                $results[] = $this->result($order, 'skipped', self::FULFILLMENT_PUSH_OFF);
                continue;
            }

            $source = strtolower((string) $order->source);

            if ($source !== 'shopee') {
                $results[] = $this->result($order, 'skipped', 'Retry pickup hanya tersedia untuk order Shopee.');
                continue;
            }

            try {
                $this->assertChannelRefs($source, (string) $order->channel_shop_id, (string) $order->channel_order_no);
                $res = $this->shopeeOrderService->retryPickup((string) $order->channel_shop_id, (string) $order->channel_order_no);

                if (! empty($res['updated'])) {
                    $results[] = $this->result($order, 'success', 'Pickup berhasil diatur ulang.');
                } else {
                    $results[] = $this->result($order, 'failed', 'Gagal atur ulang pickup' . (! empty($res['error']) ? " ({$res['error']})" : '') . '.');
                }
            } catch (\Throwable $e) {
                Log::error('retryPickup gagal', [
                    'order_id' => $order->id,
                    'salesorder_no' => $order->salesorder_no,
                    'error' => $e->getMessage(),
                ]);
                $results[] = $this->result($order, 'failed', \Modules\Channel\Support\UploadErrorPresenter::fromMessage((string) $order->source, $e->getMessage())['reason']);
            }
        }

        return $results;
    }

    private function assertChannelRefs(string $source, string $shopId, string $channelOrderNo): void
    {
        if ($shopId === '' || $channelOrderNo === '') {
            throw new \Exception(ucfirst($source) . ': channel_shop_id atau channel_order_no kosong, tidak bisa kirim ke marketplace.');
        }
    }

    private function alreadyHandledMessage(string $channelStatus): ?string
    {
        $cs = strtoupper(trim($channelStatus));

        return match (true) {
            in_array($cs, ['CANCELLED', 'IN_CANCEL'], true) => 'Pesanan dibatalkan — pengiriman tidak dipanggil ulang.',
            in_array($cs, ['RETURN_REQUESTED', 'RETURNED'], true) => 'Pesanan dalam proses retur — pengiriman tidak dipanggil ulang.',
            in_array($cs, ['SHIPPED', 'IN_TRANSIT', 'TO_CONFIRM_RECEIVE', 'DELIVERED', 'COMPLETED'], true) => 'Pesanan sudah dikirim/diproses — pengiriman tidak dipanggil ulang.',
            in_array($cs, ['PROCESSED', 'AWAITING_COLLECTION'], true) => 'Pengiriman sudah diatur saat packing selesai — manifest hanya mendata paket.',
            default => null,
        };
    }

    private function result(Order $order, string $status, string $message): array
    {
        return [
            'order_id'      => $order->id,
            'salesorder_no' => $order->salesorder_no,
            'source'        => $order->source,
            'status'        => $status,
            'message'       => $message,
        ];
    }

    private function stageQuery(string $stage)
    {
        return match ($stage) {
            'ready-to-process' => $this->readyToProcess(),
            'ready-to-pick' => $this->readyToPick(),
            'on-picking' => $this->onPicking(),
            'finish-pick' => $this->finishPick(),
            'failed-pick' => $this->failedPick(),
            'on-packing' => $this->onPacking(),
            'finish-pack' => $this->finishPack(),
            'ready-to-ship' => $this->readyToShipStage(),
            'shipped' => $this->shipped(),
            'empty-stock' => $this->emptyStock(),
            'request-cancel' => $this->pendingCancelRequests(),
            default => throw new \Exception("Stage '{$stage}' tidak dikenal."),
        };
    }

    public function getCourierOptionsByStage(string $stage): array
    {
        $query = $this->stageQuery($stage);

        \App\Support\WarehouseAccess::apply($query, 'sales_orders.location_id');

        return $query->reorder()
            ->whereNotNull('shipping_provider')
            ->where('shipping_provider', '<>', '')
            ->distinct()
            ->orderBy('shipping_provider')
            ->pluck('shipping_provider')
            ->all();
    }

    public function getOrdersByStage(string $stage, int $limit = 10)
    {
        $query = $this->stageQuery($stage);

        $extraSelects = match ($stage) {
            'finish-pick' => ['picker_name', 'picklist_ref'],
            'finish-pack' => ['packer_name'],
            default       => [],
        };

        \App\Support\WarehouseAccess::apply($query, 'sales_orders.location_id');

        return $this->fulfillmentRepository->paginateStage($query, $limit, $extraSelects);
    }

    public function getMonitoring(): array
    {

        $bucketExpr = 'LEAST(GREATEST(CURRENT_DATE - sales_orders.transaction_date::date, 0), 4)';

        $stageScopes = [
            'ready_to_process' => fn () => $this->readyToProcess(),
            'pick'             => fn () => $this->onPicking(),
            'pending'          => fn () => $this->emptyStock(),      
            'pack'             => fn () => $this->onPacking(),
            'ready_to_ship'    => fn () => $this->finishPack(),
            'waiting_ship'     => fn () => $this->readyToShipStage(),
        ];

        $periods = [];
        for ($bucket = 0; $bucket <= 4; $bucket++) {
            $periods[$bucket] = [
                'day_term'         => $bucket,
                'ready_to_process' => 0,
                'pick'             => 0,
                'pending'          => 0,
                'pack'             => 0,
                'ready_to_ship'    => 0,
                'waiting_ship'     => 0,
            ];
        }

        foreach ($stageScopes as $key => $scopeFactory) {
            $rows = $scopeFactory()
                ->reorder()
                ->selectRaw("{$bucketExpr} as day_term, COUNT(*) as c")
                ->groupByRaw($bucketExpr)
                ->pluck('c', 'day_term');

            foreach ($rows as $bucket => $count) {
                $periods[(int) $bucket][$key] = (int) $count;
            }
        }

        return [
            'summary' => $this->monitoringSummary(),
            'periods' => array_values($periods),
        ];
    }

    private function monitoringSummary(): array
    {
        $now      = now();
        $timeCap  = $now->format('H:i:s');
        $today    = $now->toDateString();
        $yesterday = $now->copy()->subDay()->toDateString();

        $todayCount = Order::whereDate('transaction_date', $today)->count();
        $yestCount  = Order::whereDate('transaction_date', $yesterday)
            ->whereRaw('transaction_date::time <= ?', [$timeCap])
            ->count();

        $mtdCount = Order::whereBetween('transaction_date', [
            $now->copy()->startOfMonth(),
            $now,
        ])->count();
        $prevMonthCount = Order::whereBetween('transaction_date', [
            $now->copy()->subMonthNoOverflow()->startOfMonth(),
            $now->copy()->subMonthNoOverflow(),
        ])->count();

        $readyToPick      = $this->readyToProcess()->count();
        $readyToPick2days = $this->readyToProcess()
            ->whereDate('transaction_date', '<=', $now->copy()->subDays(2)->toDateString())
            ->count();

        $pickedToday = $this->pickedOrdersCount($today);
        $pickedYest  = $this->pickedOrdersCount($yesterday, $timeCap);

        return [
            'today'               => $todayCount,
            'yest'                => $yestCount,
            'mtd'                 => $mtdCount,
            'prev_month'          => $prevMonthCount,
            'ready_to_pick'       => $readyToPick,
            'ready_to_pick_2days' => $readyToPick2days,
            'picked_today'        => $pickedToday,
            'picked_yest'         => $pickedYest,
        ];
    }

    private function pickedOrdersCount(string $date, ?string $timeCap = null): int
    {
        return Order::whereHas('picklistItems', function ($q) use ($date, $timeCap) {
            $q->whereHas('picklist', function ($pq) use ($date, $timeCap) {
                $pq->where('status', Picklist::STATUS_COMPLETED)
                    ->whereDate('completed_at', $date);
                if ($timeCap !== null) {
                    $pq->whereRaw('completed_at::time <= ?', [$timeCap]);
                }
            });
        })->count();
    }

    public function getPickers(?string $locationId, string $role): \Illuminate\Support\Collection
    {
        return $this->fulfillmentRepository->getPickers($locationId, $role);
    }

    public function changeLocation(string $orderId, string $locationId, string $changedBy): Order
    {
        \App\Support\WarehouseAccess::assert($locationId);

        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if (!in_array($order->status, ['pending', 'reserved'])) {
            throw new \Exception("Order hanya bisa dipindah lokasi saat status pending/reserved (saat ini: {$order->status}).");
        }

        return $this->orderService->relocateOrder($order, $locationId);
    }

    public function findOrderByNo(string $orderNo): ?Order
    {
        return Order::where('salesorder_no', $orderNo)
            ->orWhere('channel_order_no', $orderNo)
            ->orWhere('tracking_number', $orderNo)
            ->with([
                'items.product:id,sku,product_id',
                'items.product.product:id,name',
                'location:id,location_name,location_code',
            ])
            ->first();
    }

    public function moveToReadyToPick(string $orderId, string $locationId, string $movedBy): Order
    {
        \App\Support\WarehouseAccess::assert($locationId);

        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if ($order->status !== 'reserved') {
            throw new \Exception("Order harus berstatus 'reserved' untuk dipindah ke ready-to-pick (saat ini: {$order->status}).");
        }

        $existing = PicklistItem::where('order_id', $orderId)
            ->whereHas('picklist', fn ($q) => $q->whereNotIn('status', [Picklist::STATUS_CANCELLED, Picklist::STATUS_FAILED]))
            ->exists();

        if ($existing) {
            throw new \Exception('Order sudah memiliki picklist aktif.');
        }

        DB::transaction(function () use ($order, $locationId, $movedBy) {
            $picklist = Picklist::create([
                'picklist_no' => $this->generatePicklistNo(),
                'location_id' => $locationId,
                'status' => Picklist::STATUS_DRAFT,
                'created_by' => $movedBy,
            ]);

            foreach ($order->items as $item) {
                PicklistItem::create([
                    'picklist_id' => $picklist->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'item_id' => $item->item_id,
                    'sku' => $item->sku,
                    'qty_ordered' => $item->qty_in_base,
                    'qty_picked' => 0,
                ]);
            }
        });

        return $order->fresh();
    }

    public function moveToReadyToProcess(string $orderId): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if ($order->status !== 'reserved') {
            throw new \Exception("Order harus berstatus 'reserved' untuk dipindah ke ready-to-process (saat ini: {$order->status}).");
        }

        PicklistItem::where('order_id', $orderId)
            ->whereHas('picklist', fn ($q) => $q->where('status', Picklist::STATUS_DRAFT))
            ->delete();

        return $order->fresh();
    }

    private function generatePicklistNo(): string
    {
        $last = Picklist::whereRaw("picklist_no ~ '^PICK-[0-9]+$'")
            ->orderByRaw("CAST(SUBSTRING(picklist_no FROM 6) AS BIGINT) DESC")
            ->value('picklist_no');

        $seq = $last ? ((int) substr($last, 5)) + 1 : (Picklist::count() + 1);

        return 'PICK-' . str_pad((string) $seq, 9, '0', STR_PAD_LEFT);
    }

    public function requestCancelOrder(string $orderId, ?string $reason = null, ?string $requestedBy = null): Order
    {
        $order = Order::find($orderId);

        if (!$order) {
            throw new \Exception('Order tidak ditemukan.');
        }

        if (in_array($order->status, ['shipped', 'cancelled'])) {
            throw new \Exception("Order dengan status '{$order->status}' tidak bisa di-request cancel.");
        }

        $order->update([
            'cancel_request_reason' => $reason,
            'cancel_requested_at' => now(),
            'cancel_requested_by' => $requestedBy,
        ]);

        return $order->fresh();
    }

    private function readyToProcess()
    {
        return Order::where('status', 'reserved')
            ->whereNotNull('handed_to_warehouse_at')
            ->whereDoesntHave('picklistItems');
    }

    private function readyToPick()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_DRAFT));
            });
    }

    private function onPicking()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_IN_PROGRESS));
            });
    }

    private function finishPick()
    {
        return Order::where('status', 'picked')
            ->whereDoesntHave('packlist', fn ($q) => $q->whereIn('status', [Packlist::STATUS_DRAFT, Packlist::STATUS_IN_PROGRESS, Packlist::STATUS_COMPLETED]));
    }

    private function failedPick()
    {
        return Order::where('status', 'reserved')
            ->whereHas('picklistItems', function ($q) {
                $q->whereHas('picklist', fn ($pq) => $pq->where('status', Picklist::STATUS_FAILED));
            });
    }

    private function onPacking()
    {
        return Order::where('status', 'picked')
            ->whereHas('packlist', fn ($q) => $q->where('status', Packlist::STATUS_IN_PROGRESS));
    }

    private function finishPack()
    {
        return Order::where(function ($q) {
            $q->where('status', 'packed')
                ->orWhere(function ($q2) {
                    $q2->where('status', 'cancelled')
                        ->whereNull('cancel_dismissed_at')
                        ->whereHas('packlist', fn ($pq) => $pq->where('status', Packlist::STATUS_COMPLETED));
                });
        })->whereDoesntHave('shipmentOrders');
    }

    private function readyToShipStage()
    {
        return Order::where(function ($q) {
            $q->where('status', 'packed')
                ->orWhere(function ($q2) {
                    $q2->where('status', 'cancelled')
                        ->whereNull('cancel_dismissed_at');
                });
        })->whereHas('shipmentOrders', function ($q) {
            $q->whereHas('shipment', fn ($sq) => $sq->where('status', 'SCHEDULED'));
        });
    }

    private function shipped()
    {
        return Order::where('status', 'shipped');
    }

    private function emptyStock()
    {
        return Order::where('status', 'reserved')
            ->whereHas('items', function ($q) {
                $q->whereDoesntHave('inventory', function ($iq) {
                    $iq->where('available', '>', 0)
                        ->whereRaw('(sales_orders.location_id IS NULL OR inventories.location_id = sales_orders.location_id)');
                });
            });
    }

    private function pendingCancelRequests()
    {
        return Order::whereNotNull('cancel_requested_at')
            ->whereNotIn('status', ['cancelled']);
    }

    public function deleteOrderFromFulfillment(string $orderId, ?string $reason, ?string $removedBy): void
    {
        $order = Order::findOrFail($orderId);

        if ($order->status === 'shipped') {
            throw new \Exception('Pesanan sudah dikirim — tidak bisa dihapus.');
        }

        $removedBy = $removedBy ?: 'system';

        $shipmentId = ShipmentOrder::where('order_id', $order->id)->value('shipment_id');

        if ($shipmentId) {
            app(\Modules\Outbound\Services\ShipmentService::class)->removeOrders($shipmentId, [$order->id]);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_SHIPPING, $removedBy, $reason, false);

            return;
        }

        $activePacklist = Packlist::where('order_id', $order->id)
            ->where('status', '!=', Packlist::STATUS_CANCELLED)
            ->first();

        if ($activePacklist) {
            app(\Modules\Outbound\Services\PacklistService::class)->revert($activePacklist->id);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_PACKING, $removedBy, $reason, false);

            return;
        }

        if ($order->status === 'packed') {
            $order->update(['status' => 'picked']);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_PACKING, $removedBy, $reason, false);

            return;
        }

        $picklistId = PicklistItem::where('order_id', $order->id)->value('picklist_id');

        if ($picklistId) {
            $reversed = app(\Modules\Outbound\Services\PicklistService::class)
                ->failPickOrder($picklistId, $order->id, $removedBy, (string) $reason);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_PICKING, $removedBy, $reason, $reversed);

            return;
        }

        if (in_array($order->status, ['picked', 'reserved'], true)) {
            $order->update([
                'status'                 => 'reserved',
                'pick_failed_at'         => now(),
                'pick_failed_by'         => $removedBy,
                'pick_fail_reason'       => $reason,
                'handed_to_warehouse_at' => null,
            ]);
            $this->logRemoval($order->id, FulfillmentRemoval::STAGE_PICKING, $removedBy, $reason, false);

            return;
        }

        throw new \Exception('Pesanan belum masuk proses picking/packing/pengiriman — tidak ada yang bisa dikembalikan.');
    }

    public function bulkDeleteOrdersFromFulfillment(array $orderIds, ?string $reason, ?string $removedBy): array
    {
        $results = [];

        $orders = $this->ordersByIds($orderIds);

        foreach ($orderIds as $orderId) {
            $order = $orders[$orderId] ?? null;

            if (! $order) {
                $results[] = [
                    'order_id'      => $orderId,
                    'salesorder_no' => null,
                    'source'        => null,
                    'status'        => 'failed',
                    'message'       => 'Order tidak ditemukan.',
                ];
                continue;
            }

            try {
                $this->deleteOrderFromFulfillment($order->id, $reason, $removedBy);
                $results[] = $this->result($order, 'success', 'Dikembalikan ke tahap sebelumnya.');
            } catch (\Throwable $e) {
                $results[] = $this->result($order, 'failed', $e->getMessage());
            }
        }

        return $results;
    }

    private function ordersByIds(array $orderIds): \Illuminate\Support\Collection
    {
        if (empty($orderIds)) {
            return collect();
        }

        return Order::whereIn('id', $orderIds)->get()->keyBy('id');
    }

    private function logRemoval(string $orderId, string $stage, string $removedBy, ?string $reason, bool $reversedStock): void
    {
        FulfillmentRemoval::create([
            'order_id'       => $orderId,
            'stage'          => $stage,
            'removed_by'     => $removedBy,
            'reason'         => $reason,
            'reversed_stock' => $reversedStock,
        ]);
    }
}
