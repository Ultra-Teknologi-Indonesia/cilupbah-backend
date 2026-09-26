<?php

namespace Modules\Sales\Services;

use App\Models\User;
use App\Support\WarehouseAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Channel\Exceptions\ChannelLabelUnsupportedException;
use Modules\Channel\Exceptions\ShopeeApiException;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelFulfillmentGuard;
use Modules\Realtime\Services\RealtimeEventPublisher;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Jobs\ArchiveBulkShippingLabelJob;
use Modules\Sales\Jobs\CollectShopeeLabelPreparationJob;
use Modules\Sales\Jobs\DownloadBulkMarketplaceLabelsJob;
use Modules\Sales\Jobs\FinalizeBulkShippingLabelBatchJob;
use Modules\Sales\Jobs\PrepareBulkShopeeShippingLabelsJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelItemJob;
use Modules\Sales\Jobs\ProcessBulkShippingLabelJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Jobs\RequestShopeeMassAwbJob;
use Modules\Sales\Jobs\RequestTikTokMassAwbJob;
use Modules\Sales\Models\BulkShippingLabelBatch;
use Modules\Sales\Models\BulkShippingLabelItem;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Repositories\BulkShippingLabelRequestRepository;
use Modules\Sales\Support\ChannelOperationLedger;
use Modules\Sales\Support\ChannelOrderSideEffectGuard;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class BulkShippingLabelService
{
    public const CHANNEL_SHOPEE = 'shopee';

    public const CHANNEL_TIKTOK = 'tiktok';

    public const CHANNEL_LAZADA = 'lazada';

    public const CHANNEL_WC = 'woocommerce';

    public const CHANNEL_MANUAL = 'manual';

    public const SUPPORTED_CHANNELS = [
        self::CHANNEL_SHOPEE,
        self::CHANNEL_TIKTOK,
        self::CHANNEL_LAZADA,
    ];

    public const SIZE_100X150 = 'thermal_100x150';

    public const SIZE_100X120 = 'thermal_100x120';

    public const DEFAULT_SIZE = self::SIZE_100X120;

    private const SIZE_DIMENSIONS_MM = [
        self::SIZE_100X150 => [100.0, 150.0],
        self::SIZE_100X120 => [100.0, 120.0],
    ];

    private const BBOX_MIN_SIDE_MM = 20.0;

    private const BBOX_FULL_SHEET_RATIO = 0.95;

    private const BBOX_SAFE_MARGIN_MM = 2.0;

    public const TIKTOK_DOWNLOAD_TIMEOUT = 20;

    public const TIKTOK_DOWNLOAD_RETRIES = 2;

    public const TIKTOK_PARALLEL_LANES = 8;

    public const SPLIT_SUB_BATCH_THRESHOLD = 500;

    public const SUB_BATCH_SIZE = 100;

    private const INSTANT_COURIER_KEYWORDS = [
        'INSTANT',
        'SAMEDAY_INSTANT',
        'SAME DAY INSTANT',
        'GOJEK',
        'GRAB',
        'LALAMOVE',
        'BORZO',
        'DEALIVER',
    ];

    public function __construct(private SalesOrderService $salesOrderService) {}

    public function createBatch(User $user, array $orderIds, array $perChannelOpts): BulkShippingLabelBatch
    {

        $orderIds = array_values(array_unique(array_map('strval', $orderIds)));
        if ($orderIds === []) {
            throw new \InvalidArgumentException('Minimal satu pesanan diperlukan.');
        }

        $awaitingAwb = [];
        $ordersById = new EloquentCollection;
        $canonicalize = function (array $value) use (&$canonicalize): array {
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$entry) {
                if (is_array($entry)) {
                    $entry = $canonicalize($entry);
                }
            }

            return $value;
        };
        $accessIds = WarehouseAccess::allowedIds();
        if ($accessIds !== null) {
            sort($accessIds);
        }

        $requestKey = hash('sha256', json_encode([$orderIds, $canonicalize($perChannelOpts), $accessIds], JSON_THROW_ON_ERROR));

        [$batch, $created] = app(BulkShippingLabelRequestRepository::class)->firstOrCreateActive(
            (string) $user->id,
            $requestKey,
            function () use ($user, $orderIds, $perChannelOpts, $requestKey, &$awaitingAwb, &$ordersById) {
                $batch = BulkShippingLabelBatch::create([
                    'user_id' => $user->id,
                    'request_key' => $requestKey,
                    'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
                    'per_channel_opts' => $perChannelOpts,
                    'total_count' => count($orderIds),
                    'done_count' => 0,
                    'failed_count' => 0,
                    'skipped_count' => 0,
                ]);

                $ordersQuery = SalesOrder::whereIn('id', $orderIds);
                WarehouseAccess::apply($ordersQuery, 'location_id');
                $orders = $ordersQuery
                    ->get()
                    ->keyBy('id');
                $ordersById = new EloquentCollection($orders->all());

                foreach ($orderIds as $orderId) {
                    $order = $orders->get($orderId);
                    $channel = $order?->source ?? self::CHANNEL_MANUAL;

                    [$status, $reason] = $this->initialItemStatus($order, $channel);

                    if ($status === BulkShippingLabelItem::STATUS_WAITING_AWB) {
                        $awaitingAwb[] = $orderId;
                    }

                    BulkShippingLabelItem::create([
                        'batch_id' => $batch->id,
                        'order_id' => $orderId,
                        'channel' => $channel,
                        'status' => $status,
                        'reason' => $reason,
                    ]);
                }

                $batch->recomputeCounts();

                return $batch->fresh();
            },
        );

        if (! $created) {

            $this->dispatchAwaitingAwb($batch, $orderIds);

            return $batch;
        }

        $items = $batch->items()
            ->whereIn('order_id', $orderIds)
            ->get()
            ->keyBy('order_id');

        $this->hydrateReusableLabels($items, $ordersById);

        $batch->recomputeCounts();

        $this->dispatchAwaitingAwb($batch, $awaitingAwb);

        return $batch;
    }

    public function queueBatch(BulkShippingLabelBatch $batch): void
    {
        $hasPending = $batch->items()
            ->where('status', BulkShippingLabelItem::STATUS_PENDING)
            ->exists();

        $hasTransient = $batch->items()
            ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
            ->exists();

        if (! $hasPending && $hasTransient) {
            return;
        }

        ProcessBulkShippingLabelJob::dispatch($batch->id);
    }

    public function requeueOrphanedBatch(BulkShippingLabelBatch $batch): int
    {
        if ($batch->status !== BulkShippingLabelBatch::STATUS_PROCESSING
            || $batch->started_at !== null) {
            return 0;
        }

        $reset = $batch->items()
            ->whereIn('status', [
                BulkShippingLabelItem::STATUS_DOWNLOADING,
                BulkShippingLabelItem::STATUS_TRANSFORMING,
                BulkShippingLabelItem::STATUS_WAITING_MARKETPLACE,
                BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
                BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP,
                BulkShippingLabelItem::STATUS_WAITING_TIKTOK_PREP,
            ])
            ->update([
                'status' => BulkShippingLabelItem::STATUS_PENDING,
                'reason' => null,
                'updated_at' => now(),
            ]);

        if ($batch->items()->where('status', BulkShippingLabelItem::STATUS_PENDING)->exists()) {
            ProcessBulkShippingLabelJob::dispatch($batch->id);
        }

        return $reset;
    }

    public function downloadableFile(BulkShippingLabelBatch $batch, User $user): array
    {
        if ((string) $batch->user_id !== (string) $user->id) {
            throw new AuthorizationException('Batch label bukan milik pengguna ini.');
        }

        $warehouseIds = $user->allowedLocationIds();
        if ($warehouseIds !== null && app(BulkShippingLabelRequestRepository::class)
            ->hasInaccessibleCompletedItems((string) $batch->id, $warehouseIds)) {
            throw new AuthorizationException('Akses gudang berubah. Pilih ulang pesanan yang dapat dicetak.');
        }

        if ($batch->status !== BulkShippingLabelBatch::STATUS_READY
            || (empty($batch->merged_pdf_path) && empty($batch->print_pdf_path))) {
            throw new NotFoundHttpException('File label belum siap.');
        }

        if ($batch->print_pdf_path) {
            $spoolDiskName = config('bulk-labels.spool_disk', 'print_spool');
            $spool = Storage::disk($spoolDiskName);
            if ($spool->exists($batch->print_pdf_path)) {
                $this->recordLabelPrinted($batch, $user);

                return ['disk' => $spoolDiskName, 'path' => $batch->print_pdf_path];
            }
        }

        if (! $batch->merged_pdf_path) {
            throw new NotFoundHttpException('File label tidak ditemukan.');
        }

        $archiveDiskName = config('bulk-labels.archive_disk', 'documents');
        $archive = Storage::disk($archiveDiskName);
        if (! $archive->exists($batch->merged_pdf_path)) {
            throw new NotFoundHttpException('File label tidak ditemukan.');
        }

        $this->recordLabelPrinted($batch, $user);

        return ['disk' => $archiveDiskName, 'path' => $batch->merged_pdf_path];
    }

    public function downloadablePath(BulkShippingLabelBatch $batch, User $user): string
    {
        return $this->downloadableFile($batch, $user)['path'];
    }

    private function recordLabelPrinted(BulkShippingLabelBatch $batch, User $user): void
    {
        if ($batch->print_pdf_path && $batch->print_requested_at === null) {
            $batch->update(['print_requested_at' => now()]);
        }

        $batch->items()
            ->whereIn('status', BulkShippingLabelItem::COMPLETED_STATUSES)
            ->with('order')
            ->get()
            ->each(function (BulkShippingLabelItem $item) use ($user): void {
                if ($item->order) {
                    $this->salesOrderService->logLabelPrinted($item->order, $user, $item->order->shipping_label_doc_type);
                }
            });

    }

    public function archiveAfterPrintDelivery(BulkShippingLabelBatch $batch): void
    {
        if (! $batch->print_pdf_path
            || $batch->archive_status === BulkShippingLabelBatch::ARCHIVE_ARCHIVED) {
            return;
        }

        ArchiveBulkShippingLabelJob::dispatch($batch->id)
            ->delay(now()->addSeconds(config('bulk-labels.archive_after_print_seconds', 30)))
            ->afterResponse();
    }

    public function retryFailed(User $user, BulkShippingLabelBatch $batch): BulkShippingLabelBatch
    {
        if ((string) $batch->user_id !== (string) $user->id) {
            throw new AuthorizationException('Batch label bukan milik pengguna ini.');
        }

        $recoverableItems = $batch->items()
            ->where('status', BulkShippingLabelItem::STATUS_FAILED)
            ->where(function ($query): void {
                $query->whereIn('reason', BulkShippingLabelItem::RECOVERABLE_REASONS)
                    ->orWhere(function ($query): void {
                        $query->where('channel', 'shopee')
                            ->where('reason', BulkShippingLabelItem::REASON_SELF_DESIGN);
                    });
            })
            ->get();

        if ($recoverableItems->isEmpty()) {
            throw new \InvalidArgumentException('Tidak ada label gagal yang bisa dicoba ulang.');
        }

        $awaitingAwb = [];
        $toDispatch = [];

        DB::transaction(function () use ($batch, $recoverableItems, &$awaitingAwb, &$toDispatch): void {
            $batch->update([
                'status' => BulkShippingLabelBatch::STATUS_PROCESSING,
                'finished_at' => null,
            ]);

            $orderIds = $recoverableItems->pluck('order_id')->all();
            $orders = SalesOrder::whereIn('id', $orderIds)->get()->keyBy('id');

            foreach ($recoverableItems as $item) {
                $order = $orders->get($item->order_id);
                $channel = $order?->source ?? $item->channel ?? self::CHANNEL_MANUAL;
                [$status, $reason] = $this->initialItemStatus($order, $channel);

                $item->update([
                    'status' => $status,
                    'reason' => $reason,
                    'raw_pdf_path' => null,
                    'ready_pdf_path' => null,
                    'updated_at' => now(),
                ]);

                if ($status === BulkShippingLabelItem::STATUS_WAITING_AWB) {
                    $awaitingAwb[] = (string) $item->order_id;
                } elseif ($status === BulkShippingLabelItem::STATUS_PENDING) {
                    $toDispatch[] = $item;
                }
            }

            $batch->recomputeCounts();
        });

        foreach ($recoverableItems as $item) {
            $this->hydrateCachedLabel((string) $item->order_id);
        }

        $this->dispatchAwaitingAwb($batch, $awaitingAwb);

        foreach ($toDispatch as $item) {
            $freshItem = BulkShippingLabelItem::find($item->id);
            if ($freshItem && $freshItem->status === BulkShippingLabelItem::STATUS_PENDING) {
                $this->dispatchItem($freshItem);
            }
        }

        return $batch->fresh();
    }

    private function dispatchAwaitingAwb(BulkShippingLabelBatch $batch, array $orderIds): void
    {
        $orderIds = array_values(array_unique(array_map('strval', $orderIds)));
        if ($orderIds === []) {
            return;
        }

        $waitingOrderIds = $batch->items()
            ->whereIn('order_id', $orderIds)
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_AWB)
            ->pluck('order_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $orders = SalesOrder::query()
            ->whereIn('id', $waitingOrderIds)
            ->get(['id', 'source', 'channel_shop_id']);

        $shopeeOrders = $orders
            ->filter(static fn (SalesOrder $order): bool => strtolower((string) $order->source) === self::CHANNEL_SHOPEE)
            ->groupBy(static fn (SalesOrder $order): string => (string) $order->channel_shop_id);

        $chunkSize = (int) config('bulk-labels.shopee_mass_awb_chunk_size', 50);
        foreach ($shopeeOrders as $shopId => $shopOrders) {
            foreach ($shopOrders->pluck('id')->map('strval')->chunk(max(1, $chunkSize)) as $chunk) {
                RequestShopeeMassAwbJob::dispatch(
                    (string) $batch->id,
                    (string) $shopId,
                    $chunk->values()->all(),
                );
            }
        }

        $tiktokOrders = $orders
            ->filter(static fn (SalesOrder $order): bool => strtolower((string) $order->source) === self::CHANNEL_TIKTOK)
            ->groupBy(static fn (SalesOrder $order): string => (string) $order->channel_shop_id);

        $tiktokChunkSize = (int) config('bulk-labels.tiktok_mass_awb_chunk_size', 50);
        foreach ($tiktokOrders as $shopId => $shopOrders) {
            foreach ($shopOrders->pluck('id')->map('strval')->chunk(max(1, $tiktokChunkSize)) as $chunk) {
                RequestTikTokMassAwbJob::dispatch(
                    (string) $batch->id,
                    (string) $shopId,
                    $chunk->values()->all(),
                );
            }
        }

        $orders
            ->reject(static fn (SalesOrder $order): bool => in_array(
                strtolower((string) $order->source),
                [self::CHANNEL_SHOPEE, self::CHANNEL_TIKTOK],
                true,
            ))
            ->each(static fn (SalesOrder $order) => RequestChannelAwbJob::dispatch(
                (string) $order->id,
                0,
                true,
                false,
                false,
                strtolower((string) $order->source),
            ));
    }

    private function initialItemStatus(?SalesOrder $order, string $channel): array
    {
        if (! $order) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_NO_AWB];
        }

        if (! in_array($channel, self::SUPPORTED_CHANNELS, true)) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED];
        }

        $hasAwb = ! empty($order->tracking_number) || ! empty($order->awb_no);

        if (! $hasAwb) {
            if ($this->isInstantCourier($order)) {
                return [BulkShippingLabelItem::STATUS_SKIPPED_INSTANT, BulkShippingLabelItem::REASON_INSTANT_COURIER];
            }

            return $this->awaitAwbOrFail($order);
        }

        return [BulkShippingLabelItem::STATUS_PENDING, null];
    }

    private function awaitAwbOrFail(SalesOrder $order): array
    {
        if (empty($order->channel_shop_id)) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_NO_AWB];
        }

        if ($this->channelFulfillmentBlocked($order)) {
            return [BulkShippingLabelItem::STATUS_FAILED, BulkShippingLabelItem::REASON_FULFILLMENT_DISABLED];
        }

        return [BulkShippingLabelItem::STATUS_WAITING_AWB, null];
    }

    public function channelFulfillmentBlocked(SalesOrder $order): bool
    {
        return ChannelFulfillmentGuard::blocks(
            $order->channel_shop_id,
            'ready_to_ship',
            $order->salesorder_no,
        );
    }

    public function isInstantCourier(?SalesOrder $order): bool
    {
        return $order === null || $order->channel_instant !== false;
    }

    public function processPendingItems(BulkShippingLabelBatch $batch, ?array $perChannelOpts): void
    {
        $pending = $batch->items()
            ->with('order')
            ->where('status', BulkShippingLabelItem::STATUS_PENDING)
            ->get();

        $shopeeItems = $pending->where('channel', self::CHANNEL_SHOPEE);
        $tikTokItems = $pending->where('channel', self::CHANNEL_TIKTOK);
        $lazadaItems = $pending->where('channel', self::CHANNEL_LAZADA);
        $otherItems = $pending->whereNotIn('channel', self::SUPPORTED_CHANNELS);

        foreach ($shopeeItems as $item) {
            $this->processItem($item, $perChannelOpts);
        }
        foreach ($lazadaItems as $item) {
            $this->processItem($item, $perChannelOpts);
        }
        foreach ($otherItems as $item) {
            $this->fail($item, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED);
        }
        if ($tikTokItems->isNotEmpty()) {
            $this->processTikTokBatch($tikTokItems->values(), $perChannelOpts);
        }

        $batch->recomputeCounts();

        $this->tryFinalize($batch);
    }

    public function processQueuedBatch(BulkShippingLabelBatch $batch): void
    {
        app(BulkShippingLabelRequestRepository::class)->eachPendingChunk((string) $batch->id, function (Collection $pending) use ($batch): void {

            if (config('bulk-labels.async_shopee_preparation', false)) {
                foreach ($pending->where('channel', self::CHANNEL_SHOPEE)->groupBy('order.channel_shop_id') as $shopItems) {
                    foreach ($shopItems->chunk(50) as $chunk) {
                        PrepareBulkShopeeShippingLabelsJob::dispatch((string) $batch->id, $chunk->pluck('id')->all());
                    }
                }
            } else {

                $this->prepareShopeeItems($pending->where('channel', self::CHANNEL_SHOPEE));
            }

            foreach ($pending->where('channel', self::CHANNEL_LAZADA)->groupBy('order.channel_shop_id') as $shopItems) {
                foreach ($shopItems->chunk(20) as $chunk) {
                    DownloadBulkMarketplaceLabelsJob::dispatch((string) $batch->id, $chunk->pluck('id')->all(), 'lazada');
                }
            }
            foreach ($pending->where('channel', self::CHANNEL_TIKTOK) as $item) {
                $this->dispatchItem($item);
            }

            foreach ($pending->whereNotIn('channel', self::SUPPORTED_CHANNELS) as $item) {
                $this->fail($item, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED);
            }
        });

        $batch->recomputeCounts();
        $this->tryFinalize($batch);
    }

    public function prepareShopeeChunk(string $batchId, array $itemIds, int $attempt = 0): void
    {
        $items = app(BulkShippingLabelRequestRepository::class)->shopeePreparationItems($batchId, $itemIds);
        $locks = [];
        $busyOrders = [];
        try {
            foreach ($items->pluck('order_id')->unique()->sort() as $orderId) {
                $lock = Cache::lock("shipping-label:prepare:{$orderId}", 240);
                if (! $lock->get()) {
                    $busyOrders[] = $orderId;

                    continue;
                }
                $locks[] = $lock;
            }
            if ($locks === [] && $busyOrders !== []) {
                throw new \RuntimeException('Label masih disiapkan oleh proses lain; kelompok akan dicoba kembali.');
            }
            $this->prepareShopeeItems($items->whereNotIn('order_id', $busyOrders), $attempt);
            if ($busyOrders !== []) {
                PrepareBulkShopeeShippingLabelsJob::dispatch($batchId,
                    $items->whereIn('order_id', $busyOrders)->pluck('id')->all(), $attempt)->delay(now()->addSeconds(5));
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
        $batch = app(BulkShippingLabelRequestRepository::class)->findBatch($batchId);
        if ($batch) {
            $batch->recomputeCounts();
            $this->tryFinalize($batch);
        }
    }

    private function prepareShopeeItems(Collection $items, int $attempt = 0): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $requests = app(BulkShippingLabelRequestRepository::class);
        $items = $items->filter(fn (BulkShippingLabelItem $item): bool => $requests->subscribeShopeePreparation($item));

        $orders = SalesOrder::query()
            ->whereIn('id', $items->pluck('order_id')->unique()->values())
            ->get()
            ->keyBy('id');
        $shopee = app(ShopeeOrderService::class);
        $downloadItems = [];
        $retryItems = [];

        foreach ($items->groupBy(static function (BulkShippingLabelItem $item) use ($orders): string {
            return (string) ($orders->get($item->order_id)?->channel_shop_id ?? '');
        }) as $shopId => $shopItems) {
            if ($shopId === '') {
                foreach ($shopItems as $item) {
                    $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);
                }

                continue;
            }

            $rows = [];
            $claimed = [];
            $claimsByOrder = [];
            $createRows = [];
            $executeByOrder = [];
            foreach ($shopItems as $item) {
                $order = $orders->get($item->order_id);
                if ($order && (ChannelOrderSideEffectGuard::active((string) $order->id, 'prepare_bulk_shipping_label') === null
                    || ChannelFulfillmentGuard::blocks($order->channel_shop_id, 'shipping_label', $order->salesorder_no))) {
                    $this->fail($item, BulkShippingLabelItem::REASON_FULFILLMENT_DISABLED);

                    continue;
                }
                if (! $order || ! filled($order->tracking_number) || ! filled($order->channel_order_no)) {
                    $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);

                    continue;
                }

                if ($order->shipping_label_status === 'ready') {
                    $downloadItems[(string) $item->batch_id][] = (string) $item->id;

                    continue;
                }

                $orderId = (string) $order->id;
                if (! isset($claimsByOrder[$orderId])) {
                    $claim = ChannelOperationLedger::claim($order, 'create_shipping_label');
                    $claimsByOrder[$orderId] = $claim['attempt'];
                    $executeByOrder[$orderId] = $claim['should_execute'];
                }

                foreach ($this->shopeeShippingDocumentRows($order) as $row) {
                    $key = (string) $row['order_sn'].'|'.(string) ($row['package_number'] ?? '');
                    $rows[] = $row;
                    if ($executeByOrder[$orderId]) {
                        $createRows[] = $row;
                    }
                    $claimed[$key] = [
                        'item' => $item,
                        'order' => $order,
                        'attempt' => $claimsByOrder[$orderId],
                        'doc_type' => (string) $row['shipping_document_type'],
                    ];
                }
            }

            if ($rows === []) {
                continue;
            }

            try {
                $created = $createRows === [] ? ['results' => []] : $shopee->createShippingDocumentsMass($shopId, $createRows);
                $checked = $shopee->getShippingDocumentResultsMass($shopId, $rows);
            } catch (Throwable $e) {
                foreach ($claimed as $entry) {
                    ChannelOperationLedger::markUncertain($entry['attempt'], $e);
                    $entry['order']->forceFill(['shipping_label_status' => 'preparing'])->saveQuietly();
                    $retryItems[(string) $entry['item']->batch_id][(string) $entry['item']->id] = $entry['item'];
                }

                Log::warning('Shopee bulk label prepare failed; switched to status verification', [
                    'shop_id' => $shopId,
                    'orders' => count($claimed),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $states = [];
            foreach ($claimed as $key => $entry) {
                $orderId = (string) $entry['order']->id;
                $states[$orderId] ??= [
                    'item' => $entry['item'],
                    'order' => $entry['order'],
                    'attempt' => $entry['attempt'],
                    'doc_type' => $entry['doc_type'],
                    'ready' => [],
                    'retry' => false,
                    'terminal' => false,
                    'retry_marked' => false,
                    'individual_prepare' => false,
                ];

                if ($states[$orderId]['terminal']) {
                    continue;
                }

                $createResult = $created['results'][$key] ?? null;
                $checkResult = $checked['results'][$key] ?? null;
                $createError = (string) ($createResult['error'] ?? '');
                $checkError = (string) ($checkResult['error'] ?? '');

                if (($checkResult['ready'] ?? false) === true) {
                    $states[$orderId]['ready'][$key] = true;

                    continue;
                }

                if (($createResult['accepted'] ?? false) === true) {
                    ChannelOperationLedger::markAccepted($entry['attempt']);
                } elseif ($createError !== '') {
                    app(ShopeeShippingDocumentTypeCache::class)->forget($entry['order']);
                    if ($states[$orderId]['terminal']) {
                        continue;
                    }
                    $reason = $shopee->classifyShippingLabelFailure($createResult);
                    if ($reason !== null) {
                        if (! $states[$orderId]['terminal']) {
                            ChannelOperationLedger::markRejected($entry['attempt'], $createError);
                        }
                        $this->markShopeeBatchFailure($entry['order'], $reason, $createError);
                        $this->fail($entry['item'], $reason);
                        $states[$orderId]['terminal'] = true;

                        continue;
                    }

                    if (! $states[$orderId]['retry_marked']) {
                        if (($createResult['response'] ?? null) === null) {
                            ChannelOperationLedger::markUncertain($entry['attempt'], new \RuntimeException($createError));
                        } else {
                            ChannelOperationLedger::markRetryable($entry['attempt'], $createError, $createResult['response']);

                            $states[$orderId]['individual_prepare'] = true;
                        }
                        $states[$orderId]['retry_marked'] = true;
                    }
                    $states[$orderId]['retry'] = true;

                    continue;
                } elseif ($executeByOrder[$orderId]) {
                    $states[$orderId]['retry'] = true;
                }

                if (strtoupper((string) ($checkResult['status'] ?? '')) === 'FAILED' || $checkError !== '') {
                    $reason = $shopee->classifyShippingLabelFailure($checkResult);
                    if ($reason !== null) {
                        if (! $states[$orderId]['terminal']) {
                            ChannelOperationLedger::markRejected($entry['attempt'], $checkError ?: 'Shopee shipping document FAILED');
                        }
                        $this->markShopeeBatchFailure($entry['order'], $reason, $checkError);
                        $this->fail($entry['item'], $reason);
                        $states[$orderId]['terminal'] = true;

                        continue;
                    }
                }

                $states[$orderId]['retry'] = true;
            }

            foreach ($states as $state) {
                if ($state['terminal']) {
                    continue;
                }

                $packageCount = count(array_filter(
                    $claimed,
                    static fn (array $entry): bool => (string) $entry['order']->id === (string) $state['order']->id,
                ));
                if ($state['retry'] || count($state['ready']) < $packageCount) {
                    $state['order']->forceFill(['shipping_label_status' => 'preparing'])->saveQuietly();
                    if ($state['individual_prepare']) {
                        PrepareShopeeShippingLabelJob::dispatch((string) $state['order']->id);
                    } else {
                        $retryItems[(string) $state['item']->batch_id][(string) $state['item']->id] = $state['item'];
                    }

                    continue;
                }

                $state['order']->forceFill([
                    'shipping_label_status' => 'ready',
                    'shipping_label_doc_type' => $state['doc_type'],
                    'shipping_label_prepared_at' => now(),
                ])->saveQuietly();
                ChannelOperationLedger::markSucceeded($state['attempt']);
                app(ShopeeShippingDocumentTypeCache::class)->confirm($state['order'], $state['doc_type']);
                $downloadItems[(string) $state['item']->batch_id][] = (string) $state['item']->id;
            }
        }
        foreach ($downloadItems as $batchId => $ids) {
            foreach (array_chunk(array_values(array_unique($ids)), 50) as $chunk) {
                DownloadBulkMarketplaceLabelsJob::dispatch($batchId, $chunk, 'shopee');
            }
        }
        $delays = config('bulk-labels.shopee_preparation_delays', [2, 3, 5, 8, 13, 30]);
        foreach ($retryItems as $batchId => $pending) {
            if (isset($delays[$attempt])) {
                foreach (array_chunk(array_keys($pending), 50) as $chunk) {
                    PrepareBulkShopeeShippingLabelsJob::dispatch($batchId, $chunk, $attempt + 1)->delay(now()->addSeconds($delays[$attempt]));
                }
            } else {
                foreach ($pending as $item) {
                    PrepareShopeeShippingLabelJob::dispatch((string) $item->order_id, 1);
                }
            }
        }
    }

    private function shopeeShippingDocumentRows(SalesOrder $order): array
    {
        $base = [
            'order_sn' => (string) $order->channel_order_no,
            'tracking_number' => (string) $order->tracking_number,
            'shipping_document_type' => $order->shipping_label_doc_type ?: (app(ShopeeShippingDocumentTypeCache::class)->get($order) ?? 'THERMAL_AIR_WAYBILL'),
        ];
        $packageNumbers = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) $order->channel_package_ids,
        ))));
        $packageTrackingNumbers = (array) data_get(
            is_array($order->shipping_label_raw_data) ? $order->shipping_label_raw_data : [],
            'package_tracking_numbers',
            [],
        );

        if (count($packageNumbers) <= 1) {
            return [$base + (isset($packageNumbers[0]) ? ['package_number' => $packageNumbers[0]] : [])];
        }

        return array_map(
            static fn (string $packageNumber): array => array_filter([
                'order_sn' => $base['order_sn'],
                'package_number' => $packageNumber,
                'tracking_number' => $packageTrackingNumbers[$packageNumber] ?? null,
                'shipping_document_type' => $base['shipping_document_type'],
            ], static fn ($value): bool => $value !== null && $value !== ''),
            $packageNumbers,
        );
    }

    private function markShopeeBatchFailure(SalesOrder $order, string $reason, string $message): void
    {
        app(ShopeeShippingDocumentTypeCache::class)->forget($order);
        $rawData = is_array($order->shipping_label_raw_data)
            ? $order->shipping_label_raw_data
            : [];
        $rawData['shipping_label_failure'] = array_filter([
            'reason' => $reason,
            'message' => $message,
            'at' => now()->toISOString(),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $order->forceFill([
            'shipping_label_status' => $reason === ShopeeOrderService::LABEL_FAILURE_SELF_DESIGN
                ? 'self_design_required'
                : 'failed',
            'shipping_label_doc_type' => null,
            'shipping_label_prepared_at' => now(),
            'shipping_label_raw_data' => $rawData,
        ])->saveQuietly();
    }

    public function dispatchPendingItems(BulkShippingLabelBatch $batch): int
    {
        if ($batch->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
            return 0;
        }

        $count = 0;
        $batch->items()
            ->where('status', BulkShippingLabelItem::STATUS_PENDING)
            ->orderBy('created_at')
            ->select(['id', 'batch_id', 'order_id'])
            ->cursor()
            ->each(function (BulkShippingLabelItem $item) use (&$count): void {
                $this->dispatchItem($item);
                $count++;
            });

        return $count;
    }

    public function dispatchItem(BulkShippingLabelItem $item): void
    {
        ProcessBulkShippingLabelItemJob::dispatch(
            $item->batch_id,
            $item->id,
            (string) $item->order_id,
            strtolower((string) $item->channel),
        );
    }

    public function stageDownloadedLabel(string $orderId, string $bytes): int
    {
        if ($bytes === '') {
            return 0;
        }

        $disk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
        $items = BulkShippingLabelItem::query()
            ->where('order_id', $orderId)
            ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
            ->get();

        foreach ($items as $item) {
            $this->stageDownloadedLabelItem($item, $bytes, $disk);
        }

        return $items->count();
    }

    public function stageReadyLabelForBatchItem(string $batchId, string $orderId, string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }

        $item = BulkShippingLabelItem::query()
            ->where('batch_id', $batchId)
            ->where('order_id', $orderId)
            ->where('status', '!=', BulkShippingLabelItem::STATUS_SKIPPED_INSTANT)
            ->first();

        if ($item === null) {
            return false;
        }

        $disk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
        if ($item->isTerminal() && $item->ready_pdf_path && $disk->exists($item->ready_pdf_path)) {
            return true;
        }

        if (! $this->stageReadyLabelItem($item, $bytes, $disk)) {
            return false;
        }

        $this->publishBatchProgress($batchId);

        return true;
    }

    public function finalizeSynchronouslyIfReady(string $batchId): bool
    {
        $batch = BulkShippingLabelBatch::find($batchId);
        if ($batch === null || $batch->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
            return false;
        }
        if ($batch->items()->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)->exists()) {
            return false;
        }

        $this->finalizeInWorker($batchId);

        return true;
    }

    public function transformDownloadedItem(BulkShippingLabelItem $item): void
    {
        $item->refresh();
        if ($item->status !== BulkShippingLabelItem::STATUS_TRANSFORMING || ! $item->raw_pdf_path) {
            return;
        }

        $disk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
        if (! $disk->exists($item->raw_pdf_path)) {
            throw new \RuntimeException('File PDF sementara tidak ditemukan.');
        }

        $readyPath = "items/{$item->batch_id}/{$item->id}/ready.pdf";
        if (! $disk->copy($item->raw_pdf_path, $readyPath)) {
            throw new \RuntimeException('File PDF sementara tidak dapat dipindahkan ke label siap.');
        }
        $disk->delete($item->raw_pdf_path);

        $item->update([
            'status' => BulkShippingLabelItem::STATUS_READY,
            'ready_pdf_path' => $readyPath,
            'raw_pdf_path' => null,
            'reason' => null,
        ]);

        $this->publishBatchProgress((string) $item->batch_id);
        $this->finalizeAffectedBatches(collect([$item]));
    }

    public function markTransformFailed(string $batchId, string $itemId, string $reason): void
    {
        $item = BulkShippingLabelItem::query()
            ->whereKey($itemId)
            ->where('batch_id', $batchId)
            ->first();

        if ($item) {
            if ($item->raw_pdf_path) {
                Storage::disk(config('bulk-labels.spool_disk', 'print_spool'))->delete($item->raw_pdf_path);
            }
            $this->fail($item, $reason);
        }
    }

    public function processPendingItem(BulkShippingLabelItem $item): bool
    {
        $order = $item->relationLoaded('order')
            ? $item->order
            : SalesOrder::find($item->order_id);
        if (! $order) {
            $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);

            return true;
        }
        if ($order->is_canceled || $order->status === 'cancelled'
            || $order->channel_cancel_status === 'accepted' || $order->cancel_requested_at !== null) {
            $this->fail($item, 'Pesanan dibatalkan atau sedang menunggu pembatalan.');

            return true;
        }

        $waitingStatus = match ($item->channel) {
            self::CHANNEL_SHOPEE => BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
            self::CHANNEL_TIKTOK => BulkShippingLabelItem::STATUS_WAITING_TIKTOK_PREP,
            self::CHANNEL_LAZADA => BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP,
            default => null,
        };
        if ($order->shipping_label_status === 'preparing' && $waitingStatus !== null) {

            $item->update([
                'status' => $waitingStatus,
                'reason' => null,
            ]);
            app(ShippingLabelPreparationDispatcher::class)->dispatch($order);
            if (in_array($order->fresh()?->shipping_label_status, ['ready', 'failed', 'self_design_required'], true)) {
                $this->onOrderLabelReady((string) $order->id);
            }
            $this->publishBatchProgress($item->batch_id);

            return true;
        }

        $limit = max(1, (int) config('queue.routing.labels.rate_limit_attempts', 5));
        $decay = max(1, (int) config('queue.routing.labels.rate_limit_decay_seconds', 1));
        $key = sprintf('bulk-label:%s:%s', $item->channel, $order->channel_shop_id ?: 'internal');

        if (! RateLimiter::attempt($key, $limit, fn (): bool => true, $decay)) {
            return false;
        }

        $this->processItem($item, null);

        return true;
    }

    public function recoverStaleMarketplaceItems(
        BulkShippingLabelBatch $batch,
        \DateTimeInterface $threshold,
    ): int {
        $items = $batch->items()
            ->whereIn('status', [
                BulkShippingLabelItem::STATUS_WAITING_MARKETPLACE,
                BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
                BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP,
                BulkShippingLabelItem::STATUS_WAITING_TIKTOK_PREP,
            ])
            ->where('updated_at', '<', $threshold)
            ->with('order')
            ->get();

        $recovered = 0;

        foreach ($items as $item) {
            $order = $item->order?->fresh();

            if (! $order) {
                $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);
                $recovered++;

                continue;
            }

            if ($order->shipping_label_status === 'preparing') {
                if (app(ShippingLabelPreparationDispatcher::class)->dispatch($order)) {
                    $item->update(['updated_at' => now()]);
                    $recovered++;
                }

                continue;
            }

            if ($order->shipping_label_status === 'ready') {
                $item->update([
                    'status' => BulkShippingLabelItem::STATUS_PENDING,
                    'reason' => null,
                    'updated_at' => now(),
                ]);
                $this->dispatchItem($item->fresh());
                $recovered++;

                continue;
            }

            if (in_array($order->shipping_label_status, ['failed', 'self_design_required'], true)) {
                $this->onOrderLabelReady((string) $order->id);
                $recovered++;

                continue;
            }

            $item->update([
                'status' => BulkShippingLabelItem::STATUS_PENDING,
                'reason' => null,
                'updated_at' => now(),
            ]);
            $this->dispatchItem($item->fresh());
            $recovered++;
        }

        if ($recovered > 0) {
            $this->publishBatchProgress((string) $batch->id);
        }

        return $recovered;
    }

    public function markItemCrashed(string $batchId, string $itemId): void
    {
        $item = BulkShippingLabelItem::query()
            ->whereKey($itemId)
            ->where('batch_id', $batchId)
            ->first(['id', 'order_id']);

        if (! $item) {
            return;
        }

        $items = $this->failOrderItems(
            (string) $item->order_id,
            BulkShippingLabelItem::REASON_BATCH_CRASHED,
        );

        $this->finalizeAffectedBatches($items);
    }

    public function resolveChannelOptions(string $channel): array
    {
        return match ($channel) {
            self::CHANNEL_SHOPEE => [
                'document_type' => 'THERMAL_AIR_WAYBILL',
                'document_size' => null,
            ],
            self::CHANNEL_TIKTOK => [
                'document_type' => 'SHIPPING_LABEL',
                'document_size' => 'A6',
            ],
            self::CHANNEL_LAZADA => [
                'document_type' => 'PDF',
                'document_size' => null,
            ],
            default => [],
        };
    }

    private function resolveTargetSize(BulkShippingLabelBatch $batch): array
    {
        $opts = $batch->per_channel_opts ?? [];
        $sizeKey = $opts['document_size'] ?? self::DEFAULT_SIZE;

        return self::SIZE_DIMENSIONS_MM[$sizeKey] ?? self::SIZE_DIMENSIONS_MM[self::DEFAULT_SIZE];
    }

    public function preprocessPdfForFpdi(string $srcPdfBytes): string
    {
        $gs = trim((string) @shell_exec('command -v gs 2>/dev/null'));
        if ($gs === '') {
            return $srcPdfBytes;
        }

        $inTmp = tempnam(sys_get_temp_dir(), 'lbl_in_');
        $outTmp = tempnam(sys_get_temp_dir(), 'lbl_out_');
        try {
            file_put_contents($inTmp, $srcPdfBytes);
            $cmd = sprintf(
                '%s -q -dNOPAUSE -dBATCH -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -sOutputFile=%s %s 2>&1',
                escapeshellcmd($gs),
                escapeshellarg($outTmp),
                escapeshellarg($inTmp),
            );
            @shell_exec($cmd);
            if (is_file($outTmp) && filesize($outTmp) > 0) {
                $result = file_get_contents($outTmp);
                if ($result !== false && $result !== '') {
                    return $result;
                }
            }
        } catch (Throwable $e) {
            Log::warning('preprocessPdfForFpdi: gs gagal, pakai bytes asli', ['error' => $e->getMessage()]);
        } finally {
            @unlink($inTmp);
            @unlink($outTmp);
        }

        return $srcPdfBytes;
    }

    private function isShopeeA4Sheet(?string $channel, float $srcW, float $srcH): bool
    {
        if ($channel !== self::CHANNEL_SHOPEE) {
            return false;
        }

        $portrait = $srcW >= 200 && $srcW <= 220 && $srcH >= 285 && $srcH <= 302;
        $landscape = $srcW >= 285 && $srcW <= 302 && $srcH >= 200 && $srcH <= 220;

        return $portrait || $landscape;
    }

    private function detectInkBBoxMm(string $pdfBytes, int $page): ?array
    {
        $gs = trim((string) @shell_exec('command -v gs 2>/dev/null'));
        if ($gs === '') {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lbl_bbox_');
        try {
            file_put_contents($tmp, $pdfBytes);
            $cmd = sprintf(
                '%s -q -dNOPAUSE -dBATCH -dFirstPage=%d -dLastPage=%d -sDEVICE=bbox %s 2>&1',
                escapeshellcmd($gs),
                $page,
                $page,
                escapeshellarg($tmp),
            );
            $out = (string) @shell_exec($cmd);

            if (! preg_match('/%%HiResBoundingBox:\s*([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)\s+([\d.-]+)/', $out, $m)) {
                return null;
            }

            $ptToMm = 25.4 / 72.0;
            $box = [
                (float) $m[1] * $ptToMm,
                (float) $m[2] * $ptToMm,
                (float) $m[3] * $ptToMm,
                (float) $m[4] * $ptToMm,
            ];

            return ($box[2] > $box[0] && $box[3] > $box[1]) ? $box : null;
        } catch (Throwable $e) {
            Log::warning('detectInkBBoxMm gagal', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($tmp);
        }
    }

    private function placementFromBBox(float $srcW, float $srcH, array $bbox, float $targetW, float $targetH): ?array
    {
        $x0 = max(0.0, min($bbox[0], $srcW));
        $y0 = max(0.0, min($bbox[1], $srcH));
        $x1 = max(0.0, min($bbox[2], $srcW));
        $y1 = max(0.0, min($bbox[3], $srcH));

        $boxW = $x1 - $x0;
        $boxH = $y1 - $y0;

        if ($boxW < self::BBOX_MIN_SIDE_MM || $boxH < self::BBOX_MIN_SIDE_MM) {
            return null;
        }

        if (($boxW * $boxH) >= ($srcW * $srcH * self::BBOX_FULL_SHEET_RATIO)) {
            return null;
        }

        $usableW = max(1.0, $targetW - 2 * self::BBOX_SAFE_MARGIN_MM);
        $usableH = max(1.0, $targetH - 2 * self::BBOX_SAFE_MARGIN_MM);
        $scale = min($usableW / $boxW, $usableH / $boxH);

        $topOffset = $srcH - $y1;

        return [
            -$x0 * $scale + ($targetW - $boxW * $scale) / 2,
            -$topOffset * $scale + self::BBOX_SAFE_MARGIN_MM,
            $srcW * $scale,
            $srcH * $scale,
        ];
    }

    private function placementOnTarget(float $srcW, float $srcH, float $targetW, float $targetH, ?string $channel = null): array
    {
        $isShopeeA4Portrait = $channel === self::CHANNEL_SHOPEE
            && $srcW >= 200 && $srcW <= 220
            && $srcH >= 285 && $srcH <= 302;

        $isShopeeA4Landscape = $channel === self::CHANNEL_SHOPEE
            && $srcW >= 285 && $srcW <= 302
            && $srcH >= 200 && $srcH <= 220;

        if ($isShopeeA4Landscape) {
            $effW = $srcW / 2;
            $effH = $srcH;
        } elseif ($isShopeeA4Portrait) {
            $effW = $srcW / 2;
            $effH = $srcH / 2;
        } else {
            $effW = $srcW;
            $effH = $srcH;
        }

        if ($channel === self::CHANNEL_TIKTOK || $channel === self::CHANNEL_LAZADA) {
            $scale = $targetW / $effW;
            $renderW = $effW * $scale;
            $renderH = $effH * $scale;
            $x = ($targetW - $renderW) / 2;
            $y = 0.0;

            return [$x, $y, $srcW * $scale, $srcH * $scale];
        }

        $aspect = $effH / $effW;
        $scale = $aspect > 1.6
            ? $targetW / $effW
            : min($targetW / $effW, $targetH / $effH);

        $renderW = $effW * $scale;
        $renderH = $effH * $scale;

        $x = ($targetW - $renderW) / 2;
        $y = 0.0;

        return [$x, $y, $srcW * $scale, $srcH * $scale];
    }

    public function normalizeToTarget(string $srcPdfBytes, string $sizeKey = self::DEFAULT_SIZE, ?string $channel = null): string
    {
        [$targetW, $targetH] = self::SIZE_DIMENSIONS_MM[$sizeKey] ?? self::SIZE_DIMENSIONS_MM[self::DEFAULT_SIZE];

        try {
            $prepared = $this->preprocessPdfForFpdi($srcPdfBytes);
            $out = new Fpdi('P', 'mm', [$targetW, $targetH]);
            $pageCount = $out->setSourceFile(StreamReader::createByString($prepared));
            for ($p = 1; $p <= $pageCount; $p++) {
                $tpl = $out->importPage($p);
                $src = $out->getTemplateSize($tpl);
                $srcW = (float) $src['width'];
                $srcH = (float) $src['height'];

                $placement = null;
                if (! $this->isShopeeA4Sheet($channel, $srcW, $srcH)) {
                    $bbox = $this->detectInkBBoxMm($prepared, $p);
                    if ($bbox !== null) {
                        $placement = $this->placementFromBBox($srcW, $srcH, $bbox, $targetW, $targetH);
                    }
                }

                $placement ??= $this->placementOnTarget($srcW, $srcH, $targetW, $targetH, $channel);

                [$x, $y, $drawW, $drawH] = $placement;

                $out->AddPage('P', [$targetW, $targetH]);
                $out->useTemplate($tpl, $x, $y, $drawW, $drawH, false);
            }

            return $out->Output('S');
        } catch (Throwable $e) {
            Log::warning('normalizeToTarget: FPDI gagal, return PDF asli', [
                'error' => $e->getMessage(),
                'channel' => $channel,
                'size_key' => $sizeKey,
            ]);

            return $srcPdfBytes;
        }
    }

    private function processTikTokBatch($items, ?array $perChannelOpts): void
    {
        $options = $this->resolveChannelOptions(self::CHANNEL_TIKTOK);
        $orders = SalesOrder::query()
            ->whereIn('id', $items->pluck('order_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $urlMap = [];
        foreach ($items as $item) {
            try {
                $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);
                $order = $orders->get($item->order_id);
                if (! $order) {
                    $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);

                    continue;
                }
                try {
                    $result = $this->salesOrderService->getShippingLabel($order, $options);
                } catch (ShippingLabelPreparingException $e) {
                    $this->waitForMarketplace($item, $order);

                    continue;
                }

                $urls = $this->labelUrls($result);
                if ($urls !== []) {
                    $urlMap[$item->id] = $urls;

                    continue;
                }

                $bytes = $this->resolveLabelBytes($result);
                if ($bytes !== null) {
                    $this->succeed($item, $bytes, $order);

                    continue;
                }
                $this->fail($item, 'tiktok_no_label');
            } catch (Throwable $e) {
                Log::warning('TikTok batch prep failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
                $this->fail($item, substr($e->getMessage(), 0, 250));
            }
        }

        if (empty($urlMap)) {
            return;
        }

        $downloads = [];
        foreach ($urlMap as $itemId => $urls) {
            foreach ($urls as $index => $url) {
                $downloads["{$itemId}:{$index}"] = $url;
            }
        }

        $downloadedBytes = [];
        $failedDownloads = [];
        foreach (array_chunk($downloads, self::TIKTOK_PARALLEL_LANES, true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk) {
                $reqs = [];
                foreach ($chunk as $downloadKey => $url) {
                    $reqs[$downloadKey] = $pool
                        ->as((string) $downloadKey)
                        ->timeout(self::TIKTOK_DOWNLOAD_TIMEOUT)
                        ->retry(self::TIKTOK_DOWNLOAD_RETRIES, 500)
                        ->get($url);
                }

                return $reqs;
            });

            foreach ($chunk as $downloadKey => $_url) {
                [$itemId] = explode(':', (string) $downloadKey, 2);
                $response = $responses[$downloadKey] ?? null;
                if ($response instanceof Throwable || $response === null || ! $response->successful()) {
                    $failedDownloads[$itemId] = true;

                    continue;
                }
                $downloadedBytes[$itemId][] = $response->body();
            }
        }

        foreach ($urlMap as $itemId => $urls) {
            $item = $items->firstWhere('id', $itemId);
            if (! $item) {
                continue;
            }

            $bytes = $downloadedBytes[$itemId] ?? [];
            if (isset($failedDownloads[$itemId]) || count($bytes) !== count($urls)) {
                $this->fail($item, 'tiktok_download_failed');

                continue;
            }

            $this->succeed(
                $item,
                $this->mergePdfBytes($bytes),
                $orders->get($item->order_id),
            );
        }
    }

    public function processItem(BulkShippingLabelItem $item, ?array $perChannelOpts): void
    {
        try {
            $item->update(['status' => BulkShippingLabelItem::STATUS_DOWNLOADING]);
            $order = $item->relationLoaded('order')
                ? $item->order
                : SalesOrder::find($item->order_id);
            if (! $order) {
                $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);

                return;
            }

            $options = $this->resolveChannelOptions($item->channel);

            match ($item->channel) {
                self::CHANNEL_SHOPEE => $this->processShopee($item, $order, $options),
                self::CHANNEL_TIKTOK => $this->processTikTok($item, $order, $options),
                self::CHANNEL_LAZADA => $this->processLazada($item, $order, $options),
                default => $this->fail($item, BulkShippingLabelItem::REASON_CHANNEL_UNSUPPORTED),
            };
        } catch (Throwable $e) {
            Log::error('BulkShippingLabelService.processItem failed', [
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
            $this->fail($item, substr($e->getMessage(), 0, 250));
        }
    }

    private function processShopee(BulkShippingLabelItem $item, SalesOrder $order, array $options): void
    {
        try {
            $result = $this->salesOrderService->getShippingLabel($order, $options);
        } catch (ShippingLabelPreparingException $e) {

            $this->waitForMarketplace($item, $order);

            return;
        } catch (\RuntimeException $e) {
            $msg = strtolower($e->getMessage());
            $latestOrder = $order->fresh();
            $persistedFailure = data_get($latestOrder?->shipping_label_raw_data, 'shipping_label_failure.reason');
            $terminalReason = match (true) {
                Str::contains($msg, ['parcel has been shipped', 'already shipped', 'sudah dikirim']) => BulkShippingLabelItem::REASON_PARCEL_ALREADY_SHIPPED,
                $persistedFailure === BulkShippingLabelItem::REASON_PARCEL_ALREADY_SHIPPED => BulkShippingLabelItem::REASON_PARCEL_ALREADY_SHIPPED,
                $latestOrder?->shipping_label_status === 'self_design_required' => BulkShippingLabelItem::REASON_SELF_DESIGN,
                default => null,
            };

            if ($terminalReason !== null) {
                $this->fail($item, $terminalReason);

                return;
            }

            $transient = $e instanceof ConnectionException
                || ($e instanceof ShopeeApiException && $e->isRetryable())
                || Str::contains($msg, [
                    'timeout',
                    'timed out',
                    'temporarily',
                    'try again',
                    'not ready',
                    'belum siap',
                    'http error [5',
                ]);

            if ($transient) {
                $this->waitForMarketplace($item, $order);

                return;
            }

            $this->fail($item, BulkShippingLabelItem::REASON_SHOPEE_PREP_FAILED);

            return;
        }

        $bytes = $this->resolveLabelBytes($result);
        if ($bytes !== null) {
            $this->succeed($item, $bytes, $order);

            return;
        }

        $this->waitForMarketplace($item, $order);
    }

    private function processTikTok(BulkShippingLabelItem $item, SalesOrder $order, array $options): void
    {
        try {
            $result = $this->salesOrderService->getShippingLabel($order, $options);
        } catch (ShippingLabelPreparingException $e) {
            $this->waitForMarketplace($item, $order);

            return;
        }

        $bytes = $this->resolveLabelBytes($result);
        if ($bytes !== null) {
            $this->succeed($item, $bytes, $order);

            return;
        }

        $urls = $this->labelUrls($result);
        if ($urls !== []) {
            try {
                $documents = [];
                foreach ($urls as $url) {
                    $response = Http::timeout(self::TIKTOK_DOWNLOAD_TIMEOUT)
                        ->retry(self::TIKTOK_DOWNLOAD_RETRIES, 500)
                        ->get($url);
                    if (! $response->successful()) {
                        throw new \RuntimeException('TikTok document download failed.');
                    }
                    $documents[] = $response->body();
                }
                if ($documents !== []) {
                    $this->succeed($item, $this->mergePdfBytes($documents), $order);

                    return;
                }
            } catch (Throwable $e) {
                Log::warning('TikTok single label download failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->fail($item, $urls !== []
            ? 'tiktok_download_failed'
            : 'tiktok_no_label');
    }

    private function labelUrls(array $result): array
    {
        $urls = $result['urls'] ?? [];
        if (! is_array($urls)) {
            $urls = [];
        }

        $singleUrl = $result['url'] ?? ($result['doc_url'] ?? null);
        if (is_string($singleUrl) && $singleUrl !== '') {
            $urls[] = $singleUrl;
        }

        return array_values(array_unique(array_filter($urls, static fn ($url): bool => is_string($url) && $url !== '')));
    }

    public function mergePdfBytes(array $documents): string
    {
        if ($documents === [] || count($documents) > 500
            || array_sum(array_map('strlen', $documents)) > (int) config('bulk-labels.cache_max_bytes', 32 * 1024 * 1024)) {
            throw new \RuntimeException('Ukuran atau jumlah dokumen label melebihi batas aman.');
        }
        if (count($documents) === 1) {
            return $documents[0];
        }

        try {
            $pdf = new Fpdi;
            $totalPages = 0;
            foreach ($documents as $document) {
                $pageCount = $pdf->setSourceFile(StreamReader::createByString($document));
                $totalPages += $pageCount;
                if ($pageCount < 1 || $totalPages > 1000) {
                    throw new \RuntimeException('Jumlah halaman label kosong atau melebihi batas aman.');
                }
                for ($page = 1; $page <= $pageCount; $page++) {
                    $phpLimit = ini_parse_quantity((string) ini_get('memory_limit'));
                    $safeMemory = $phpLimit > 0 ? min(160 * 1024 * 1024, $phpLimit - 48 * 1024 * 1024) : 160 * 1024 * 1024;
                    if (memory_get_usage(true) > $safeMemory) {
                        throw new \RuntimeException('Penggabungan label mencapai batas memori aman.');
                    }
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($template);
                }
            }

            return $pdf->Output('S');
        } catch (Throwable $e) {
            Log::warning('TikTok label multi-package PDF merge gagal', [
                'documents' => count($documents),
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Label TikTok multi-package tidak dapat digabungkan.', 0, $e);
        }
    }

    private function processLazada(BulkShippingLabelItem $item, SalesOrder $order, array $options): void
    {
        try {
            $result = $this->salesOrderService->getShippingLabel($order, $options);
        } catch (ShippingLabelPreparingException $e) {

            $this->waitForMarketplace($item, $order);

            return;
        } catch (ChannelLabelUnsupportedException $e) {
            $this->fail($item, BulkShippingLabelItem::REASON_SELF_DESIGN);

            return;
        }

        $bytes = $this->resolveLabelBytes($result);
        if ($bytes !== null) {
            $this->succeed($item, $bytes, $order);

            return;
        }

        $hadPayload = ! empty($result['url']) || ! empty($result['doc_url'])
            || ! empty($result['document_base64']) || ! empty($result['data']);

        if ($hadPayload) {

            $this->fail($item, BulkShippingLabelItem::REASON_LAZADA_DECODE_FAILED);

            return;
        }

        $this->waitForMarketplace($item, $order);
    }

    private function waitForMarketplace(BulkShippingLabelItem $item, SalesOrder $order): void
    {
        $item->update(['status' => BulkShippingLabelItem::STATUS_WAITING_MARKETPLACE, 'reason' => null]);
        app(ShippingLabelPreparationDispatcher::class)->dispatch($order);

        if (in_array($order->fresh()?->shipping_label_status, ['ready', 'failed', 'self_design_required'], true)) {
            $this->onOrderLabelReady((string) $order->id);
        }
    }

    private function resolveLabelBytes(array $result): ?string
    {
        if (! empty($result['document_base64']) && is_string($result['document_base64'])) {
            $decoded = base64_decode($result['document_base64'], true);

            return $decoded === false ? null : $decoded;
        }

        if (! empty($result['bytes']) && is_string($result['bytes'])) {
            return $result['bytes'];
        }

        if (! empty($result['data']) && is_string($result['data'])) {
            $decoded = base64_decode($result['data'], true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        $url = $result['url'] ?? ($result['doc_url'] ?? null);
        if (! empty($url) && is_string($url)) {
            return $this->downloadUrl($url);
        }

        return null;
    }

    private function downloadUrl(string $url): ?string
    {
        $response = Http::timeout(self::TIKTOK_DOWNLOAD_TIMEOUT)
            ->retry(self::TIKTOK_DOWNLOAD_RETRIES, 500)
            ->get($url);
        if (! $response->successful()) {
            return null;
        }

        return $response->body();
    }

    private function succeed(
        BulkShippingLabelItem $item,
        string $bytes,
        ?SalesOrder $order = null,
    ): void {
        $orderId = (string) ($order?->id ?? $item->order_id);
        $fresh = SalesOrder::find($orderId);
        if (! $fresh || $fresh->is_canceled || $fresh->status === 'cancelled'
            || $fresh->channel_cancel_status === 'accepted' || $fresh->cancel_requested_at !== null) {
            $this->fail($item, 'Pesanan dibatalkan atau sedang menunggu pembatalan.');

            return;
        }
        if ($order && ($fresh->tracking_number !== $order->tracking_number
            || $fresh->channel_package_ids !== $order->channel_package_ids)) {
            $this->fail($item, 'Data paket berubah saat label diunduh. Muat ulang pesanan dan coba kembali.');

            return;
        }
        if ($order) {
            $this->salesOrderService->cacheShippingLabelBytes(
                $order,
                $bytes,
                $order->shipping_label_doc_type,
            );
        }

        if ($order && $order->shipping_label_status !== 'ready') {
            $order->forceFill([
                'shipping_label_status' => 'ready',
                'shipping_label_prepared_at' => now(),
            ])->saveQuietly();
        }

        if ($this->stageDownloadedLabel($orderId, $bytes) === 0) {
            $this->stageDownloadedLabel((string) $item->order_id, $bytes);
        }
    }

    private function hydrateReusableLabels(
        EloquentCollection $items,
        EloquentCollection $orders,
    ): void {
        $pendingItems = $items->filter(
            static fn (BulkShippingLabelItem $item): bool => $item->status === BulkShippingLabelItem::STATUS_PENDING,
        );

        if ($pendingItems->isEmpty()) {
            return;
        }

        $orderIds = $pendingItems->pluck('order_id')->map('strval')->values();
        $currentItemIds = $pendingItems->pluck('id')->values();
        $candidatesByOrder = BulkShippingLabelItem::query()
            ->with('batch:id,status,per_channel_opts')
            ->whereIn('order_id', $orderIds)
            ->whereNotIn('id', $currentItemIds)
            ->whereIn('status', BulkShippingLabelItem::COMPLETED_STATUSES)
            ->whereNotNull('ready_pdf_path')
            ->whereHas('batch', function ($query): void {
                $query->whereIn('status', [
                    BulkShippingLabelBatch::STATUS_PROCESSING,
                    BulkShippingLabelBatch::STATUS_READY,
                ]);
            })
            ->orderByDesc('updated_at')
            ->get()
            ->groupBy('order_id');

        $disk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));

        foreach ($pendingItems as $item) {
            $previous = $candidatesByOrder
                ->get($item->order_id, collect())
                ->first(static fn (BulkShippingLabelItem $candidate): bool => $candidate->ready_pdf_path !== null
                    && $disk->exists($candidate->ready_pdf_path),
                );

            if ($previous) {
                $targetPath = "items/{$item->batch_id}/{$item->id}/ready.pdf";
                if ($disk->copy($previous->ready_pdf_path, $targetPath)) {
                    $item->update([
                        'status' => BulkShippingLabelItem::STATUS_READY,
                        'ready_pdf_path' => $targetPath,
                        'raw_pdf_path' => null,
                        'downloaded_at' => now(),
                        'reason' => null,
                    ]);

                    continue;
                }

                Log::warning('Bulk label reuse artifact tidak dapat disalin, lanjut ke cache order', [
                    'order_id' => $item->order_id,
                    'source_path' => $previous->ready_pdf_path,
                ]);
            }

            $order = $orders->get($item->order_id);
            if (! $order) {
                continue;
            }

            $sourceBytes = $this->salesOrderService->cachedShippingLabelBytes($order);
            if ($sourceBytes !== null) {
                $this->stageDownloadedLabelItem($item, $sourceBytes, $disk);
            }
        }
    }

    private function stageDownloadedLabelItem(
        BulkShippingLabelItem $item,
        string $bytes,
        ?Filesystem $disk = null,
    ): void {
        $disk ??= Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
        $path = "items/{$item->batch_id}/{$item->id}/ready.pdf";
        if (! $disk->put($path, $bytes)) {
            throw new \RuntimeException('File label tidak dapat disimpan ke print spool.');
        }

        if ($item->raw_pdf_path && $item->raw_pdf_path !== $path) {
            $disk->delete($item->raw_pdf_path);
        }

        $item->update([
            'status' => BulkShippingLabelItem::STATUS_READY,
            'raw_pdf_path' => null,
            'ready_pdf_path' => $path,
            'downloaded_at' => now(),
            'reason' => null,
        ]);

        $this->publishBatchProgress((string) $item->batch_id);
        $this->finalizeAffectedBatches(collect([$item]));
    }

    private function stageReadyLabelItem(
        BulkShippingLabelItem $item,
        string $bytes,
        Filesystem $disk,
    ): bool {
        if ($bytes === '') {
            return false;
        }

        $path = "items/{$item->batch_id}/{$item->id}/ready.pdf";
        if (! $disk->put($path, $bytes)) {
            throw new \RuntimeException('File label siap tidak dapat disimpan ke print spool.');
        }

        $item->update([
            'status' => BulkShippingLabelItem::STATUS_READY,
            'ready_pdf_path' => $path,
            'raw_pdf_path' => null,
            'downloaded_at' => now(),
            'reason' => null,
        ]);

        return true;
    }

    private function fail(BulkShippingLabelItem $item, string $reason): void
    {
        $items = $this->failOrderItems((string) $item->order_id, $reason);
        if ($items->isEmpty()) {
            $item->update([
                'status' => BulkShippingLabelItem::STATUS_FAILED,
                'reason' => $reason,
            ]);
            $items = collect([$item]);
        }

        $this->finalizeAffectedBatches($items);
    }

    private function skipInstant(BulkShippingLabelItem $item): void
    {
        $items = BulkShippingLabelItem::query()
            ->where('order_id', $item->order_id)
            ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
            ->get();

        foreach ($items as $sharedItem) {
            $sharedItem->update([
                'status' => BulkShippingLabelItem::STATUS_SKIPPED_INSTANT,
                'reason' => BulkShippingLabelItem::REASON_INSTANT_COURIER,
            ]);
        }

        $this->finalizeAffectedBatches($items->isEmpty() ? collect([$item]) : $items);
    }

    private function hydrateCachedLabel(string $orderId): bool
    {
        $order = SalesOrder::find($orderId);
        if (! $order) {
            return false;
        }

        $bytes = $this->salesOrderService->cachedShippingLabelBytes($order);
        if ($bytes === null || $bytes === '') {
            return false;
        }

        return $this->stageDownloadedLabel($orderId, $bytes) > 0;
    }

    private function failOrderItems(string $orderId, string $reason)
    {
        $items = BulkShippingLabelItem::query()
            ->where('order_id', $orderId)
            ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
            ->get();

        foreach ($items as $sharedItem) {
            $sharedItem->update([
                'status' => BulkShippingLabelItem::STATUS_FAILED,
                'reason' => $reason,
            ]);
        }

        return $items;
    }

    public function onOrderAwbReady(string $orderId): void
    {
        $items = BulkShippingLabelItem::where('order_id', $orderId)
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_AWB)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $order = SalesOrder::find($orderId);

        if (! $order || empty($order->tracking_number)) {
            return;
        }

        foreach ($items as $item) {
            $claimed = BulkShippingLabelItem::query()
                ->whereKey($item->id)
                ->where('status', BulkShippingLabelItem::STATUS_WAITING_AWB)
                ->update([
                    'status' => $item->channel === self::CHANNEL_SHOPEE
                        ? BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP
                        : BulkShippingLabelItem::STATUS_PENDING,
                    'updated_at' => now(),
                ]);

            if ($claimed === 1) {
                if ($item->channel === self::CHANNEL_SHOPEE) {
                    CollectShopeeLabelPreparationJob::dispatch((string) $item->batch_id)->delay(now()->addSeconds(2));
                } else {
                    $this->dispatchItem($item);
                }
            }
        }

        $this->publishBatchProgressForItems($items);
    }

    public function onOrderAwbSkippedInstant(string $orderId): void
    {
        $items = BulkShippingLabelItem::where('order_id', $orderId)
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_AWB)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        foreach ($items as $item) {
            $this->skipInstant($item);
        }

        $this->finalizeAffectedBatches($items);
    }

    public function onOrderAwbGaveUp(string $orderId, string $reason): void
    {
        $items = BulkShippingLabelItem::where('order_id', $orderId)
            ->where('status', BulkShippingLabelItem::STATUS_WAITING_AWB)
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        foreach ($items as $item) {
            $this->fail($item, $reason);
        }

        $this->finalizeAffectedBatches($items);
    }

    public function onOrderLabelReady(string $orderId): void
    {
        $items = BulkShippingLabelItem::where('order_id', $orderId)
            ->whereIn('status', [
                BulkShippingLabelItem::STATUS_WAITING_MARKETPLACE,
                BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
                BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP,
                BulkShippingLabelItem::STATUS_WAITING_TIKTOK_PREP,
            ])
            ->get();

        if ($items->isEmpty()) {
            return;
        }

        $order = SalesOrder::find($orderId);
        if (! $order) {
            foreach ($items as $item) {
                $this->fail($item, BulkShippingLabelItem::REASON_NO_AWB);
            }
            $this->finalizeAffectedBatches($items);

            return;
        }

        $labelStatus = $order->shipping_label_status ?? null;
        $persistedFailure = data_get($order->shipping_label_raw_data, 'shipping_label_failure.reason');

        foreach ($items as $item) {
            try {
                $isLazada = $item->channel === self::CHANNEL_LAZADA;

                if ($labelStatus === 'ready') {
                    $claimed = BulkShippingLabelItem::query()
                        ->whereKey($item->id)
                        ->whereIn('status', [
                            BulkShippingLabelItem::STATUS_WAITING_MARKETPLACE,
                            BulkShippingLabelItem::STATUS_WAITING_SHOPEE_PREP,
                            BulkShippingLabelItem::STATUS_WAITING_LAZADA_PREP,
                            BulkShippingLabelItem::STATUS_WAITING_TIKTOK_PREP,
                        ])
                        ->update([
                            'status' => BulkShippingLabelItem::STATUS_PENDING,
                            'updated_at' => now(),
                        ]);

                    if ($claimed === 1) {
                        $this->dispatchItem($item);
                    }
                } elseif ($persistedFailure === BulkShippingLabelItem::REASON_PARCEL_ALREADY_SHIPPED) {
                    $this->fail($item, BulkShippingLabelItem::REASON_PARCEL_ALREADY_SHIPPED);
                } elseif ($labelStatus === 'self_design_required') {
                    $this->fail($item, BulkShippingLabelItem::REASON_SELF_DESIGN);
                } elseif ($labelStatus === 'failed') {
                    $this->fail($item, $isLazada
                        ? BulkShippingLabelItem::REASON_LAZADA_PREP_FAILED
                        : BulkShippingLabelItem::REASON_SHOPEE_PREP_FAILED);
                }

            } catch (Throwable $e) {
                Log::warning('onOrderLabelReady: transition failed', [
                    'item_id' => $item->id,
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
                $this->fail($item, substr($e->getMessage(), 0, 250));
            }
        }

        $this->finalizeAffectedBatches($items);
        $this->publishBatchProgressForItems($items);
    }

    private function publishBatchProgressForItems($items): void
    {
        $items->pluck('batch_id')
            ->unique()
            ->each(fn (string $batchId) => $this->publishBatchProgress($batchId));
    }

    private function publishBatchProgress(string $batchId): void
    {
        $batch = BulkShippingLabelBatch::find($batchId);
        if (! $batch) {
            return;
        }

        $batch->recomputeCounts();
        $batch->refresh();

        app(RealtimeEventPublisher::class)->publish(
            (string) $batch->user_id,
            'bulk-label:'.$batch->id,
            'bulk-label.progress',
            [
                'batch_id' => (string) $batch->id,
                'status' => $batch->status,
                'total' => (int) $batch->total_count,
                'done' => (int) $batch->done_count,
                'failed' => (int) $batch->failed_count,
                'skipped' => (int) $batch->skipped_count,
                'file_available' => $batch->file_purged_at === null
                    && ($batch->merged_pdf_path !== null || $batch->print_pdf_path !== null),
                'finished_at' => $batch->finished_at?->toIso8601String(),
            ],
        );
    }

    private function finalizeAffectedBatches($items): void
    {
        $batchIds = $items->pluck('batch_id')->unique()->values();
        foreach ($batchIds as $batchId) {
            $batch = BulkShippingLabelBatch::find($batchId);
            if ($batch) {
                $this->tryFinalize($batch);
            }
        }
    }

    public function tryFinalize(BulkShippingLabelBatch $batch): void
    {
        $fresh = BulkShippingLabelBatch::find($batch->id);
        if (! $fresh || $fresh->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
            return;
        }

        if ($fresh->items()->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)->exists()) {
            return;
        }

        $this->dispatchFinalizeJob($fresh);
    }

    public function forceFinalize(BulkShippingLabelBatch $batch, string $reason): void
    {
        $fresh = BulkShippingLabelBatch::find($batch->id);
        if (! $fresh || $fresh->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
            return;
        }

        $fresh->items()
            ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
            ->update([
                'status' => BulkShippingLabelItem::STATUS_FAILED,
                'reason' => $reason,
                'updated_at' => now(),
            ]);

        $this->dispatchFinalizeJob($fresh);
    }

    private function dispatchFinalizeJob(BulkShippingLabelBatch $batch): void
    {
        $pending = FinalizeBulkShippingLabelBatchJob::dispatch((string) $batch->id);
        $delay = max(0, (int) config('bulk-labels.finalize_retry_delay_seconds', 0));

        if ($delay > 0) {
            $pending->delay(now()->addSeconds($delay));
        }
    }

    public function finalizeInWorker(string $batchId): void
    {
        $lock = Cache::lock(
            "bulk-label-finalize:{$batchId}",
            (int) config('bulk-labels.finalize_lock_seconds', 900),
        );

        if (! $lock->get()) {
            throw new \RuntimeException('Finalisasi batch sedang dikerjakan worker lain.');
        }

        try {
            $fresh = BulkShippingLabelBatch::find($batchId);
            if (! $fresh || $fresh->status !== BulkShippingLabelBatch::STATUS_PROCESSING) {
                return;
            }

            if ($fresh->items()->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)->exists()) {
                return;
            }

            $this->mergeAndPersist($fresh);
        } finally {
            if ($lock->isOwnedByCurrentProcess()) {
                $lock->release();
            }
        }
    }

    public function mergeAndPersist(BulkShippingLabelBatch $batch): void
    {
        $hasItems = $batch->items()
            ->whereIn('status', BulkShippingLabelItem::COMPLETED_STATUSES)
            ->exists();

        if (! $hasItems) {
            $batch->update([
                'status' => BulkShippingLabelBatch::STATUS_FAILED,
                'finished_at' => now(),
            ]);
            $batch->recomputeCounts();

            return;
        }

        $itemsQuery = $batch->items()
            ->whereIn('status', BulkShippingLabelItem::COMPLETED_STATUSES)
            ->orderBy('created_at');

        $tempDir = sys_get_temp_dir();
        $inputPaths = [];
        $tempInputPaths = [];

        foreach ($itemsQuery->cursor() as $item) {
            if ($item->ready_pdf_path) {
                $inputPath = tempnam($tempDir, 'bulk-label-input-');
                if ($inputPath === false) {
                    throw new \RuntimeException('File sementara PDF label tidak dapat dibuat.');
                }

                $tempInputPaths[] = $inputPath;

                try {
                    $disk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
                    $source = $disk->readStream($item->ready_pdf_path);
                    if (! is_resource($source)) {
                        throw new \RuntimeException('File label siap tidak dapat dibaca.');
                    }

                    $target = fopen($inputPath, 'wb');
                    if (! is_resource($target)) {
                        fclose($source);
                        throw new \RuntimeException('File sementara PDF label tidak dapat ditulis.');
                    }

                    try {
                        stream_copy_to_stream($source, $target);
                    } finally {
                        fclose($source);
                        fclose($target);
                    }

                    clearstatcache(true, $inputPath);
                    if ((int) filesize($inputPath) <= 0) {
                        throw new \RuntimeException('File label siap kosong.');
                    }

                    $inputPaths[] = $inputPath;

                    continue;
                } catch (Throwable $e) {
                    Log::warning('Label merge input failed for item', [
                        'item_id' => $item->id,
                        'error' => $e->getMessage(),
                    ]);
                    @unlink($inputPath);
                    $tempInputPaths = array_values(array_filter(
                        $tempInputPaths,
                        static fn (string $path): bool => $path !== $inputPath,
                    ));
                }
            }

            Log::error('Ready label file missing before merge', [
                'item_id' => $item->id,
                'batch_id' => $batch->id,
            ]);

            $item->update([
                'status' => BulkShippingLabelItem::STATUS_FAILED,
                'reason' => 'ready_file_missing',
                'updated_at' => now(),
            ]);
        }

        if ($inputPaths === []) {
            $batch->update([
                'status' => BulkShippingLabelBatch::STATUS_FAILED,
                'finished_at' => now(),
            ]);
            $batch->recomputeCounts();

            return;
        }

        $path = "bulk-labels/{$batch->id}.pdf";

        $tempPath = tempnam(sys_get_temp_dir(), 'bulk-label-');
        if ($tempPath === false) {
            throw new \RuntimeException('File sementara PDF label tidak dapat dibuat.');
        }

        $bytes = 0;
        $localFirst = false;
        try {
            if (! $this->mergePdfFilesWithoutResize($inputPaths, $tempPath)) {
                throw new \RuntimeException('PDF label gagal digabung tanpa resize.');
            }

            clearstatcache(true, $tempPath);
            $bytes = (int) filesize($tempPath);
            if ($bytes <= 0) {
                throw new \RuntimeException('PDF label hasil merge kosong.');
            }

            $localFirst = (bool) config('bulk-labels.local_first', false);
            if ($localFirst) {
                $spoolPath = $path;
                $spool = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
                $stream = null;
                try {
                    $stream = fopen($tempPath, 'rb');
                    if (! is_resource($stream) || ! $spool->writeStream($spoolPath, $stream)) {
                        throw new \RuntimeException('Print spool write returned false.');
                    }
                } catch (Throwable $e) {
                    Log::warning('Print spool unavailable; falling back to archive-first', [
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage(),
                    ]);
                    $localFirst = false;
                    try {
                        $spool->delete($spoolPath);
                    } catch (Throwable) {

                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }

            if (! $localFirst) {
                $archive = Storage::disk(config('bulk-labels.archive_disk', 'documents'));
                $stream = fopen($tempPath, 'rb');
                try {
                    if (! is_resource($stream) || ! $archive->writeStream($path, $stream)) {
                        throw new \RuntimeException('PDF label tidak dapat disimpan ke object storage.');
                    }
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
        } finally {
            @unlink($tempPath);
            foreach ($tempInputPaths as $tempInputPath) {
                @unlink($tempInputPath);
            }
        }

        $itemDisk = Storage::disk(config('bulk-labels.spool_disk', 'print_spool'));
        foreach ($batch->items()->whereNotNull('ready_pdf_path')->cursor() as $item) {
            if ($item->ready_pdf_path && $itemDisk->exists($item->ready_pdf_path)) {
                $itemDisk->delete($item->ready_pdf_path);
            }
            $item->update(['ready_pdf_path' => null, 'raw_pdf_path' => null]);
        }

        $batch->update([
            'merged_pdf_path' => $localFirst ? null : $path,
            'merged_pdf_bytes' => $bytes,
            'print_pdf_path' => $localFirst ? $path : null,
            'archive_status' => $localFirst
                ? BulkShippingLabelBatch::ARCHIVE_PENDING
                : BulkShippingLabelBatch::ARCHIVE_ARCHIVED,
            'archive_pdf_bytes' => $localFirst ? null : $bytes,
            'archived_at' => $localFirst ? null : now(),
            'archive_error' => null,
            'status' => BulkShippingLabelBatch::STATUS_READY,
            'finished_at' => now(),
        ]);

        $batch->recomputeCounts();

    }

    private function mergePdfFilesWithoutResize(array $inputPaths, string $outputPath): bool
    {
        if ($inputPaths === []) {
            return false;
        }

        if (count($inputPaths) === 1) {
            return copy($inputPaths[0], $outputPath);
        }

        @unlink($outputPath);

        $pdfunite = trim((string) @shell_exec('command -v pdfunite 2>/dev/null'));
        if ($pdfunite !== '' && $this->runPdfMergeCommand(
            escapeshellarg($pdfunite)
            .' '.implode(' ', array_map('escapeshellarg', $inputPaths))
            .' '.escapeshellarg($outputPath),
        )) {
            return true;
        }

        @unlink($outputPath);
        $qpdf = trim((string) @shell_exec('command -v qpdf 2>/dev/null'));
        if ($qpdf !== '' && $this->runPdfMergeCommand(
            escapeshellarg($qpdf)
            .' --empty --pages '
            .implode(' ', array_map('escapeshellarg', $inputPaths))
            .' -- '.escapeshellarg($outputPath),
        )) {
            return true;
        }

        @unlink($outputPath);

        return $this->mergePdfFilesWithFpdiWithoutResize($inputPaths, $outputPath);
    }

    private function runPdfMergeCommand(string $command): bool
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            return false;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            Log::warning('Native PDF merge gagal; mencoba fallback berikutnya.', [
                'exit_code' => $exitCode,
                'stderr' => trim((string) $stderr),
                'stdout' => trim((string) $stdout),
            ]);

            return false;
        }

        return true;
    }

    private function mergePdfFilesWithFpdiWithoutResize(array $inputPaths, string $outputPath): bool
    {
        try {
            $pdf = new Fpdi('P', 'mm');
            foreach ($inputPaths as $inputPath) {
                $pageCount = $pdf->setSourceFile($inputPath);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $width = (float) $size['width'];
                    $height = (float) $size['height'];
                    $orientation = $width > $height ? 'L' : 'P';

                    $pdf->AddPage($orientation, [$width, $height]);
                    $pdf->useTemplate($template, 0, 0, $width, $height, false);
                }
            }

            $pdf->Output('F', $outputPath);

            return is_file($outputPath) && (int) filesize($outputPath) > 0;
        } catch (Throwable $e) {
            Log::error('FPDI direct PDF merge gagal.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function markCrashed(BulkShippingLabelBatch $batch): void
    {
        $batch->items()
            ->whereIn('status', BulkShippingLabelItem::TRANSIENT_STATUSES)
            ->update([
                'status' => BulkShippingLabelItem::STATUS_FAILED,
                'reason' => BulkShippingLabelItem::REASON_BATCH_CRASHED,
            ]);
        $batch->update([
            'status' => BulkShippingLabelBatch::STATUS_FAILED,
            'finished_at' => now(),
        ]);
        $batch->recomputeCounts();
    }
}
