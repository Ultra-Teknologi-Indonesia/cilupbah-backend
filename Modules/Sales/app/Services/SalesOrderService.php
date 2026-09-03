<?php

namespace Modules\Sales\Services;

use App\Exceptions\UserFacingException;
use App\Models\User;
use App\Support\ChannelWarehousePolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Channel\Exceptions\ChannelLabelUnsupportedException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\LazadaOrderService;
use Modules\Channel\Services\MarketplaceCancelReasonService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Services\TikTokClient;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Inventory\Jobs\AutoDetectStockReplenishmentJob;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Outbound\Models\Packlist;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Outbound\Services\FulfillmentCleanupService;
use Modules\Outbound\Services\OutboundFulfillmentService;
use Modules\Sales\Enums\BuyerCancellationSyncStatus;
use Modules\Sales\Enums\OrderActivityAction;
use Modules\Sales\Enums\OrderActivityEntity;
use Modules\Sales\Enums\SalesOrderStatus;
use Modules\Sales\Exceptions\CannotDeleteActiveOrderException;
use Modules\Sales\Exceptions\DuplicateOrderException;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Exceptions\LocationNotConfiguredException;
use Modules\Sales\Exceptions\ProductNotMappableException;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Jobs\AutoAcceptCancelRequestJob;
use Modules\Sales\Jobs\CancelChannelOrderJob;
use Modules\Sales\Jobs\PrepareLazadaShippingLabelJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\RespondBuyerCancellationJob;
use Modules\Sales\Jobs\SyncOrderFinanceJob;
use Modules\Sales\Jobs\SyncStockJob;
use Modules\Sales\Models\OrderBinAllocation;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Sales\Support\OrderTotals;
use Modules\Sales\Support\SalesOrderDataNormalizer;
use Modules\Sales\Support\ShadowOrderGuard;
use Modules\Warehouse\Models\Location;

class SalesOrderService
{
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['reserved', 'cancelled'],
        'reserved' => ['picked', 'cancelled'],
        'picked' => ['packed', 'cancelled'],
        'packed' => ['shipped', 'cancelled'],
        'shipped' => [],
        'cancelled' => [],
    ];

    private const CHANNEL_CANCELLABLE_STATUSES = [
        'shopee' => ['UNPAID', 'READY_TO_SHIP', 'PROCESSED'],
        'tiktok' => ['UNPAID', 'READY_TO_SHIP'],
        'lazada' => ['UNPAID', 'PROCESSED'],
    ];

    private const STATUS_HISTORY_ACTIONS = [
        'reserved' => 'PROCESS',
        'picked' => 'FINISH_PICK',
        'packed' => 'FINISH_PACK',
        'shipped' => 'SHIPPED',
        'cancelled' => 'CANCELLED',
    ];

    private const AUDITED_CHANNEL_FIELDS = [
        'channel_status',
        'tracking_number',
        'courier',
        'shipping_provider',
        'shipping_address',
        'shipping_full_name',
        'shipping_subdistrict',
        'customer_name',
        'shipping_phone',
        'payment_method',
        'mp_completed_date',
        'is_escrow_updated',
        'zone_name',
        'district_cd',
        'due_date',
    ];

    private const AUDIT_IGNORED = [
        'last_modified',
        'updated_at',
        'mp_timestamp',
        'synced_at',
    ];

    private const IDEMPOTENCY_TTL = 172800;

    private const CHANNEL_PREFIX = [
        'tiktok' => 'TT',
        'shopee' => 'SP',
        'lazada' => 'LZ',
        'tokopedia' => 'TP',
        'blibli' => 'BL',
    ];

    public const NOTIF_ORDER_PERMISSION = 'view-pesanan';

    public function __construct(
        protected SalesOrderRepository $orderRepository,
        protected StockService $stockService,
        protected NotificationDispatcher $notifications,
        protected ChannelWarehousePolicy $channelWarehousePolicy,
    ) {}

    private function orderLink(string $id): string
    {
        return "/dashboard/pesanan/{$id}";
    }

    public function getPaginatedOrders()
    {
        return $this->orderRepository->getPaginatedOrders();
    }

    public function getShippingProviders(array $params = []): array
    {
        return $this->orderRepository->getShippingProviders($params);
    }

    public function getTabCounts(): array
    {

        $cacheKey = 'sales:tab-counts:u:'.(auth()->id() ?? 'guest');

        return Cache::remember($cacheKey, now()->addSeconds(30), fn () => $this->orderRepository->getTabCounts());
    }

    public function readyToProcessQuery(?string $locationId = null): Builder
    {
        return $this->orderRepository->readyToProcessQuery($locationId);
    }

    public function getOrderById($id)
    {
        return $this->orderRepository->getOrderById($id);
    }

    public function getCancelledOrders(int $limit = 10)
    {
        return $this->orderRepository->getCancelledOrders($limit);
    }

    public function getCompletedOrders(int $limit = 10)
    {
        return $this->orderRepository->getCompletedOrders($limit);
    }

    public function getFailedOrders(int $limit = 10)
    {
        return $this->orderRepository->getFailedOrders($limit);
    }

    public function getReturnedOrders(int $limit = 10)
    {
        return $this->orderRepository->getReturnedOrders($limit);
    }

    public function getUnfulfilledOrders(int $limit = 10)
    {
        return $this->orderRepository->getUnfulfilledOrders($limit);
    }

    public function bulkDeleteCancelled(array $ids): int
    {
        return $this->orderRepository->bulkDeleteCancelled($ids);
    }

    public function moveToReadyToProcess(array $orderIds, $actor = null): array
    {

        $pendingCancelIds = SalesOrder::whereIn('id', $orderIds)
            ->where('channel_cancel_status', 'pending')
            ->pluck('salesorder_no', 'id')
            ->all();

        $stockShortIds = SalesOrder::whereIn('id', $orderIds)
            ->whereNotIn('id', array_keys($pendingCancelIds))
            ->where('status', 'reserved')
            ->hasStockShortfall()
            ->pluck('salesorder_no', 'id')
            ->all();

        $skipped = [];
        foreach ($pendingCancelIds as $id => $orderNo) {
            $skipped[] = ['id' => (string) $id, 'salesorder_no' => $orderNo, 'reason' => 'cancel_pending'];
        }
        foreach ($stockShortIds as $id => $orderNo) {
            $skipped[] = ['id' => (string) $id, 'salesorder_no' => $orderNo, 'reason' => 'empty_stock'];
        }

        if (! empty($skipped)) {
            $actorId = null;
            if ($actor instanceof User) {
                $actorId = $actor->id;
            } elseif (is_array($actor)) {
                $actorId = $actor['id'] ?? null;
            }

            foreach ($skipped as $s) {
                $isCancel = $s['reason'] === 'cancel_pending';
                $this->notifications->toPermission(self::NOTIF_ORDER_PERMISSION, [
                    'type' => $isCancel ? 'order_cancel_pending' : 'order_empty_stock',
                    'title' => $isCancel
                        ? 'Pesanan ditahan (pembatalan diproses)'
                        : 'Pesanan tidak bisa diproses (stok kosong)',
                    'message' => $isCancel
                        ? "Pesanan {$s['salesorder_no']} di-skip: menunggu konfirmasi pembatalan marketplace."
                        : "Pesanan {$s['salesorder_no']} di-skip karena ada SKU stok kosong.",
                    'data' => [
                        'sales_order_id' => $s['id'],
                        'salesorder_no' => $s['salesorder_no'],
                        'link' => $this->orderLink($s['id']),
                    ],
                ], excludeUserIds: array_filter([$actorId]));
            }
        }

        $skippedDbIds = array_merge(array_keys($pendingCancelIds), array_keys($stockShortIds));
        $eligibleIds = array_values(array_diff($orderIds, $skippedDbIds));

        if (empty($eligibleIds)) {
            return [
                'moved' => 0,
                'skipped' => $skipped,
                'message' => $this->buildMoveToReadyMessage(0, $skipped),
            ];
        }

        $count = DB::transaction(function () use ($eligibleIds, $actor) {
            $orders = SalesOrder::whereIn('id', $eligibleIds)
                ->where('status', 'reserved')
                ->get();

            $count = 0;

            foreach ($orders as $order) {
                PicklistItem::where('order_id', $order->id)
                    ->whereHas('picklist', fn ($q) => $q->whereIn('status', [
                        Picklist::STATUS_DRAFT,
                        Picklist::STATUS_FAILED,
                    ]))
                    ->delete();

                $order->update([
                    'handed_to_warehouse_at' => now(),
                    'pick_failed_at' => null,
                    'pick_failed_by' => null,
                    'pick_fail_reason' => null,
                ]);

                if (! $order->statusHistory()->where('action', 'PROCESS')->exists()) {
                    $this->logStatusHistory($order, 'PROCESS', [
                        'from' => 'reserved',
                        'to' => 'reserved',
                    ], $actor);
                }

                $count++;
            }

            return $count;
        });

        return [
            'moved' => $count,
            'skipped' => $skipped,
            'message' => $this->buildMoveToReadyMessage($count, $skipped),
        ];
    }

    private function buildMoveToReadyMessage(int $moved, array $skipped): string
    {
        $message = "{$moved} order berhasil dipindahkan ke siap proses";

        if (! empty($skipped)) {
            $skippedCount = count($skipped);
            $message .= " · {$skippedCount} order dilewati karena stok kosong (cek tab Stok Kosong)";
        }

        return $message;
    }

    public function acceptCancelRequest(string $orderId, bool $auto = false, ?string $reason = null): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);

        if (! $order->cancel_requested_at) {
            throw new \InvalidArgumentException('Pesanan ini tidak memiliki permintaan pembatalan.');
        }

        if ($order->status === 'cancelled') {
            throw new InvalidStatusTransitionException($order->status, 'cancelled');
        }

        $actorId = $auto ? null : (Auth::id() ?: null);
        $channel = $auto ? 'auto' : 'manual';
        $finalReason = $reason ?? $order->cancel_request_reason ?? 'seller_cancel_reason_other';

        if ($order->status === 'shipped') {
            $result = DB::transaction(function () use ($order, $actorId, $channel, $finalReason) {
                app(SalesReturnService::class)->createFromCancelledShipped(
                    $order,
                    $finalReason,
                    $actorId ?? 'system:auto-accept-cancel',
                );

                $order->update([
                    'cancel_requested_at' => null,
                    'cancel_request_reason' => null,
                    'cancel_requested_by' => null,
                    'cancel_accepted_at' => now(),
                    'cancel_accepted_by' => $actorId,
                    'cancel_channel' => $channel,
                    'cancel_reason' => $finalReason,
                ]);

                Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));

                return $order->fresh();
            });

            if (in_array(strtolower((string) $result->source), ['shopee', 'tiktok'], true)) {
                $this->respondToBuyerCancellationSynchronously($result, RespondBuyerCancellationJob::ACCEPT);
            } else {
                CancelChannelOrderJob::dispatch($result->id, $finalReason)
                    ->onQueue(config('queue.names.channel_cancellation'));
            }

            $this->notifyOrderCancelled($result, $finalReason, $channel, $actorId);

            return $result;
        }

        $result = DB::transaction(function () use ($order, $actorId, $channel, $finalReason) {
            $this->applyStockTransition($order, 'cancelled');

            $order->update([
                'status' => 'cancelled',
                'is_canceled' => true,
                'cancel_requested_at' => null,
                'cancel_request_reason' => null,
                'cancel_requested_by' => null,
                'cancel_reason' => $finalReason,
                'cancel_accepted_at' => now(),
                'cancel_accepted_by' => $actorId,
                'cancel_channel' => $channel,
            ]);

            app(FulfillmentCleanupService::class)
                ->detachCancelledOrder($order->id, $actorId ?: 'system:cancel-accept');

            Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));

            SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));

            return $order->fresh();
        });

        if ($result->source) {
            if (in_array(strtolower((string) $result->source), ['shopee', 'tiktok'], true)) {
                $this->respondToBuyerCancellationSynchronously($result, RespondBuyerCancellationJob::ACCEPT);
            } else {
                CancelChannelOrderJob::dispatch($result->id, $finalReason)
                    ->onQueue(config('queue.names.channel_cancellation'));
            }
        }

        $this->notifyOrderCancelled($result, $finalReason, $channel, $actorId);

        return $result;
    }

    private function notifyOrderCancelled(SalesOrder $order, ?string $reason, string $channel, ?string $actorId): void
    {
        $sourceLabel = $order->source ? strtolower($order->source) : 'manual';
        $reasonSuffix = $reason ? " Alasan: {$reason}" : '';
        $this->notifications->toPermission(self::NOTIF_ORDER_PERMISSION, [
            'type' => 'order_cancelled',
            'title' => 'Pesanan dibatalkan',
            'message' => "Pesanan {$order->salesorder_no} ({$sourceLabel}) dibatalkan via {$channel}.{$reasonSuffix}",
            'data' => [
                'sales_order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source' => $order->source,
                'channel' => $channel,
                'reason' => $reason,
                'link' => $this->orderLink($order->id),
            ],
        ], excludeUserIds: array_filter([$actorId]));
    }

    public function rejectCancelRequest(string $orderId, ?string $reason = null, bool $auto = false): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);

        if (! $order->cancel_requested_at) {
            throw new \InvalidArgumentException('Pesanan ini tidak memiliki permintaan pembatalan.');
        }

        $actorId = $auto ? null : (Auth::id() ?: null);

        $order->update([
            'cancel_requested_at' => null,
            'cancel_request_reason' => null,
            'cancel_requested_by' => null,
            'cancel_rejected_at' => now(),
            'cancel_rejected_by' => $actorId,
            'cancel_reject_reason' => $reason,
        ]);

        $result = $order->fresh();

        $this->respondToBuyerCancellationSynchronously($result, RespondBuyerCancellationJob::REJECT);

        return $result;
    }

    private function respondToBuyerCancellationSynchronously(SalesOrder $order, string $decision): void
    {
        if (! in_array(strtolower((string) $order->source), ['shopee', 'tiktok', 'lazada'], true)) {
            return;
        }

        RespondBuyerCancellationJob::dispatchSync($order->id, $decision);
    }

    public function retryBuyerCancellationSync(string $orderId): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);
        $decision = (string) $order->buyer_cancel_sync_decision;

        if (! in_array($decision, [RespondBuyerCancellationJob::ACCEPT, RespondBuyerCancellationJob::REJECT], true)) {
            throw new \InvalidArgumentException('Belum ada keputusan pembatalan buyer yang bisa dikirim ulang.');
        }

        RespondBuyerCancellationJob::dispatchSync($order->id, $decision);

        return $order->fresh();
    }

    public function markBuyerCancellationRequestedFromChannel(
        string $source,
        string $shopId,
        string $channelOrderNo,
        ?string $reason = null,
        ?string $channelReference = null,
    ): ?SalesOrder {
        $order = SalesOrder::query()
            ->where('source', strtolower($source))
            ->where('channel_shop_id', $shopId)
            ->where('channel_order_no', $channelOrderNo)
            ->first();

        if (! $order) {
            return null;
        }

        if ($order->cancel_accepted_at || $order->cancel_rejected_at) {
            return $order;
        }

        $order->forceFill([
            'cancel_requested_at' => $order->cancel_requested_at ?: now(),
            'cancel_request_reason' => $reason ?: $order->cancel_request_reason,
            'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::PENDING->value,
            'buyer_cancel_sync_decision' => null,
            'buyer_cancel_sync_error' => null,
            'buyer_cancel_channel_reference' => $channelReference ?: $order->buyer_cancel_channel_reference,
        ])->saveQuietly();

        AutoAcceptCancelRequestJob::dispatch($order->id);

        return $order->fresh();
    }

    public function markBuyerCancellationFinishedFromChannel(
        string $source,
        string $shopId,
        string $channelOrderNo,
        string $channelStatus,
        ?string $channelReference = null,
    ): ?SalesOrder {
        $order = SalesOrder::query()
            ->where('source', strtolower($source))
            ->where('channel_shop_id', $shopId)
            ->where('channel_order_no', $channelOrderNo)
            ->first();

        if (! $order) {
            return null;
        }

        $status = strtoupper($channelStatus);
        $updates = [
            'buyer_cancel_channel_reference' => $channelReference ?: $order->buyer_cancel_channel_reference,
            'buyer_cancel_sync_status' => in_array($status, ['CANCEL_SUCCESS', 'CANCEL_REFUND_ISSUED'], true)
                ? BuyerCancellationSyncStatus::SUCCEEDED->value
                : BuyerCancellationSyncStatus::STALE->value,
            'buyer_cancel_sync_error' => null,
            'buyer_cancel_synced_at' => now(),
        ];

        $order->forceFill($updates)->saveQuietly();

        return $order->fresh();
    }

    public function autoResolveCancelRequest(string $orderId): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);

        if (! $order->cancel_requested_at || $order->cancel_accepted_at || $order->status === 'cancelled') {
            return $order;
        }

        if ($order->status === 'packed' && $this->hasScheduledShipmentAssignment($order)) {
            return $this->rejectCancelRequest(
                $order->id,
                'Pesanan sudah dijadwalkan pengiriman (siap kirim). Pembatalan otomatis ditolak, proses dilanjutkan.',
                auto: true,
            );
        }

        return $this->acceptCancelRequest($order->id, auto: true);
    }

    private function hasScheduledShipmentAssignment(SalesOrder $order): bool
    {
        return ShipmentOrder::where('order_id', $order->id)
            ->whereHas('shipment', fn ($q) => $q->where('status', Shipment::STATUS_SCHEDULED))
            ->exists();
    }

    public function requestChannelCancel(string $orderId, string $reason): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);
        $source = strtolower((string) $order->source);

        if (! in_array($source, ['tiktok', 'shopee', 'lazada'], true)) {
            throw new UserFacingException('Tidak dapat membatalkan', 'Request cancel ke marketplace hanya untuk pesanan TikTok, Shopee, atau Lazada.');
        }

        if ($order->status === 'cancelled' || $order->is_canceled) {
            throw new InvalidStatusTransitionException((string) $order->status, 'cancelled');
        }

        $fromStatus = SalesOrderStatus::tryFrom((string) $order->status);
        if ($fromStatus === null || ! $fromStatus->canTransitionTo(SalesOrderStatus::CANCELLED)) {
            throw new UserFacingException('Tidak dapat membatalkan', "Pesanan berstatus {$order->status} tidak dapat dibatalkan.");
        }

        if ($order->channel_cancel_status === 'pending') {
            throw new UserFacingException('Sedang diproses', 'Permintaan pembatalan untuk pesanan ini sedang diproses.');
        }

        if ($source === 'tiktok' && empty($order->channel_status_raw)) {
            $this->refreshChannelStatusRaw($order);
            $order->refresh();
        }

        $this->assertChannelCancellable($order, $source);
        $this->assertValidCancelReason($order, $source, $reason);

        $order->forceFill([
            'channel_cancel_requested_at' => now(),
            'channel_cancel_requested_by' => Auth::id() ?: null,
            'channel_cancel_status' => 'pending',
            'channel_cancel_error' => null,
            'cancel_reason' => $reason,
        ])->save();

        CancelChannelOrderJob::dispatch($order->id, $reason)
            ->onQueue(config('queue.names.channel_cancellation'));

        return $order->fresh();
    }

    public function releaseChannelCancel(string $orderId): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);

        if ($order->status === 'cancelled' || $order->is_canceled) {
            throw new UserFacingException('Sudah dibatalkan', 'Pesanan sudah dibatalkan, tidak bisa dilanjutkan.');
        }

        if ($order->channel_cancel_status !== null) {
            $order->forceFill([
                'channel_cancel_status' => null,
                'channel_cancel_error' => null,
                'channel_cancel_requested_at' => null,
            ])->save();
        }

        return $order->fresh();
    }

    public function markChannelCancelRejected(string $orderRef, ?string $reason = null): void
    {
        $order = SalesOrder::where('channel_order_no', $orderRef)
            ->orWhere('salesorder_no', $orderRef)
            ->first();

        if (! $order || $order->status === 'cancelled' || $order->is_canceled) {
            return;
        }

        if ($order->channel_cancel_status === 'pending') {
            $order->forceFill([
                'channel_cancel_status' => 'failed',
                'channel_cancel_error' => Str::limit($reason ?: 'Ditolak marketplace', 240),
            ])->saveQuietly();
        }
    }

    private function assertChannelCancellable(SalesOrder $order, string $source): void
    {
        $eligible = self::CHANNEL_CANCELLABLE_STATUSES[$source] ?? [];
        $channelStatus = (string) $order->channel_status;

        if (in_array($channelStatus, $eligible, true)) {
            return;
        }

        if ($channelStatus === '' || $channelStatus === 'UNKNOWN') {
            return;
        }

        throw new UserFacingException(
            'Status tidak dapat dibatalkan',
            "Pesanan {$source} berstatus {$channelStatus} tidak dapat dibatalkan seller. "
            .'Diizinkan: '.implode(', ', $eligible).'.'
        );
    }

    private function assertValidCancelReason(SalesOrder $order, string $source, string $reason): void
    {
        try {
            $validKeys = array_column($this->cancelReasonsForOrder($order), 'key');
        } catch (\Throwable $e) {
            throw new UserFacingException('Gagal memuat alasan', 'Gagal memuat daftar alasan pembatalan. Coba lagi.', 502);
        }

        if (! in_array($reason, $validKeys, true)) {
            throw new UserFacingException('Alasan tidak valid', "Alasan pembatalan tidak valid untuk {$source}.");
        }
    }

    public function cancelReasonsForOrder(SalesOrder $order): array
    {
        $source = strtolower((string) $order->source);
        $reasonService = app(MarketplaceCancelReasonService::class);

        if (! in_array($source, ['tiktok', 'shopee', 'lazada'], true)) {
            return [];
        }

        if ($source === 'lazada') {
            return $reasonService->normalize(
                app(LazadaOrderService::class)->getCancelReasons($order->channel_shop_id)
            );
        }

        $context = $source === 'tiktok'
            ? ($order->channel_status_raw ?: ($order->is_paid ? 'ON_HOLD' : 'UNPAID'))
            : null;

        return $reasonService->for($source, $context);
    }

    public function cancelReasonsForOrderId(string $orderId): array
    {
        return $this->cancelReasonsForOrder(SalesOrder::findOrFail($orderId));
    }

    private function refreshChannelStatusRaw(SalesOrder $order): void
    {
        try {
            if (strtolower((string) $order->source) === 'tiktok') {
                app(TikTokOrderService::class)
                    ->pullOrderById($order->channel_shop_id, $order->salesorder_no);
            }
        } catch (\Throwable $e) {
            Log::warning('requestChannelCancel: gagal refresh status mentah TikTok: '.$e->getMessage());
        }
    }

    public function markAsComplete(array $orderIds): int
    {
        return DB::transaction(function () use ($orderIds) {
            $count = 0;
            $orders = SalesOrder::with('items')
                ->whereIn('id', $orderIds)
                ->whereIn('status', ['packed', 'reserved'])

                ->where(fn ($q) => $q->whereNull('channel_cancel_status')
                    ->orWhere('channel_cancel_status', '!=', 'pending'))
                ->get();

            foreach ($orders as $order) {
                if ($order->status === 'reserved'
                    && ! OrderBinAllocation::query()->where('order_id', $order->id)->exists()
                ) {
                    throw new UserFacingException(
                        'Pesanan Belum Dipicking',
                        "Pesanan {$order->salesorder_no} masih reserved dan belum memiliki alokasi pemotongan stok fisik.",
                        422,
                    );
                }

                $this->reconcileStockTransition($order, $order->status, 'shipped');
                $order->update(['status' => 'shipped']);
                $this->logStatusHistory($order, 'COMPLETED', ['to' => 'shipped']);
                SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
                $count++;
            }

            return $count;
        });
    }

    public function cancelLocally(string $orderId, ?string $reason, ?string $actorId = null): SalesOrder
    {
        return DB::transaction(function () use ($orderId, $reason, $actorId) {
            $order = SalesOrder::with('items')->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->is_canceled || $order->status === 'cancelled') {
                return $order->fresh(['items']);
            }

            $this->applyStockTransition($order, 'cancelled');

            $order->update([
                'status' => 'cancelled',
                'is_canceled' => true,
                'cancel_reason' => $reason,
                'cancel_accepted_at' => now(),
                'cancel_accepted_by' => $actorId,
            ]);

            $this->logStatusHistory($order, 'CANCELLED', [
                'from' => 'reserved',
                'to' => 'cancelled',
                'reason' => $reason,
            ]);

            app(FulfillmentCleanupService::class)
                ->detachCancelledOrder($order->id, $actorId ?: 'system:buyer-confirmation');

            return $order->fresh(['items']);
        });
    }

    public function saveAirwaybill(array $data): SalesOrder
    {
        $order = SalesOrder::findOrFail($data['order_id']);
        $order->update([
            'tracking_number' => $data['tracking_number'],
            'shipping_provider' => $data['shipping_provider'] ?? $order->shipping_provider,
        ]);

        return $order->fresh();
    }

    public function saveReceivedDate(array $data): SalesOrder
    {
        $order = SalesOrder::findOrFail($data['order_id']);
        $order->update(['received_date' => $data['received_date'] ?? now()]);

        return $order->fresh();
    }

    public function saveCourierPickup(string $id, array $data): SalesOrder
    {
        $order = SalesOrder::findOrFail($id);
        $order->update([
            'courier_name' => $data['courier_name'] ?? null,
            'courier_phone' => $data['courier_phone'] ?? null,
            'pickup_code' => $data['pickup_code'] ?? null,
            'courier_pickup_recorded_at' => now(),
            'courier_pickup_recorded_by' => Auth::id() ?: null,
        ]);

        return $order->fresh();
    }

    public function replaceCourierIdPhoto(string $id, UploadedFile $photo): SalesOrder
    {
        $order = SalesOrder::findOrFail($id);
        $order->clearMediaCollection('courier_id');
        $order->addMedia($photo)->toMediaCollection('courier_id');
        $order->update([
            'courier_pickup_recorded_at' => now(),
            'courier_pickup_recorded_by' => Auth::id() ?: null,
        ]);

        return $order->fresh();
    }

    public function deleteCourierIdPhoto(string $id): SalesOrder
    {
        $order = SalesOrder::findOrFail($id);
        $order->clearMediaCollection('courier_id');

        return $order->fresh();
    }

    public function setAsPaid(array $data): SalesOrder
    {
        $order = SalesOrder::findOrFail($data['order_id']);
        $order->update([
            'is_paid' => true,
            'paid_time' => $data['paid_time'] ?? now(),
            'payment_method' => $data['payment_method'] ?? $order->payment_method,
        ]);
        $this->logStatusHistory($order, 'PAID');

        return $order->fresh();
    }

    public function requestAwb(array $data): array
    {
        $fulfillmentService = app(OutboundFulfillmentService::class);
        $results = $fulfillmentService->readyToShip([$data['order_id']]);

        if (empty($results)) {
            return ['order_id' => $data['order_id'], 'status' => 'failed', 'message' => 'Tidak ada hasil dari proses pengiriman.'];
        }

        $result = $results[0];

        if (($result['status'] ?? '') === 'failed') {
            throw new \Exception($result['message'] ?? 'Gagal memproses pengiriman.');
        }

        return $result;
    }

    public function getShippingLabel(SalesOrder $order, array $options = []): array
    {
        $source = $order->source;
        $shopId = $order->channel_shop_id;
        $channelOrderNo = $order->channel_order_no;

        if (! $source || ! $shopId || ! $channelOrderNo) {
            throw new \InvalidArgumentException('Pesanan ini bukan dari marketplace atau data channel tidak lengkap.');
        }

        $cachedLabel = $this->readCachedShippingLabel($order);
        if ($cachedLabel !== null) {
            return $cachedLabel;
        }

        if ($source === 'tiktok') {
            $tikTokService = app(TikTokOrderService::class);
            $shopRepo = app(ChannelShopRepository::class);
            $shop = $shopRepo->findByShopId($shopId);

            if (! $shop || ! $shop->access_token) {
                throw new \RuntimeException('Token akses TikTok Shop tidak ditemukan.');
            }

            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $detailQueries = array_merge($queries, ['ids' => $channelOrderNo]);
            $tikTokClient = app(TikTokClient::class);
            $packageIds = $this->channelPackageIds($order);
            if ($packageIds === []) {
                $res = $tikTokClient->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);
                foreach (($res['data']['orders'] ?? []) as $o) {
                    foreach ($o['packages'] ?? [] as $pkg) {
                        if (! empty($pkg['id'])) {
                            $packageIds[] = (string) $pkg['id'];
                        }
                    }
                }
                $packageIds = array_values(array_unique($packageIds));
                $this->persistChannelPackageIds($order, $packageIds);
            }

            $packageId = $packageIds[0] ?? null;

            if (! $packageId) {
                throw new \RuntimeException('Package ID tidak ditemukan untuk pesanan TikTok ini.');
            }

            $documentType = $options['document_type'] ?? 'SHIPPING_LABEL';
            $documentSize = $options['document_size'] ?? 'A6';
            $result = $tikTokService->getShippingLabel($shopId, $packageId, $documentType, $documentSize);
            $docUrl = $result['data']['doc_url'] ?? ($result['data']['document_url'] ?? null);

            if ($docUrl) {
                return [
                    'type' => 'url',
                    'url' => $docUrl,
                    'source' => 'tiktok',
                ];
            }

            return [
                'type' => 'raw',
                'data' => $result,
                'source' => 'tiktok',
            ];
        }

        if ($source === 'shopee') {
            $shopeeService = app(ShopeeOrderService::class);

            $requestedDocType = $options['document_type'] ?? null;

            $cacheUsable = $order->shipping_label_status === 'ready'
                && $order->shipping_label_doc_type;

            if ($cacheUsable) {
                $download = $shopeeService->downloadShippingDocument(
                    $shopId,
                    $channelOrderNo,
                    $order->shipping_label_doc_type
                );

                if (! empty($download['binary']) || ! empty($download['content'])) {
                    $content = (string) ($download['content'] ?? '');
                    $this->cacheShippingLabelBytes($order, $content, $order->shipping_label_doc_type);

                    return [
                        'type' => 'base64',
                        'content_type' => $download['content_type'] ?? 'application/pdf',
                        'document_base64' => base64_encode($content),
                        'source' => 'shopee',
                    ];
                }

            }

            if ($order->shipping_label_status === 'preparing') {
                $liveStatus = null;
                $liveDocType = $order->shipping_label_doc_type ?: 'THERMAL_AIR_WAYBILL';
                try {
                    $liveResult = $shopeeService->getShippingDocumentResult($shopId, $channelOrderNo, $liveDocType);
                    $liveRow = $liveResult['response']['result_list'][0] ?? [];
                    $liveStatus = strtoupper((string) ($liveRow['status'] ?? ''));
                } catch (\Throwable $e) {
                    $reason = $shopeeService->classifyShippingLabelFailure($e);
                    if ($reason !== null) {
                        $this->markShopeeShippingLabelFailure($order, $reason, $e->getMessage());
                        throw $e;
                    }

                    Log::warning('getShippingLabel: live get_shipping_document_result gagal, pakai cache', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($liveStatus === 'READY') {
                    $download = $shopeeService->downloadShippingDocument($shopId, $channelOrderNo, $liveDocType);
                    if (! empty($download['binary']) || ! empty($download['content'])) {
                        $content = (string) ($download['content'] ?? '');
                        $this->cacheShippingLabelBytes($order, $content, $liveDocType);
                        $order->update([
                            'shipping_label_status' => 'ready',
                            'shipping_label_doc_type' => $liveDocType,
                            'shipping_label_prepared_at' => now(),
                        ]);

                        return [
                            'type' => 'base64',
                            'content_type' => $download['content_type'] ?? 'application/pdf',
                            'document_base64' => base64_encode($content),
                            'source' => 'shopee',
                        ];
                    }
                }

                if ($liveStatus === 'FAILED') {
                    $failMsg = $liveRow['fail_message'] ?? $liveRow['fail_error'] ?? 'Shopee menolak pembuatan label.';
                    $reason = $shopeeService->classifyShippingLabelFailure($liveRow);
                    if ($reason !== null) {
                        $this->markShopeeShippingLabelFailure($order, $reason, $failMsg);
                    } else {
                        $order->update(['shipping_label_status' => 'failed']);
                    }
                    throw new \RuntimeException("Shopee gagal membuat label: {$failMsg}");
                }

                $isStale = $order->shipping_label_prepared_at
                    ? $order->shipping_label_prepared_at->lt(now()->subMinutes(5))
                    : true;

                if ($isStale) {
                    PrepareShopeeShippingLabelJob::dispatch($order->id)
                        ->onConnection(config('queue.routing.labels.connection', 'redis-long'))
                        ->onQueue(config('queue.routing.labels.queue', 'labels'));
                    throw new ShippingLabelPreparingException(
                        'Label belum siap. Kemungkinan status pesanan di Shopee belum siap dikirim (RETRY_SHIP) — cek Seller Center. Sistem mencoba ulang, tunggu 1-2 menit.'
                    );
                }

                throw new ShippingLabelPreparingException(
                    'Label sedang disiapkan oleh Shopee. Coba lagi dalam 1-2 menit.'
                );
            }

            if ($order->shipping_label_status === 'failed') {
                PrepareShopeeShippingLabelJob::dispatch($order->id)
                    ->onConnection(config('queue.routing.labels.connection', 'redis-long'))
                    ->onQueue(config('queue.routing.labels.queue', 'labels'));

                throw new ShippingLabelPreparingException(
                    'Label sebelumnya gagal. Sedang dicoba ulang, tunggu 1-2 menit.'
                );
            }

            $shopeeDocType = $order->shipping_label_doc_type ?? $requestedDocType ?? 'NORMAL_AIR_WAYBILL';
            try {
                $result = $shopeeService->getAirwayBill(
                    $shopId,
                    $channelOrderNo,
                    $shopeeDocType,
                    $order->tracking_number,
                    $order->package_number ?? null
                );
            } catch (\Throwable $e) {
                $reason = $shopeeService->classifyShippingLabelFailure($e);
                if ($reason !== null) {
                    $this->markShopeeShippingLabelFailure($order, $reason, $e->getMessage());
                }

                throw $e;
            }

            if (! empty($result['ready']) && ! empty($result['document_base64'])) {
                $labelBytes = base64_decode((string) $result['document_base64'], true);
                if ($labelBytes !== false && $labelBytes !== '') {
                    $this->cacheShippingLabelBytes($order, $labelBytes, $result['doc_type'] ?? $shopeeDocType);
                }

                $order->update([
                    'shipping_label_status' => 'ready',
                    'shipping_label_doc_type' => $result['doc_type'] ?? $shopeeDocType,
                    'shipping_label_prepared_at' => now(),
                ]);

                return [
                    'type' => 'base64',
                    'content_type' => $result['content_type'] ?? 'application/pdf',
                    'document_base64' => $result['document_base64'],
                    'source' => 'shopee',
                ];
            }

            if (! empty($result['error'])) {
                $reason = $shopeeService->classifyShippingLabelFailure($result);
                if ($reason !== null) {
                    $this->markShopeeShippingLabelFailure(
                        $order,
                        $reason,
                        (string) ($result['message'] ?? $result['error']),
                    );
                }

                throw new \RuntimeException($result['message'] ?? $result['error']);
            }

            return [
                'type' => 'raw',
                'data' => $result,
                'source' => 'shopee',
            ];
        }

        if ($source === 'lazada') {
            $lazadaService = app(LazadaOrderService::class);

            if ($order->shipping_label_status === 'ready' && is_array($order->shipping_label_raw_data)) {
                $cached = $order->shipping_label_raw_data['document'] ?? [];
                if (! empty($cached['pdf_url'])) {
                    return ['type' => 'url', 'url' => $cached['pdf_url'], 'source' => 'lazada'];
                }
                if (! empty($cached['file'])) {
                    $bytes = base64_decode((string) $cached['file'], true);
                    if ($bytes !== false && $bytes !== '') {
                        $this->cacheShippingLabelBytes($order, $bytes, $cached['doc_type'] ?? 'PDF');
                    }

                    return [
                        'type' => 'base64',
                        'content_type' => ($cached['doc_type'] ?? 'PDF') === 'HTML' ? 'text/html' : 'application/pdf',
                        'document_base64' => $cached['file'],
                        'source' => 'lazada',
                    ];
                }
            }

            if ($order->shipping_label_status === 'self_design_required') {
                throw new \RuntimeException(
                    'Order Lazada ini bertipe SOF/DBS — label tidak tersedia via API. '
                    .'Ambil resi langsung dari Lazada Seller Center.'
                );
            }

            if ($order->shipping_label_status === 'preparing') {
                throw new ShippingLabelPreparingException(
                    'Label Lazada sedang disiapkan. Coba lagi dalam 1-2 menit.'
                );
            }

            try {
                $packageIds = $this->channelPackageIds($order);
                if ($packageIds === []) {
                    $packageIds = $lazadaService->resolvePackageIds($shopId, $channelOrderNo);
                    $this->persistChannelPackageIds($order, $packageIds);
                }
                $document = $lazadaService->getPackageDocument($shopId, $packageIds, 'PDF');
            } catch (ChannelLabelUnsupportedException $e) {
                $order->update(['shipping_label_status' => 'self_design_required']);
                throw new \RuntimeException($e->getMessage());
            }

            $isHtml = ($document['doc_type'] ?? 'PDF') === 'HTML';

            if (! empty($document['pdf_url']) || ! empty($document['file'])) {
                $rawData = [
                    'channel' => 'lazada',
                    'document' => $document,
                ];
                if (! empty($document['file'])) {
                    $bytes = base64_decode((string) $document['file'], true);
                    if ($bytes !== false && $bytes !== '') {
                        $this->cacheShippingLabelBytes($order, $bytes, $document['doc_type'] ?? 'PDF', $rawData);
                    }
                }
                $order->update([
                    'shipping_label_status' => 'ready',
                    'shipping_label_doc_type' => $document['doc_type'] ?? 'PDF',
                    'shipping_label_prepared_at' => now(),
                    'shipping_label_raw_data' => $order->fresh()?->shipping_label_raw_data ?: $rawData,
                ]);

                if (! empty($document['pdf_url'])) {
                    return ['type' => 'url', 'url' => $document['pdf_url'], 'source' => 'lazada'];
                }

                return [
                    'type' => 'base64',
                    'content_type' => $isHtml ? 'text/html' : 'application/pdf',
                    'document_base64' => $document['file'],
                    'source' => 'lazada',
                ];
            }

            PrepareLazadaShippingLabelJob::dispatch($order->id)
                ->onConnection(config('queue.routing.labels.connection', 'redis-long'))
                ->onQueue(config('queue.routing.labels.queue', 'labels'));

            throw new ShippingLabelPreparingException(
                'Label Lazada belum siap (pesanan mungkin belum di-RTS). Sistem menyiapkan, tunggu 1-2 menit.'
            );
        }

        throw new \InvalidArgumentException("Channel '{$source}' belum mendukung cetak resi otomatis.");
    }

    private function readCachedShippingLabel(SalesOrder $order): ?array
    {
        if ($order->shipping_label_status !== 'ready') {
            return null;
        }

        $path = data_get($order->shipping_label_raw_data, 'cache.path');
        $expectedHash = data_get($order->shipping_label_raw_data, 'cache.sha256');
        if (! is_string($path) || $path === '' || ! is_string($expectedHash) || $expectedHash === '') {
            return null;
        }

        try {
            $disk = Storage::disk('documents');
            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = $disk->get($path);
            if ($bytes === '' || ! hash_equals($expectedHash, hash('sha256', $bytes))) {
                Log::warning('Shipping label cache tidak valid, ambil ulang dari channel', [
                    'order_id' => $order->id,
                    'path' => $path,
                ]);

                return null;
            }

            return [
                'type' => 'base64',
                'content_type' => data_get($order->shipping_label_raw_data, 'cache.content_type', 'application/pdf'),
                'document_base64' => base64_encode($bytes),
                'source' => strtolower((string) $order->source),
                'cached' => true,
            ];
        } catch (\Throwable $e) {
            Log::warning('Shipping label cache gagal dibaca, ambil ulang dari channel', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function cacheShippingLabelBytes(
        SalesOrder $order,
        string $bytes,
        ?string $documentType = null,
        ?array $rawData = null,
    ): void {
        if ($bytes === '') {
            return;
        }

        $hash = hash('sha256', $bytes);
        $path = "shipping-label-cache/{$order->id}/{$hash}.pdf";
        $disk = Storage::disk('documents');
        if (! $disk->exists($path)) {
            $disk->put($path, $bytes);
        }

        $metadata = $rawData
            ?? (is_array($order->shipping_label_raw_data) ? $order->shipping_label_raw_data : []);
        $metadata['cache'] = [
            'path' => $path,
            'sha256' => $hash,
            'bytes' => strlen($bytes),
            'content_type' => strtoupper((string) $documentType) === 'HTML'
                ? 'text/html'
                : 'application/pdf',
            'document_type' => $documentType,
            'cached_at' => now()->toIso8601String(),
        ];

        $order->forceFill(['shipping_label_raw_data' => $metadata])->saveQuietly();
    }

    private function channelPackageIds(SalesOrder $order): array
    {
        $ids = is_array($order->channel_package_ids) ? $order->channel_package_ids : [];

        foreach ((array) data_get($order->shipping_label_raw_data, 'documents', []) as $document) {
            if (is_array($document) && ! empty($document['package_id'])) {
                $ids[] = (string) $document['package_id'];
            }
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $ids),
            static fn (string $id): bool => $id !== '',
        )));
    }

    private function persistChannelPackageIds(SalesOrder $order, array $packageIds): void
    {
        $packageIds = array_values(array_unique(array_filter(
            array_map('strval', $packageIds),
            static fn (string $id): bool => $id !== '',
        )));

        if ($packageIds === [] || $packageIds === $this->channelPackageIds($order)) {
            return;
        }

        $order->forceFill(['channel_package_ids' => $packageIds])->saveQuietly();
    }

    public function retryShippingLabel(SalesOrder $order): void
    {
        $source = strtolower((string) $order->source);

        if (! in_array($source, ['shopee', 'lazada'], true)) {
            throw new \InvalidArgumentException('Retry label hanya tersedia untuk pesanan Shopee dan Lazada.');
        }

        if (empty($order->tracking_number)) {
            throw new \InvalidArgumentException('Pesanan belum memiliki nomor resi. Minta resi terlebih dahulu.');
        }

        $order->update([
            'shipping_label_status' => null,
            'shipping_label_doc_type' => null,
            'shipping_label_prepared_at' => null,
            'shipping_label_raw_data' => null,
        ]);

        $job = $source === 'lazada'
            ? PrepareLazadaShippingLabelJob::dispatch($order->id)
            : PrepareShopeeShippingLabelJob::dispatch($order->id);

        $job->onConnection(config('queue.routing.labels.connection', 'redis-long'));
        $job->onQueue(config('queue.routing.labels.queue', 'labels'));
    }

    private function markShopeeShippingLabelFailure(
        SalesOrder $order,
        string $reason,
        ?string $message = null,
    ): void {
        $rawData = is_array($order->shipping_label_raw_data)
            ? $order->shipping_label_raw_data
            : [];
        $rawData['shipping_label_failure'] = array_filter([
            'reason' => $reason,
            'message' => $message,
            'at' => now()->toISOString(),
        ], static fn ($value) => $value !== null && $value !== '');

        $status = $reason === ShopeeOrderService::LABEL_FAILURE_SELF_DESIGN
            ? 'self_design_required'
            : 'failed';

        $order->update([
            'shipping_label_status' => $status,
            'shipping_label_doc_type' => null,
            'shipping_label_prepared_at' => now(),
            'shipping_label_raw_data' => $rawData,
        ]);
    }

    public function findOrderOrFail(string $id): SalesOrder
    {
        return $this->orderRepository->findOrFail($id);
    }

    public function getOrderForInvoice(string $id): ?SalesOrder
    {
        return $this->orderRepository->findForInvoice($id);
    }

    public function getOrderForBreakdown(string $id): ?SalesOrder
    {
        return $this->orderRepository->findForBreakdown($id);
    }

    public function prepareShippingLabelDocument(SalesOrder $order, ?string $requestedSize): array
    {
        if ($order->isManual()) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Pesanan manual (Toko Internal) tidak menggunakan label marketplace. Input resi secara manual.',
                422,
            );
        }

        $statusUpper = strtoupper((string) ($order->channel_status ?? $order->status));
        if ($order->is_canceled
            || in_array($order->status, ['shipped', 'completed', 'cancelled', 'returned'], true)
            || in_array($statusUpper, ['SHIPPED', 'COMPLETED', 'CANCELLED', 'DELIVERED', 'TO_CONFIRM_RECEIVE'], true)) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                "Label resi pengiriman tidak dapat dicetak untuk pesanan yang sudah diserahkan ke kurir, selesai, atau dibatalkan (Status: {$statusUpper}).",
                422,
            );
        }

        $source = strtolower((string) ($order->source ?? ''));
        $bulkService = app(BulkShippingLabelService::class);

        $canonical = $bulkService->resolveChannelOptions($source);
        $options = array_filter($canonical, fn ($v) => $v !== null && $v !== '');

        $sizeKey = in_array($requestedSize, [
            BulkShippingLabelService::SIZE_100X150,
            BulkShippingLabelService::SIZE_100X120,
        ], true) ? $requestedSize : BulkShippingLabelService::DEFAULT_SIZE;

        try {
            $result = $this->getShippingLabel($order, $options);

            $rawBytes = $this->extractLabelBytes($result);
            if ($rawBytes === null) {
                return $result;
            }

            $this->cacheShippingLabelBytes($order, $rawBytes, $order->shipping_label_doc_type);

            $normalized = $bulkService->normalizeToTarget($rawBytes, $sizeKey, $source);

            return [
                'type' => 'base64',
                'content_type' => 'application/pdf',
                'document_base64' => base64_encode($normalized),
                'source' => $source,
                'document_size' => $sizeKey,
            ];
        } catch (ShippingLabelPreparingException $e) {
            throw new UserFacingException('Aksi tidak dapat diproses', 'Gagal memproses pengiriman.', 202, ['detail' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            throw new UserFacingException('Aksi tidak dapat diproses', 'Gagal memproses pengiriman.', 422, ['detail' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            throw new UserFacingException('Aksi tidak dapat diproses', 'Gagal memproses pengiriman.', 422, ['detail' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('shippingLabel gagal', [
                'order_id' => $order->id,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);

            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Gagal mengambil label pengiriman: '.$e->getMessage(),
                422,
                ['detail' => $e->getMessage()],
            );
        }
    }

    private function extractLabelBytes(array $result): ?string
    {
        if (! empty($result['document_base64'])) {
            $decoded = base64_decode((string) $result['document_base64'], true);

            return $decoded === false ? null : $decoded;
        }
        if (! empty($result['bytes'])) {
            return (string) $result['bytes'];
        }
        $url = $result['url'] ?? ($result['doc_url'] ?? null);
        if (! empty($url)) {
            try {
                $response = Http::timeout(20)->retry(2, 500)->get($url);

                return $response->successful() ? $response->body() : null;
            } catch (\Throwable $e) {
                Log::warning('extractLabelBytes: url fetch failed', ['error' => $e->getMessage()]);

                return null;
            }
        }
        if (! empty($result['data']) && is_string($result['data'])) {
            $decoded = base64_decode((string) $result['data'], true);

            return $decoded === false ? null : $decoded;
        }

        return null;
    }

    public function resendShippingLabel(SalesOrder $order): void
    {
        if ($order->isManual()) {
            throw new UserFacingException(
                'Aksi tidak dapat diproses',
                'Pesanan manual tidak menggunakan label marketplace.',
                422,
            );
        }

        try {
            $this->retryShippingLabel($order);
        } catch (\InvalidArgumentException $e) {
            throw new UserFacingException('Aksi tidak dapat diproses', 'Gagal memproses pengiriman.', 422, ['detail' => $e->getMessage()]);
        }
    }

    private function idempotencyKey(?string $source, string $salesOrderNo): string
    {
        $marketplace = $source ?: 'manual';

        return "order:done:{$marketplace}:{$salesOrderNo}";
    }

    public function generateSalesOrderNo(?string $source, ?string $channelOrderNo = null, ?string $commercePlatform = null): array
    {
        $prefix = $this->resolveChannelPrefix($source, $commercePlatform);

        if ($prefix && $channelOrderNo) {

            return [
                'salesorder_no' => "{$prefix}-{$channelOrderNo}",
                'channel_order_no' => $channelOrderNo,
                'so_sequence' => null,
            ];
        }

        $sequence = DB::table('sales_orders')->max('so_sequence') ?? 0;
        $sequence++;

        return [
            'salesorder_no' => 'SO-'.str_pad($sequence, 5, '0', STR_PAD_LEFT),
            'channel_order_no' => null,
            'so_sequence' => $sequence,
        ];
    }

    private function resolveChannelPrefix(?string $source, ?string $commercePlatform = null): ?string
    {
        if (strtoupper((string) $commercePlatform) === 'TOKOPEDIA') {
            return self::CHANNEL_PREFIX['tokopedia'];
        }

        return $source && isset(self::CHANNEL_PREFIX[$source])
            ? self::CHANNEL_PREFIX[$source]
            : null;
    }

    public function createOrder(array $validated): SalesOrder
    {
        $source = $validated['source'] ?? null;

        if (empty($validated['salesorder_no'])) {
            $numbering = $this->generateSalesOrderNo($source, $validated['channel_order_no'] ?? null);
            $validated = array_merge($validated, $numbering);
        }

        $marketplace = $source ?: 'manual';
        $marketplaceOrderId = $validated['salesorder_no'];
        $idempotencyKey = $this->idempotencyKey($source, $marketplaceOrderId);

        if (Cache::has($idempotencyKey)) {
            throw new DuplicateOrderException($marketplace, $marketplaceOrderId);
        }

        try {
            $order = DB::transaction(function () use ($validated) {
                $order = SalesOrder::create(array_merge($validated, ['status' => 'pending']));
                $this->logStatusHistory($order, 'CREATED', ['to' => 'pending']);

                if (! $this->isManualSource($order->source)) {

                    $order->update([
                        'location_id' => $this->resolveChannelOrderLocationId($order),
                    ]);
                } elseif (! $order->location_id) {
                    try {
                        $locationId = $this->resolveLocationId($order);
                        $order->update(['location_id' => $locationId]);
                    } catch (\Exception $e) {
                        report($e);
                        Log::warning('SalesOrderService: gagal resolusi location_id, order dibuat tanpa lokasi', [
                            'order_id' => $order->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                if (! empty($validated['items'])) {
                    $this->orderRepository->syncOrderItems($order->id, $validated['items']);
                }

                $order->load('items');
                $this->reserveStockForOrder($order, $this->isManualSource($order->source));

                $order->status = 'reserved';
                $order->save();
                $this->logStatusHistory($order, 'PROCESS', ['from' => 'pending', 'to' => 'reserved']);

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw new DuplicateOrderException($marketplace, $marketplaceOrderId);
        }

        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);

        $this->notifications->toPermission(self::NOTIF_ORDER_PERMISSION, [
            'type' => 'order_new',
            'title' => 'Pesanan baru masuk',
            'message' => "Pesanan {$order->salesorder_no} ({$marketplace}) siap diproses.",
            'data' => [
                'sales_order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source' => $order->source,
                'link' => $this->orderLink($order->id),
            ],
        ]);

        SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));

        try {
            $kecilId = Location::getOfficialSmallWarehouseId();

            if ($kecilId && $order->location_id === $kecilId && ! $this->isManualSource($order->source)) {
                AutoDetectStockReplenishmentJob::dispatch();
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal dispatch AutoDetectStockReplenishmentJob setelah createOrder', [
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return $order->fresh('items');
    }

    public function logStatusHistory(
        SalesOrder $order,
        OrderActivityAction|string $action,
        ?array $metadata = null,
        $actor = null,
        OrderActivityEntity|string $entityType = OrderActivityEntity::ORDER,
        ?string $entityId = null,
    ): void {
        $actionEnum = $action instanceof OrderActivityAction
            ? $action
            : (OrderActivityAction::tryFrom($action)
                ?? OrderActivityAction::FIELD_CHANGED);

        $entityEnum = $entityType instanceof OrderActivityEntity
            ? $entityType
            : (OrderActivityEntity::tryFrom($entityType)
                ?? OrderActivityEntity::ORDER);

        if ($actor instanceof User) {
            $email = $actor->email;
            $name = $actor->name;
            $id = $actor->id;
        } elseif (is_array($actor)) {
            $email = $actor['email'] ?? null;
            $name = $actor['name'] ?? null;
            $id = $actor['id'] ?? null;
        } else {
            $authUser = auth()->user();
            $email = $authUser?->email;
            $name = $authUser?->name;
            $id = $authUser?->id;
        }

        if (! $email || $email === 'system') {
            if ($actionEnum === OrderActivityAction::FINISH_PACK) {
                $packer = Packlist::where('order_id', $order->id)
                    ->whereNotNull('packer_id')
                    ->with('packer')
                    ->latest('completed_at')
                    ->first()
                    ?->packer;
                if ($packer) {
                    $email = $packer->email;
                    $name = $packer->name;
                    $id = $packer->id;
                }
            } elseif ($actionEnum === OrderActivityAction::FINISH_PICK) {
                $picklist = Picklist::whereHas('items', function ($q) use ($order) {
                    $q->whereHas('orderItem', fn ($oi) => $oi->where('order_id', $order->id));
                })->whereNotNull('picker_id')->with('picker')->latest('completed_at')->first();
                if ($picklist?->picker) {
                    $email = $picklist->picker->email;
                    $name = $picklist->picker->name;
                    $id = $picklist->picker->id;
                }
            }
        }

        if ($email && (! $name || ! $id)) {
            $resolved = User::where('email', $email)->first();
            if ($resolved) {
                $name = $name ?: $resolved->name;
                $id = $id ?: $resolved->id;
            }
        }

        SalesOrderStatusHistory::create([
            'salesorder_id' => $order->id,
            'entity_type' => $entityEnum,
            'entity_id' => $entityId,
            'action_id' => $actionEnum->code(),
            'action' => $actionEnum,
            'actor_email' => $email ?? 'system',
            'actor_id' => $id,
            'actor_name' => $name ?? 'System',
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        Cache::put('so_audit:'.$order->id.':'.$actionEnum->value, true, 10);
    }

    public function logLabelPrinted(SalesOrder $order, $actor = null, ?string $documentType = null): void
    {
        $actorId = $actor instanceof User ? $actor->id : (is_array($actor) ? ($actor['id'] ?? 'user') : (auth()->id() ?? 'system'));
        $cacheKey = "label_printed:{$order->id}:{$actorId}";

        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, 5);

        $this->logStatusHistory(
            $order,
            OrderActivityAction::LABEL_PRINTED,
            [
                'document_type' => $documentType ?? $order->shipping_label_doc_type ?? 'THERMAL_AIR_WAYBILL',
                'tracking_number' => $order->tracking_number,
                'entity_no' => $order->salesorder_no,
            ],
            $actor,
        );
    }

    public function logFieldChange(
        SalesOrder $order,
        OrderActivityAction|string $action,
        array $prev,
        array $new,
        ?string $entityNo = null,
        ?string $note = null,
        $actor = null,
        ?SalesOrderItem $item = null,
    ): void {
        $prev = collect($prev)->except(self::AUDIT_IGNORED)->all();
        $new = collect($new)->except(self::AUDIT_IGNORED)->all();

        $changedKeys = [];
        foreach ($new as $key => $value) {
            $prevValue = $prev[$key] ?? null;
            if ($prevValue !== $value) {
                $changedKeys[] = $key;
            }
        }

        if (empty($changedKeys)) {
            return;
        }

        $prevValues = [];
        $newValues = [];
        foreach ($changedKeys as $key) {
            $prevValues[$key] = $prev[$key] ?? null;
            $newValues[$key] = $new[$key] ?? null;
        }

        $resolvedEntityNo = $entityNo
            ?? ($item ? ($item->description ?? $item->item_name ?? $order->salesorder_no) : $order->salesorder_no);

        $metadata = [
            'prev_values' => $prevValues,
            'new_values' => $newValues,
            'entity_no' => $resolvedEntityNo,
        ];
        if ($note !== null) {
            $metadata['note'] = $note;
        }

        $this->logStatusHistory(
            $order,
            $action,
            $metadata,
            $actor,
            $item
                ? OrderActivityEntity::ITEM
                : OrderActivityEntity::ORDER,
            $item?->id,
        );
    }

    public function auditedChannelFields(): array
    {
        return self::AUDITED_CHANNEL_FIELDS;
    }

    public function updateOrder(SalesOrder $order, array $validated, $actor = null): SalesOrder
    {
        $stockMutated = false;

        if (isset($validated['status']) && $validated['status'] !== $order->status) {
            $newStatus = $validated['status'];
            $this->validateTransition($order->status, $newStatus);
            $previousStatus = $order->status;

            if ($newStatus === 'cancelled'
                && in_array(strtolower((string) $order->source), ['tiktok', 'shopee', 'lazada', 'tokopedia', 'woocommerce'], true)) {
                throw new UserFacingException(
                    'Gunakan Ajukan Pembatalan',
                    'Pembatalan pesanan marketplace harus melalui aksi Ajukan Pembatalan (request-cancel), bukan ubah status.'
                );
            }

            $cancelReason = $newStatus === 'cancelled'
                ? ($validated['cancel_reason'] ?? null)
                : null;

            $action = self::STATUS_HISTORY_ACTIONS[$newStatus] ?? null;
            if ($action) {
                Cache::put('so_audit:'.$order->id.':'.$action, true, 10);
            }

            DB::transaction(function () use ($order, $newStatus, $cancelReason) {
                $this->applyStockTransition($order, $newStatus);
                $order->status = $newStatus;

                if ($newStatus === 'cancelled') {
                    $order->is_canceled = true;
                    $order->cancel_reason = $cancelReason;
                }

                $order->save();
            });

            if ($action) {
                $this->logStatusHistory(
                    $order,
                    $action,
                    ['from' => $previousStatus, 'to' => $newStatus],
                    $actor,
                );
            }

            $stockMutated = true;

            if ($newStatus === 'cancelled') {

                Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));
            }

            if ($newStatus === 'cancelled') {
                $actorId = null;
                if ($actor instanceof User) {
                    $actorId = $actor->id;
                } elseif (is_array($actor)) {
                    $actorId = $actor['id'] ?? null;
                }
                $this->notifyOrderCancelled($order, $cancelReason, 'manual', $actorId);
            }
        }

        $allowedFields = ['customer_name', 'shipping_address', 'seller_note'];
        $fieldsToUpdate = array_intersect_key($validated, array_flip($allowedFields));

        if (! empty($fieldsToUpdate)) {
            $order->update($fieldsToUpdate);
        }

        if ($stockMutated) {
            SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
        }

        return $order->fresh('items');
    }

    public function deleteOrderById(string $id): void
    {
        $order = $this->orderRepository->findWithItemsOrFail($id);
        $this->deleteOrder($order);
    }

    public function deleteOrder(SalesOrder $order): void
    {
        $order->load('items');
        $skus = $order->items->pluck('sku')->filter()->unique()->values()->all();

        if (! in_array($order->status, ['pending', 'cancelled'])) {
            if ($order->status === 'reserved') {
                DB::transaction(function () use ($order) {
                    $this->releaseStockForOrder($order);
                    $order->delete();
                });
            } else {
                throw new CannotDeleteActiveOrderException($order->status);
            }
        } else {
            $order->delete();
        }

        Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));

        if (! empty($skus)) {
            SyncStockJob::dispatch(null, $skus)->onQueue(config('queue.names.stock_sync'));
        }
    }

    public function relocateOrder(SalesOrder $order, string $newLocationId, bool $enforce = true): SalesOrder
    {
        return DB::transaction(function () use ($order, $newLocationId, $enforce) {
            if (! $this->isManualSource($order->source)) {
                $channelLocationId = $this->resolveChannelOrderLocationId($order);
                if ($newLocationId !== $channelLocationId) {
                    throw new UserFacingException(
                        'Lokasi order channel harus Gudang Kecil.',
                        'Order channel tidak dapat dialokasikan ke lokasi selain Gudang Kecil.',
                    );
                }
            }

            $oldLocationId = (string) ($order->location_id ?: $this->resolveLocationId($order));

            if (in_array($order->status, ['pending', 'reserved'], true) && $oldLocationId !== $newLocationId) {
                $order->load('items');

                foreach ($order->items as $item) {
                    if (! $item->item_id) {
                        continue;
                    }

                    $this->stockService->cancel(
                        $item->sku ?? "item:{$item->item_id}",
                        $item->item_id,
                        $oldLocationId,
                        $item->qty_in_base,
                        $order->salesorder_no,
                    );
                }

                $order->update(['location_id' => $newLocationId]);

                foreach ($order->items as $item) {
                    if (! $item->item_id) {
                        continue;
                    }

                    $this->stockService->reserve(
                        $item->sku ?? "item:{$item->item_id}",
                        $item->item_id,
                        $newLocationId,
                        $item->qty_in_base,
                        $order->salesorder_no,
                        $enforce,
                    );
                }

                SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
            } else {
                $order->update(['location_id' => $newLocationId]);
            }

            return $order->fresh();
        });
    }

    public function downloadOrderItem(SalesOrder $order, string $orderItemId, ?string $variantId = null): SalesOrder
    {
        if ($order->status === 'cancelled') {
            throw new ProductNotMappableException;
        }

        $item = $order->items()->whereKey($orderItemId)->firstOrFail();

        if ($item->item_id) {
            return $this->freshOrderWithItems($order);
        }

        if ($variantId === null && ! $this->skuExistsInMaster($item->sku)) {
            $this->attemptChannelProductPull($order, $item);
        }

        $wasUnmapped = $this->hasUnmappedItems($order->loadMissing('items'));

        $mutated = DB::transaction(function () use ($order, $orderItemId, $variantId) {
            $lockedOrder = SalesOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $item = $lockedOrder->items()->whereKey($orderItemId)->lockForUpdate()->firstOrFail();

            if ($item->item_id) {
                return false;
            }

            if ($variantId !== null) {
                if (! $this->orderRepository->variantExists($variantId)) {
                    throw new ProductNotMappableException($item->sku);
                }
                $resolvedVariantId = $variantId;
            } else {
                $resolvedVariantId = $this->orderRepository->variantIdBySku($item->sku);
            }

            if (! $resolvedVariantId) {
                throw new ProductNotMappableException($item->sku);
            }

            $item->update(['item_id' => $resolvedVariantId]);

            if ($lockedOrder->status === 'reserved') {
                $item->refresh();
                $this->reserveStockForItem($lockedOrder, $item, $this->isManualSource($lockedOrder->source));
            }

            return true;
        });

        if ($mutated) {
            SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
        }

        return $this->freshOrderWithItems($order);
    }

    private function freshOrderWithItems(SalesOrder $order): SalesOrder
    {
        return $order->fresh(['items', 'location:id,location_name']);
    }

    private function skuExistsInMaster(?string $sku): bool
    {
        return $this->orderRepository->variantIdBySku($sku) !== null;
    }

    private function hasUnmappedItems(SalesOrder $order): bool
    {
        foreach ($order->items as $item) {
            if (empty($item->item_id)) {
                return true;
            }
        }

        return false;
    }

    private function attemptChannelProductPull(SalesOrder $order, SalesOrderItem $item): void
    {
        $channel = $order->source;
        $shopId = $order->channel_shop_id;
        $externalProductId = $item->channel_product_id;

        if (! $channel || ! $shopId || ! $externalProductId) {
            return;
        }

        try {
            app(ChannelDownloadService::class)
                ->downloadProduct($channel, $shopId, $externalProductId);
        } catch (\Throwable $e) {
            Log::info('Auto-download produk dari channel gagal saat memproses order item', [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'channel' => $channel,
                'channel_shop_id' => $shopId,
                'channel_product_id' => $externalProductId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function upsertFromChannel(array $orderData): ?string
    {
        $channelStatus = $orderData['channel_status'] ?? 'UNKNOWN';
        $mappedStatus = $this->mapChannelStatusToInternal($channelStatus);
        $source = $orderData['source'] ?? null;

        if (isset($orderData['channel_shop_id']) && ! isset($orderData['is_shadow'])) {
            $isShadowMode = DB::table('channel_shops')
                ->where('shop_id', $orderData['channel_shop_id'])
                ->value('is_shadow_mode');
            if ($isShadowMode) {
                $orderData['is_shadow'] = true;
            }
        }

        if (array_key_exists('channel_status', $orderData) && $orderData['channel_status'] !== null && $orderData['channel_status'] !== '') {
            $orderData['channel_status_raw'] = $orderData['channel_status'];
        }

        if (! empty($orderData['channel_order_no']) && empty($orderData['salesorder_no'])) {
            $numbering = $this->generateSalesOrderNo(
                $source,
                $orderData['channel_order_no'],
                $orderData['commerce_platform'] ?? null,
            );
            $orderData['salesorder_no'] = $numbering['salesorder_no'];
        }

        try {
            DB::beginTransaction();

            $channelOrderNo = $orderData['channel_order_no'] ?? null;
            $existing = DB::table('sales_orders')
                ->where('salesorder_no', $orderData['salesorder_no'])
                ->when($channelOrderNo, fn ($q) => $q->orWhere('channel_order_no', $channelOrderNo))
                ->lockForUpdate()
                ->first();

            $wasNewOrder = $existing === null;
            $previousStatus = $existing?->status;
            $hasBuyerCancellationRequest = ! empty($orderData['cancel_requested_at'])
                && empty($existing?->cancel_requested_at)
                && ! $existing?->cancel_accepted_at
                && ! $existing?->cancel_rejected_at;

            if ($wasNewOrder && ! empty($orderData['items']) && is_array($orderData['items'])) {
                $unmapped = $this->orderRepository->unmappedSkus($orderData['items']);

                if (! empty($unmapped)) {
                    DB::rollBack();

                    Log::warning('Pesanan channel ditolak: SKU belum diunduh', [
                        'salesorder_no' => $orderData['salesorder_no'] ?? null,
                        'channel_order_no' => $orderData['channel_order_no'] ?? null,
                        'source' => $source,
                        'channel_shop_id' => $orderData['channel_shop_id'] ?? null,
                        'transaction_date' => $orderData['transaction_date'] ?? null,
                        'unmapped_skus' => $unmapped,
                    ]);

                    return null;
                }
            }

            if ($existing && $existing->handed_to_warehouse_at && in_array($mappedStatus, ['picked', 'packed'], true)) {
                $mappedStatus = $previousStatus;
            }

            $finalStatus = $this->resolveInternalStatus($previousStatus, $mappedStatus);
            $orderData['status'] = $finalStatus;

            if ($finalStatus === 'cancelled' && ($existing->channel_cancel_status ?? null) === 'pending') {
                $orderData['channel_cancel_status'] = 'accepted';
                $orderData['channel_cancel_error'] = null;
            }

            $order = $this->orderRepository->upsertOrderBySalesOrderNo($orderData['salesorder_no'], $orderData);

            if (! $order) {
                DB::rollBack();

                return null;
            }

            if (! $order->location_id) {
                try {
                    $locationId = $this->resolveLocationId($order);
                    $order->update(['location_id' => $locationId]);
                } catch (\Exception $e) {
                    report($e);
                    Log::warning('SalesOrderService: gagal resolusi location_id, order dibuat tanpa lokasi', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $this->orderRepository->syncOrderItems($order->id, $orderData['items']);
            }

            $order->load('items');

            if (! $this->isManualSource($order->source)) {
                $channelLocationId = $this->resolveChannelOrderLocationId($order);

                if ($existing !== null && in_array($order->status, ['pending', 'reserved'], true)) {
                    if ((string) $order->location_id !== $channelLocationId) {
                        $order = $this->relocateOrder($order, $channelLocationId, enforce: false);
                    }
                } elseif ($existing === null && (string) $order->location_id !== $channelLocationId) {
                    $order->update(['location_id' => $channelLocationId]);
                } elseif ($existing !== null && (string) $order->location_id !== $channelLocationId) {

                    Log::warning('sales_order.channel_terminal_order_location_mismatch', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'source' => $order->source,
                        'status' => $order->status,
                        'location_id' => $order->location_id,
                        'target_location_id' => $channelLocationId,
                    ]);
                }
            }

            if ($wasNewOrder && $order->source && ! empty($orderData['items']) && is_array($orderData['items'])) {
                $downloaded = $this->orderRepository->channelDownloadedSkus($orderData['items']);
                $undownloadedItemIds = $order->items
                    ->filter(fn ($it) => $it->item_id && $it->sku && ! in_array($it->sku, $downloaded, true))
                    ->pluck('id')
                    ->all();

                if (! empty($undownloadedItemIds)) {
                    DB::table('sales_order_items')
                        ->whereIn('id', $undownloadedItemIds)
                        ->update(['item_id' => null, 'updated_at' => now()]);

                    $order->load('items');

                    Log::info('Channel order dikarantina ke Gagal Download: SKU ada di master tapi belum di-download dari channel', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'source' => $order->source,
                        'channel_shop_id' => $order->channel_shop_id,
                        'skus' => $order->items
                            ->whereIn('id', $undownloadedItemIds)
                            ->pluck('sku')
                            ->values()
                            ->all(),
                    ]);
                }
            }

            if ($order->source && $this->hasUnmappedItems($order) && $finalStatus !== 'cancelled') {
                if ($finalStatus !== 'pending') {
                    Log::info('Channel order quarantined: produk belum di-download', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'source' => $order->source,
                        'channel_status' => $channelStatus,
                        'mapped_status' => $finalStatus,
                    ]);
                }
                $finalStatus = 'pending';
                $orderData['status'] = 'pending';
                if ($order->status !== 'pending') {
                    $order->update(['status' => 'pending']);
                }
            }

            if ($existing === null) {
                $this->logStatusHistory($order, 'CREATED', [
                    'to' => $order->status,
                    'source' => $order->source,
                    'channel_status' => $channelStatus,
                ]);
            } else {
                $this->logChannelStatusHistoryIfChanged(
                    $order,
                    $existing->channel_status ?? null,
                    $existing->channel_status_raw ?? null,
                );
            }

            $stockMutated = $this->reconcileStockTransition($order, $previousStatus, $finalStatus);

            if ($finalStatus === 'cancelled') {

                app(FulfillmentCleanupService::class)
                    ->detachCancelledOrder($order->id, 'system:channel-cancel');
            }

            DB::commit();

            $isShippedChannel = in_array(strtoupper((string) ($channelStatus ?? $order->channel_status)), ['SHIPPED', 'COMPLETED', 'DELIVERED', 'TO_CONFIRM_RECEIVE'], true)
                || in_array($finalStatus, ['shipped', 'completed', 'delivered'], true);

            if ($isShippedChannel
                && config('inventory.channel_auto_physical_backfill', false)
                && ! $order->is_shadow
                && ! $order->is_canceled
            ) {
                try {
                    $backfillResult = app(BackfillShippedOrdersStockService::class)->backfillOrder($order);

                    if (($backfillResult['success'] ?? false) !== true) {
                        Log::warning('Auto-deduct physical stock on shipped webhook tidak selesai', [
                            'order_id' => $order->id,
                            'salesorder_no' => $order->salesorder_no,
                            'result' => $backfillResult,
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Auto-deduct physical stock on shipped webhook gagal', [
                        'order_id' => $order->id,
                        'salesorder_no' => $order->salesorder_no,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($stockMutated) {
                try {
                    SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
                } catch (\Throwable $e) {
                    Log::warning('Dispatch SyncStockJob gagal setelah commit order', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($hasBuyerCancellationRequest) {
                $order->forceFill([
                    'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::PENDING->value,
                    'buyer_cancel_sync_decision' => null,
                    'buyer_cancel_sync_error' => null,
                ])->saveQuietly();

                AutoAcceptCancelRequestJob::dispatch($order->id)
                    ->onQueue(config('queue.names.channel_cancellation'));
            }

            if ($finalStatus === 'cancelled'
                && $existing?->cancel_requested_at
                && ! $existing?->cancel_accepted_at
                && ! $existing?->cancel_rejected_at
            ) {
                $order->forceFill([
                    'buyer_cancel_sync_status' => BuyerCancellationSyncStatus::SUCCEEDED->value,
                    'buyer_cancel_sync_decision' => RespondBuyerCancellationJob::ACCEPT,
                    'buyer_cancel_sync_error' => null,
                    'buyer_cancel_synced_at' => now(),
                ])->saveQuietly();
            }

            $isSettlementEligible = $order->is_canceled
                || ! in_array(strtoupper((string) $order->channel_status), ['UNPAID', 'UNCONFIRMED'], true);

            if (! $order->is_settled && $order->source && $order->channel_shop_id && $isSettlementEligible) {
                try {
                    SyncOrderFinanceJob::dispatch($order->id)
                        ->onQueue(config('queue.names.channel_finance'));
                } catch (\Throwable $e) {
                    Log::warning('Dispatch SyncOrderFinanceJob gagal setelah commit order', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->stampOrderSyncHealthy($order);

            return $order->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to upsert order: '.$e->getMessage());
            throw $e;
        }
    }

    private function stampOrderSyncHealthy(SalesOrder $order): void
    {
        if (! $order->source || ! $order->channel_shop_id) {
            return;
        }

        try {
            $repo = app(ChannelShopRepository::class);
            $shopUuid = $repo->getIdByShopIdAndChannelCode((string) $order->channel_shop_id, (string) $order->source);

            if ($shopUuid) {
                $repo->markOrderSyncOk($shopUuid);
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal stamp order-sync health setelah upsert order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logChannelStatusHistoryIfChanged(
        SalesOrder $order,
        ?string $previousChannelStatus,
        ?string $previousChannelStatusRaw,
    ): void {
        $currentChannelStatus = (string) ($order->channel_status ?? '');
        $currentChannelStatusRaw = (string) ($order->channel_status_raw ?? '');
        $previousChannelStatus = (string) ($previousChannelStatus ?? '');
        $previousChannelStatusRaw = (string) ($previousChannelStatusRaw ?? '');

        if ($previousChannelStatus === $currentChannelStatus
            && $previousChannelStatusRaw === $currentChannelStatusRaw
        ) {
            return;
        }

        $last = SalesOrderStatusHistory::query()
            ->where('salesorder_id', $order->id)
            ->where('action', 'CHANNEL_STATUS')
            ->latest('created_at')
            ->first();

        $lastMetadata = is_array($last?->metadata) ? $last->metadata : [];
        $lastNewValues = is_array($lastMetadata['new_values'] ?? null)
            ? $lastMetadata['new_values']
            : [];

        if (($lastNewValues['channel_status'] ?? null) === $currentChannelStatus
            && ($lastNewValues['channel_status_raw'] ?? null) === $currentChannelStatusRaw
        ) {
            return;
        }

        $this->logStatusHistory($order, 'CHANNEL_STATUS', [
            'prev_values' => [
                'channel_status' => $previousChannelStatus ?: null,
                'channel_status_raw' => $previousChannelStatusRaw ?: null,
            ],
            'new_values' => [
                'channel_status' => $currentChannelStatus ?: null,
                'channel_status_raw' => $currentChannelStatusRaw ?: null,
            ],
            'entity_no' => $order->salesorder_no,
            'origin' => 'channel_sync',
            'wms_status' => $order->status,
        ]);
    }

    public function updateOrderFinance(string $orderId, array $finance): ?SalesOrder
    {
        $order = SalesOrder::find($orderId);

        if (! $order) {
            return null;
        }

        $allowed = [
            'seller_voucher',
            'platform_voucher',
            'payment_voucher',
            'commission_fee',
            'service_fee',
            'transaction_fee',
            'affiliate_commission',
            'order_processing_fee',
            'other_fee',
            'seller_shipping_borne',
            'platform_shipping_rebate',
            'settlement_amount',
            'refund_total',
            'gross_amount',
            'fee_currency',
            'total_tax',
            'insurance_cost',
            'settled_at',
            'channel_settlement_id',
        ];

        $update = array_intersect_key($finance, array_flip($allowed));

        foreach ([
            'seller_voucher',
            'platform_voucher',
            'payment_voucher',
            'commission_fee',
            'service_fee',
            'transaction_fee',
            'affiliate_commission',
            'order_processing_fee',
            'other_fee',
            'seller_shipping_borne',
            'platform_shipping_rebate',
            'settlement_amount',
            'refund_total',
            'gross_amount',
            'total_tax',
            'insurance_cost',
        ] as $field) {
            if (array_key_exists($field, $update)) {
                $update[$field] = SalesOrderDataNormalizer::money($update[$field]);
            }
        }

        if (array_key_exists('settled_at', $update) && $update['settled_at'] === null) {
            unset($update['settled_at']);
        }

        if (array_key_exists('channel_settlement_id', $update)) {
            $rawSettlementId = $update['channel_settlement_id'];
            $update['channel_settlement_id'] = SalesOrderDataNormalizer::nullableUuid($rawSettlementId);

            if (SalesOrderDataNormalizer::isInvalidUuid($rawSettlementId)) {
                Log::warning('sales_order.invalid_channel_settlement_id_normalized', [
                    'order_id' => $order->id,
                    'source' => $order->source,
                    'channel_settlement_id' => $rawSettlementId,
                ]);
            }
        }

        if (array_key_exists('raw', $finance)) {
            $update['finance_raw'] = $finance['raw'];
        }

        $settledAt = $finance['settled_at'] ?? $order->settled_at;
        $update['is_settled'] = $settledAt !== null && ! $order->is_canceled;

        $update['finance_synced_at'] = now();

        $order->update($update);

        if (isset($finance['fee_lines']) && is_array($finance['fee_lines'])) {
            $this->orderRepository->replaceOrderFeeLines(
                $order->id,
                $finance['fee_lines'],
                (string) $order->source,
                (bool) $update['is_settled'],
            );
        }

        return $order->fresh();
    }

    private function mapChannelStatusToInternal(string $channelStatus): string
    {
        return match ($channelStatus) {

            'UNPAID', 'PENDING', 'ON_HOLD' => 'pending',

            'AWAITING_SHIPMENT', 'READY_TO_SHIP' => 'reserved',
            'RETRY_SHIP' => 'reserved',

            'AWAITING_COLLECTION', 'PROCESSED' => 'reserved',
            'PARTIALLY_SHIPPING' => 'reserved',

            'IN_TRANSIT' => 'shipped',
            'SHIPPED', 'TO_CONFIRM_RECEIVE' => 'shipped',
            'DELIVERED', 'COMPLETED' => 'shipped',

            'IN_CANCEL', 'TO_RETURN' => 'pending',
            'CANCELLED' => 'cancelled',
            default => 'pending',
        };
    }

    private const STATUS_RANK = [
        'pending' => 0,
        'reserved' => 1,
        'picked' => 2,
        'packed' => 3,
        'shipped' => 4,
    ];

    private function resolveInternalStatus(?string $currentStatus, string $newStatus): string
    {
        if (! $currentStatus) {
            return $newStatus;
        }

        if ($currentStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($newStatus === 'cancelled') {
            return 'cancelled';
        }

        $currentRank = $this->statusRank($currentStatus);
        $newRank = $this->statusRank($newStatus);

        return $newRank > $currentRank ? $newStatus : $currentStatus;
    }

    private function reconcileStockTransition(SalesOrder $order, ?string $previousStatus, string $finalStatus): bool
    {
        if ($finalStatus === 'cancelled') {
            return $this->releaseStockForStatus($order, $previousStatus);
        }

        if ($previousStatus === null) {

            $this->reserveStockForOrder($order, false);

            $toRank = $this->statusRank($finalStatus, 0);
            for ($rank = 2; $rank <= $toRank; $rank++) {
                match ($rank) {
                    2 => $this->pickStockForOrder($order),
                    default => null,
                };
            }

            return true;
        }

        if ($previousStatus === 'cancelled') {
            return false;
        }

        $fromRank = $this->statusRank($previousStatus, 0);
        if ($previousStatus === 'pending') {
            $fromRank = 1;
        }

        $toRank = $this->statusRank($finalStatus);

        if ($toRank <= $fromRank) {
            return false;
        }

        $mutated = false;

        for ($rank = $fromRank + 1; $rank <= $toRank; $rank++) {
            match ($rank) {
                1 => $this->reserveStockForOrder($order, false),
                2 => $this->pickStockForOrder($order),
                default => null,
            };

            if ($rank !== 3) {
                $mutated = true;
            }
        }

        return $mutated;
    }

    private function validateTransition(string $from, string $to): void
    {
        $fromStatus = SalesOrderStatus::tryFrom($from);
        $toStatus = SalesOrderStatus::tryFrom($to);

        if ($fromStatus === null || $toStatus === null) {
            Log::warning('Transisi status pesanan memakai nilai tak dikenal', [
                'from' => $from,
                'to' => $to,
            ]);

            throw new InvalidStatusTransitionException($from, $to);
        }

        if (! $fromStatus->canTransitionTo($toStatus)) {
            throw new InvalidStatusTransitionException($from, $to);
        }
    }

    private function statusRank(?string $status, int $default = -1): int
    {
        $canonical = SalesOrderStatus::tryFrom((string) $status)?->canonical();

        return $canonical === null
            ? $default
            : (self::STATUS_RANK[$canonical->value] ?? $default);
    }

    protected function applyStockTransition(SalesOrder $order, string $newStatus): void
    {
        $order->load('items');

        match ($newStatus) {
            'reserved' => $this->reserveStockForOrder($order),
            'picked' => $this->pickStockForOrder($order),

            'cancelled' => $this->releaseStockForOrder($order),
            default => null,
        };
    }

    public function promoteFromShadow(SalesOrder $order): bool
    {
        if (! $order->is_shadow || ! self::isPromotableFromShadow($order)) {
            return false;
        }

        DB::transaction(function () use ($order) {
            $order->is_shadow = false;
            $order->save();

            $canonical = SalesOrderStatus::tryFrom((string) $order->status)?->canonical();

            if ($canonical === SalesOrderStatus::RESERVED) {
                $order->load('items');
                $this->reserveStockForOrder($order, false);
            }
        });

        return true;
    }

    public static function isPromotableFromShadow(SalesOrder $order): bool
    {
        if ($order->is_canceled) {
            return false;
        }

        $canonical = SalesOrderStatus::tryFrom((string) $order->status)?->canonical();

        return in_array($canonical, [SalesOrderStatus::PENDING, SalesOrderStatus::RESERVED], true);
    }

    private function reserveStockForOrder(SalesOrder $order, bool $enforce = true): void
    {
        if (ShadowOrderGuard::blocks($order, 'reserve_stock')) {
            return;
        }
        foreach ($order->items as $item) {
            $this->reserveStockForItem($order, $item, $enforce);
        }
    }

    private function reserveStockForItem(SalesOrder $order, SalesOrderItem $item, bool $enforce = true): void
    {
        if (! $item->item_id) {
            return;
        }

        $locationId = $this->resolveLocationId($order);

        $this->stockService->reserve(
            $item->sku ?? "item:{$item->item_id}",
            $item->item_id,
            $locationId,
            $item->qty_in_base,
            $order->salesorder_no,
            $enforce,
        );
    }

    private function pickStockForOrder(SalesOrder $order): void
    {
        if (ShadowOrderGuard::blocks($order, 'pick_stock')) {
            return;
        }
        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            $this->stockService->pick(
                $item->sku ?? "item:{$item->item_id}",
                $item->item_id,
                $locationId,
                $item->qty_in_base,
                $order->salesorder_no,
            );
        }
    }

    private function releaseStockForOrder(SalesOrder $order): void
    {
        if (ShadowOrderGuard::blocks($order, 'release_stock')) {
            return;
        }
        $this->releaseStockForStatus($order, $order->status);
    }

    private function releaseStockForStatus(SalesOrder $order, ?string $status): bool
    {
        $order->loadMissing('items');

        $canonical = SalesOrderStatus::tryFrom((string) $status)?->canonical();

        if ($canonical === null) {
            Log::warning('Status pesanan tak dikenal saat melepas stok; sisa kunci tetap dilepas via ledger', [
                'salesorder_no' => $order->salesorder_no,
                'status' => $status,
            ]);
        }

        $restored = $this->restoreDirectCompletionAllocations($order);

        if (! $restored && in_array($canonical, [SalesOrderStatus::PICKED, SalesOrderStatus::PACKED], true)) {
            $restored = $this->restoreStockToOriginBins($order);
        }

        $released = $this->stockService->releaseReservationByTransaction($order->salesorder_no);

        return $restored || $released > 0;
    }

    private function restoreDirectCompletionAllocations(SalesOrder $order): bool
    {
        $allocations = OrderBinAllocation::where('order_id', $order->id)
            ->outstanding()
            ->lockForUpdate()
            ->get();

        if ($allocations->isEmpty()) {
            return false;
        }

        $skuByItem = $order->items->pluck('sku', 'item_id');
        $actorId = Auth::id() ?: null;

        foreach ($allocations as $allocation) {
            $this->stockService->restoreToBin(
                $skuByItem[$allocation->item_id] ?? "item:{$allocation->item_id}",
                (string) $allocation->item_id,
                (string) $allocation->location_id,
                (string) $allocation->bin_id,
                (int) $allocation->qty,
                $order->salesorder_no,
                'ORDER_COMPLETE_REVERSAL',
            );

            $allocation->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $actorId,
            ])->save();
        }

        return true;
    }

    private function restoreStockToOriginBins(SalesOrder $order): bool
    {
        $locationId = $this->resolveLocationId($order);

        $binAllocations = PicklistItem::where('order_id', $order->id)
            ->whereNotNull('bin_id')
            ->get(['item_id', 'sku', 'bin_id', 'qty_picked', 'qty_ordered'])
            ->groupBy('item_id');

        if ($binAllocations->isEmpty()) {
            return false;
        }

        $remainingByItem = [];
        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }
            $remainingByItem[$item->item_id] = [
                'sku' => $item->sku ?? "item:{$item->item_id}",
                'qty' => (int) $item->qty_in_base,
            ];
        }

        foreach ($binAllocations as $itemId => $rows) {
            if (! isset($remainingByItem[$itemId])) {
                continue;
            }

            foreach ($rows as $row) {
                $qty = (int) ($row->qty_picked ?: $row->qty_ordered);
                if ($qty <= 0) {
                    continue;
                }

                $take = min($qty, $remainingByItem[$itemId]['qty']);
                if ($take <= 0) {
                    break;
                }

                $this->stockService->restoreToBin(
                    $remainingByItem[$itemId]['sku'],
                    $itemId,
                    $locationId,
                    $row->bin_id,
                    $take,
                    $order->salesorder_no,
                );

                $remainingByItem[$itemId]['qty'] -= $take;
            }
        }

        foreach ($remainingByItem as $itemId => $data) {
            if ($data['qty'] <= 0) {
                continue;
            }

            if (! $binAllocations->has($itemId)) {
                continue;
            }

            $this->stockService->restore(
                $data['sku'],
                $itemId,
                $locationId,
                $data['qty'],
                $order->salesorder_no,
            );
        }

        return true;
    }

    private function resolveLocationId(SalesOrder $order): string
    {
        if ($order->location_id) {
            return (string) $order->location_id;
        }

        $isManual = $this->isManualSource($order->source);

        if (! $isManual) {
            return $this->resolveChannelOrderLocationId($order);
        }

        $defaultLocation = DB::table('locations')
            ->where('is_warehouse', true)
            ->where('is_active', true)
            ->first();

        if (! $defaultLocation) {
            throw new LocationNotConfiguredException($order->salesorder_no ?? 'unknown');
        }

        return $defaultLocation->id;
    }

    private function resolveChannelOrderLocationId(SalesOrder $order): string
    {
        $kecilId = Location::getOfficialSmallWarehouseId();

        if (! $kecilId) {
            throw new LocationNotConfiguredException($order->salesorder_no ?? 'unknown');
        }

        return (string) $kecilId;
    }

    private function isManualSource(?string $source): bool
    {
        return ! $this->channelWarehousePolicy->isChannelSource($source);
    }

    private const CONTACT_CHANNELS = ['marketplace_chat', 'whatsapp', 'phone', 'other'];

    private const CUSTOMER_DECISIONS = ['waiting', 'cancel', 'replace'];

    public function markContacted(string $orderId, ?string $channel = null, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);
        $this->assertContactChannel($channel);

        $order->update([
            'contacted_at' => now(),
            'contacted_by' => Auth::id() ?: null,
            'contact_channel' => $channel,
            'contact_note' => $note ?? $order->contact_note,
        ]);

        return $order->fresh();
    }

    public function bulkMarkContacted(array $orderIds, ?string $channel = null, ?string $note = null): int
    {
        $this->assertContactChannel($channel);

        return DB::transaction(function () use ($orderIds, $channel, $note) {
            $actorId = Auth::id() ?: null;
            $now = now();
            $count = 0;

            $orders = SalesOrder::whereIn('id', $orderIds)->get();
            foreach ($orders as $order) {
                $order->update([
                    'contacted_at' => $now,
                    'contacted_by' => $actorId,
                    'contact_channel' => $channel ?? $order->contact_channel,
                    'contact_note' => $note ?? $order->contact_note,
                ]);
                $count++;
            }

            return $count;
        });
    }

    public function setCustomerDecision(string $orderId, string $decision, ?string $note = null): SalesOrder
    {
        if (! in_array($decision, self::CUSTOMER_DECISIONS, true)) {
            throw new \InvalidArgumentException('Keputusan pembeli tidak valid.');
        }

        $order = SalesOrder::findOrFail($orderId);

        $updates = [
            'customer_decision' => $decision,
            'decision_at' => now(),
            'decision_by' => Auth::id() ?: null,
            'contact_note' => $note ?? $order->contact_note,
        ];

        if ($decision === 'cancel' && empty($order->cancel_requested_at)) {
            $updates['cancel_requested_at'] = now();
            $updates['cancel_requested_by'] = Auth::id() ?: null;
            $updates['cancel_channel'] = 'manual';
            $updates['cancel_request_reason'] = $note ?? $order->cancel_request_reason ?? 'Pembeli tidak menghendaki (stok kosong).';
        }

        $order->update($updates);

        return $order->fresh();
    }

    public function updateOrderItem(string $orderId, string $itemId, array $data): SalesOrder
    {
        return DB::transaction(function () use ($orderId, $itemId, $data) {
            $order = SalesOrder::with('items')->findOrFail($orderId);
            $this->assertEditableInternally($order);

            $item = $order->items->firstWhere('id', $itemId);
            if (! $item) {
                throw new \InvalidArgumentException('Item pesanan tidak ditemukan.');
            }

            $reservesStock = $order->status === 'reserved' && $item->item_id;
            $oldItemId = $item->item_id;
            $oldSku = $item->sku ?? "item:{$item->item_id}";
            $oldQty = (int) $item->qty_in_base;

            $updates = array_intersect_key($data, array_flip(['sku', 'description', 'qty_in_base', 'price', 'disc', 'disc_amount', 'tax_amount']));

            if (array_key_exists('sku', $updates) && $updates['sku'] !== null && $updates['sku'] !== $item->sku) {
                $newItemId = DB::table('product_variants')->where('sku', $updates['sku'])->value('id');
                if (! $newItemId) {
                    throw new \InvalidArgumentException("SKU {$updates['sku']} tidak ditemukan di master produk.");
                }
                $updates['item_id'] = $newItemId;
            }

            if ($updates) {
                $qty = (float) ($updates['qty_in_base'] ?? $item->qty_in_base);
                $price = (float) ($updates['price'] ?? $item->price);
                $discAmount = (float) ($updates['disc_amount'] ?? $item->disc_amount);
                $taxAmount = (float) ($updates['tax_amount'] ?? $item->tax_amount);
                $updates['amount'] = ($price * $qty) - $discAmount + $taxAmount;

                $prevSnapshot = collect(array_keys($updates))
                    ->mapWithKeys(fn ($k) => [$k => $item->{$k}])
                    ->all();

                $item->update($updates);

                $this->logFieldChange(
                    $order,
                    'FIELD_CHANGED',
                    $prevSnapshot,
                    $updates,
                    null,
                    null,
                    null,
                    $item->refresh(),
                );
            }

            if ($reservesStock) {
                $item->refresh();
                $newItemId = $item->item_id;
                $newSku = $item->sku ?? "item:{$newItemId}";
                $newQty = (int) $item->qty_in_base;

                $changed = $oldItemId !== $newItemId || $oldSku !== $newSku || $oldQty !== $newQty;
                if ($changed) {
                    $locationId = $this->resolveLocationId($order);
                    $this->stockService->cancel($oldSku, $oldItemId, $locationId, $oldQty, $order->salesorder_no);
                    if ($newItemId) {
                        $this->stockService->reserve($newSku, $newItemId, $locationId, $newQty, $order->salesorder_no, false);
                    }
                }
            }

            $this->recomputeOrderTotals($order->fresh(['items']));

            return $order->fresh(['items']);
        });
    }

    public function deleteOrderItem(string $orderId, string $itemId): SalesOrder
    {
        return DB::transaction(function () use ($orderId, $itemId) {
            $order = SalesOrder::with('items')->findOrFail($orderId);
            $this->assertEditableInternally($order);

            $item = $order->items->firstWhere('id', $itemId);
            if (! $item) {
                throw new \InvalidArgumentException('Item pesanan tidak ditemukan.');
            }

            if ($order->items->count() <= 1) {
                throw new \InvalidArgumentException('Pesanan harus memiliki minimal satu item. Batalkan pesanan bila ingin menghapus item terakhir.');
            }

            if ($order->status === 'reserved' && $item->item_id) {
                $this->stockService->cancel(
                    $item->sku ?? "item:{$item->item_id}",
                    $item->item_id,
                    $this->resolveLocationId($order),
                    (int) $item->qty_in_base,
                    $order->salesorder_no,
                );
            }

            $item->delete();
            $this->recomputeOrderTotals($order->fresh(['items']));

            return $order->fresh(['items']);
        });
    }

    public function isEmptyStock(SalesOrder $order): bool
    {
        return SalesOrder::whereKey($order->getKey())->hasStockShortfall()->exists();
    }

    private function assertEditableInternally(SalesOrder $order): void
    {

        if ($order->status === 'pending') {
            return;
        }

        $editableEmptyStock = $order->status === 'reserved'
            && is_null($order->handed_to_warehouse_at)
            && is_null($order->pick_failed_at)
            && ! $order->picklistItems()->exists()
            && $this->isEmptyStock($order);

        if ($editableEmptyStock) {
            return;
        }

        throw new \InvalidArgumentException('Item pesanan hanya bisa diubah/dihapus saat status Menunggu, atau saat pesanan berada di Stok Kosong dan belum diproses gudang.');
    }

    private function assertContactChannel(?string $channel): void
    {
        if ($channel !== null && ! in_array($channel, self::CONTACT_CHANNELS, true)) {
            throw new \InvalidArgumentException('Channel kontak tidak valid.');
        }
    }

    private function recomputeOrderTotals(SalesOrder $order): void
    {
        $order->loadMissing('items');

        $subTotal = 0.0;
        $totalDisc = 0.0;
        $totalTax = 0.0;
        foreach ($order->items as $it) {
            $subTotal += (float) $it->price * (float) $it->qty_in_base;
            $totalDisc += (float) $it->disc_amount;
            $totalTax += (float) $it->tax_amount;
        }

        $grandTotal = OrderTotals::grandTotal([
            'sub_total' => $subTotal,
            'total_disc' => $totalDisc,
            'total_tax' => $totalTax,
            'other_discount' => (float) $order->other_discount,
            'shipping_cost' => (float) $order->shipping_cost,
            'shipping_discount' => (float) $order->shipping_discount,
            'insurance_cost' => (float) $order->insurance_cost,
            'service_fee' => (float) $order->service_fee,
            'seller_voucher' => (float) $order->seller_voucher,
            'order_processing_fee' => (float) $order->order_processing_fee,
            'price_includes_tax' => (bool) $order->price_includes_tax,
        ]);

        $order->update([
            'sub_total' => $subTotal,
            'total_disc' => $totalDisc,
            'total_tax' => $totalTax,
            'grand_total' => $grandTotal,
        ]);
    }
}
