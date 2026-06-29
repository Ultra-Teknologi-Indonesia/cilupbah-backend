<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Exceptions\CannotDeleteActiveOrderException;
use Modules\Sales\Exceptions\DuplicateOrderException;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Exceptions\LocationNotConfiguredException;
use Modules\Sales\Exceptions\ProductNotMappableException;
use Modules\Sales\Jobs\CancelChannelOrderJob;
use Modules\Sales\Jobs\SyncStockJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;

class SalesOrderService
{
    private const ALLOWED_TRANSITIONS = [
        'pending'   => ['reserved', 'cancelled'],
        'reserved'  => ['picked', 'cancelled'],
        'picked'    => ['packed', 'cancelled'],
        'packed'    => ['shipped', 'cancelled'],
        'shipped'   => [],
        'cancelled' => [],
    ];

    private const IDEMPOTENCY_TTL = 172800;

    private const CHANNEL_PREFIX = [
        'tiktok'     => 'TT',
        'shopee'     => 'SP',
        'lazada'     => 'LZ',
        'tokopedia'  => 'TP',
        'blibli'     => 'BL',
    ];

    public function __construct(
        protected SalesOrderRepository $orderRepository,
        protected StockService $stockService,
    ) {}

    public function getPaginatedOrders()
    {
        return $this->orderRepository->getPaginatedOrders();
    }

    public function getTabCounts(): array
    {
        return $this->orderRepository->getTabCounts();
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

    public function moveToReadyToProcess(array $orderIds): int
    {
        return DB::transaction(function () use ($orderIds) {
            $orders = SalesOrder::whereIn('id', $orderIds)
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

                $count++;
            }

            return $count;
        });
    }

    public function acceptCancelRequest(string $orderId): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);

        if (! $order->cancel_requested_at) {
            throw new \InvalidArgumentException('Pesanan ini tidak memiliki permintaan pembatalan.');
        }

        if ($order->status === 'cancelled') {
            throw new InvalidStatusTransitionException($order->status, 'cancelled');
        }

        return DB::transaction(function () use ($order) {
            $this->applyStockTransition($order, 'cancelled');

            $order->update([
                'status'        => 'cancelled',
                'is_canceled'   => true,
                'cancel_reason' => $order->cancel_request_reason ?? 'seller_cancel_reason_other',
            ]);

            Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));

            if ($order->source) {
                CancelChannelOrderJob::dispatch($order->id)->onQueue(config('queue.names.channel_sync'));
            }

            SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));

            return $order->fresh();
        });
    }

    public function rejectCancelRequest(string $orderId): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);

        if (! $order->cancel_requested_at) {
            throw new \InvalidArgumentException('Pesanan ini tidak memiliki permintaan pembatalan.');
        }

        $order->update([
            'cancel_requested_at'     => null,
            'cancel_request_reason'   => null,
            'cancel_requested_by'     => null,
        ]);

        return $order->fresh();
    }

    public function markAsComplete(array $orderIds): int
    {
        return DB::transaction(function () use ($orderIds) {
            $count = 0;
            $orders = SalesOrder::with('items')
                ->whereIn('id', $orderIds)
                ->whereIn('status', ['packed', 'reserved'])
                ->get();

            foreach ($orders as $order) {

                $this->reconcileStockTransition($order, $order->status, 'shipped');
                $order->update(['status' => 'shipped']);
                SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
                $count++;
            }

            return $count;
        });
    }

    public function saveAirwaybill(array $data): SalesOrder
    {
        $order = SalesOrder::findOrFail($data['order_id']);
        $order->update([
            'tracking_number'   => $data['tracking_number'],
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

    public function setAsPaid(array $data): SalesOrder
    {
        $order = SalesOrder::findOrFail($data['order_id']);
        $order->update([
            'is_paid'        => true,
            'paid_time'      => $data['paid_time'] ?? now(),
            'payment_method' => $data['payment_method'] ?? $order->payment_method,
        ]);

        return $order->fresh();
    }

    public function requestAwb(array $data): array
    {
        $order = SalesOrder::findOrFail($data['order_id']);

        return ['order_id' => $order->id, 'status' => 'requested'];
    }

    public function getShippingLabel(SalesOrder $order, string $docType = 'shipping_label'): array
    {
        $source = $order->source;
        $shopId = $order->channel_shop_id;
        $channelOrderNo = $order->channel_order_no;

        if (! $source || ! $shopId || ! $channelOrderNo) {
            throw new \InvalidArgumentException('Pesanan ini bukan dari marketplace atau data channel tidak lengkap.');
        }

        if ($source === 'tiktok') {
            $tikTokService = app(\Modules\Channel\Services\TikTokOrderService::class);
            $shopRepo = app(\Modules\Channel\Repositories\ChannelShopRepository::class);
            $shop = $shopRepo->findByShopId($shopId);

            if (! $shop || ! $shop->access_token) {
                throw new \RuntimeException('Token akses TikTok Shop tidak ditemukan.');
            }

            $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
            $detailQueries = array_merge($queries, ['ids' => $channelOrderNo]);
            $tikTokClient = app(\Modules\Channel\Services\TikTokClient::class);
            $res = $tikTokClient->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);

            $packageId = null;
            foreach (($res['data']['orders'] ?? []) as $o) {
                foreach ($o['packages'] ?? [] as $pkg) {
                    if (! empty($pkg['id'])) {
                        $packageId = (string) $pkg['id'];
                        break 2;
                    }
                }
            }

            if (! $packageId) {
                throw new \RuntimeException('Package ID tidak ditemukan untuk pesanan TikTok ini.');
            }

            $result = $tikTokService->getShippingLabel($shopId, $packageId);
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
            $shopeeService = app(\Modules\Channel\Services\ShopeeOrderService::class);
            $shopeeDocType = $docType === 'shipping_label' ? 'NORMAL_AIR_WAYBILL' : 'NORMAL_AIR_WAYBILL';
            $result = $shopeeService->getAirwayBill($shopId, $channelOrderNo, $shopeeDocType);

            if (! empty($result['ready']) && ! empty($result['document_base64'])) {
                return [
                    'type' => 'base64',
                    'content_type' => $result['content_type'] ?? 'application/pdf',
                    'document_base64' => $result['document_base64'],
                    'source' => 'shopee',
                ];
            }

            if (! empty($result['error'])) {
                throw new \RuntimeException($result['message'] ?? $result['error']);
            }

            return [
                'type' => 'raw',
                'data' => $result,
                'source' => 'shopee',
            ];
        }

        throw new \InvalidArgumentException("Channel '{$source}' belum mendukung cetak resi otomatis.");
    }

    private function idempotencyKey(?string $source, string $salesOrderNo): string
    {
        $marketplace = $source ?: 'manual';

        return "order:done:{$marketplace}:{$salesOrderNo}";
    }

    public function generateSalesOrderNo(?string $source, ?string $channelOrderNo = null): array
    {
        if ($source && isset(self::CHANNEL_PREFIX[$source]) && $channelOrderNo) {
            $prefix = self::CHANNEL_PREFIX[$source];

            return [
                'salesorder_no'  => "{$prefix}-{$channelOrderNo}",
                'channel_order_no' => $channelOrderNo,
                'so_sequence'    => null,
            ];
        }

        $sequence = DB::table('sales_orders')->max('so_sequence') ?? 0;
        $sequence++;

        return [
            'salesorder_no'  => 'SO-' . str_pad($sequence, 5, '0', STR_PAD_LEFT),
            'channel_order_no' => null,
            'so_sequence'    => $sequence,
        ];
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

                if (! $order->location_id) {
                    try {
                        $locationId = $this->resolveLocationId($order);
                        $order->update(['location_id' => $locationId]);
                    } catch (\Exception $e) {

                    }
                }

                if (! empty($validated['items'])) {
                    $this->orderRepository->syncOrderItems($order->id, $validated['items']);
                }

                $order->load('items');
                $this->reserveStockForOrder($order, $this->isManualSource($order->source));

                $order->status = 'reserved';
                $order->save();

                return $order;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {

            throw new DuplicateOrderException($marketplace, $marketplaceOrderId);
        }

        Cache::put($idempotencyKey, true, self::IDEMPOTENCY_TTL);

        SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));

        return $order->fresh('items');
    }

    public function updateOrder(SalesOrder $order, array $validated): SalesOrder
    {
        $stockMutated = false;

        if (isset($validated['status']) && $validated['status'] !== $order->status) {
            $newStatus = $validated['status'];
            $this->validateTransition($order->status, $newStatus);

            $cancelReason = $newStatus === 'cancelled'
                ? ($validated['cancel_reason'] ?? 'seller_cancel_reason_out_of_stock')
                : null;

            DB::transaction(function () use ($order, $newStatus, $cancelReason) {
                $this->applyStockTransition($order, $newStatus);
                $order->status = $newStatus;

                if ($newStatus === 'cancelled') {
                    $order->is_canceled = true;
                    $order->cancel_reason = $cancelReason;
                }

                $order->save();
            });

            $stockMutated = true;

            if ($newStatus === 'cancelled') {

                Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));
            }

            if ($newStatus === 'cancelled' && $order->source) {
                CancelChannelOrderJob::dispatch($order->id, $cancelReason)
                    ->onQueue(config('queue.names.orders'));
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

    public function relocateOrder(SalesOrder $order, string $newLocationId): SalesOrder
    {
        return DB::transaction(function () use ($order, $newLocationId) {
            $oldLocationId = $this->resolveLocationId($order);

            if ($order->status === 'reserved' && $oldLocationId !== $newLocationId) {
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
            throw new ProductNotMappableException();
        }

        $item = $order->items()->whereKey($orderItemId)->firstOrFail();

        if ($item->item_id) {
            return $this->freshOrderWithItems($order);
        }

        if ($variantId === null && ! $this->skuExistsInMaster($item->sku)) {
            $this->attemptChannelProductPull($order, $item);
        }

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

    private function attemptChannelProductPull(SalesOrder $order, SalesOrderItem $item): void
    {
        $channel = $order->source;
        $shopId = $order->channel_shop_id;
        $externalProductId = $item->channel_product_id;

        if (! $channel || ! $shopId || ! $externalProductId) {
            return;
        }

        try {
            app(\Modules\Channel\Services\ChannelDownloadService::class)
                ->downloadProduct($channel, $shopId, $externalProductId);
        } catch (\Throwable $e) {
            Log::info('Auto-download produk dari channel gagal saat memproses order item', [
                'order_id'            => $order->id,
                'order_item_id'       => $item->id,
                'channel'             => $channel,
                'channel_shop_id'     => $shopId,
                'channel_product_id'  => $externalProductId,
                'error'               => $e->getMessage(),
            ]);
        }
    }

    public function upsertFromChannel(array $orderData): ?string
    {
        $channelStatus = $orderData['channel_status'] ?? 'UNKNOWN';
        $mappedStatus = $this->mapChannelStatusToInternal($channelStatus);

        if (! empty($orderData['channel_order_no']) && empty($orderData['salesorder_no'])) {
            $numbering = $this->generateSalesOrderNo(
                $orderData['source'] ?? null,
                $orderData['channel_order_no']
            );
            $orderData['salesorder_no'] = $numbering['salesorder_no'];
        }

        try {
            DB::beginTransaction();

            $existing = DB::table('sales_orders')
                ->where('salesorder_no', $orderData['salesorder_no'])
                ->lockForUpdate()
                ->first();

            $previousStatus = $existing?->status;
            $finalStatus = $this->resolveInternalStatus($previousStatus, $mappedStatus);
            $orderData['status'] = $finalStatus;

            $order = $this->orderRepository->upsertOrderBySalesOrderNo($orderData['salesorder_no'], $orderData);

            if (! $order->location_id) {
                try {
                    $locationId = $this->resolveLocationId($order);
                    $order->update(['location_id' => $locationId]);
                } catch (\Exception $e) {

                }
            }

            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $this->orderRepository->syncOrderItems($order->id, $orderData['items']);
            }

            $order->load('items');

            $stockMutated = $this->reconcileStockTransition($order, $previousStatus, $finalStatus);

            DB::commit();

            if ($stockMutated) {
                SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
            }

            if (($orderData['channel_status'] ?? null) === 'COMPLETED'
                && ! $order->is_settled
                && $order->source
                && $order->channel_shop_id
            ) {
                \Modules\Sales\Jobs\SyncOrderFinanceJob::dispatch($order->id)
                    ->onQueue(config('queue.names.channel_sync'));
            }

            return $order->id;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to upsert order: " . $e->getMessage());
            throw $e;
        }
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
            'commission_fee',
            'service_fee',
            'transaction_fee',
            'affiliate_commission',
            'seller_shipping_borne',
            'platform_shipping_rebate',
            'settlement_amount',
            'fee_currency',
        ];

        $update = array_intersect_key($finance, array_flip($allowed));

        if (array_key_exists('is_settled', $finance)) {
            $update['is_settled'] = (bool) $finance['is_settled'];
        }

        $update['finance_synced_at'] = now();

        $order->update($update);

        if (isset($finance['fee_lines']) && is_array($finance['fee_lines'])) {
            $this->orderRepository->replaceOrderFeeLines(
                $order->id,
                $finance['fee_lines'],
                (string) $order->source,
                (bool) ($finance['is_settled'] ?? false),
            );
        }

        return $order->fresh();
    }

    private function mapChannelStatusToInternal(string $channelStatus): string
    {
        return match ($channelStatus) {
            // Belum dibayar / menunggu (Shopee UNPAID/PENDING, TikTok UNPAID/ON_HOLD remorse 1jam)
            'UNPAID', 'PENDING', 'ON_HOLD'            => 'pending',

            // Siap diproses (Shopee READY_TO_SHIP/RETRY_SHIP, TikTok AWAITING_SHIPMENT)
            'AWAITING_SHIPMENT', 'READY_TO_SHIP'      => 'reserved',
            'RETRY_SHIP'                              => 'reserved',

            // Label shipping sudah dibuat (Shopee PROCESSED, TikTok AWAITING_COLLECTION/PARTIALLY_SHIPPING)
            'AWAITING_COLLECTION', 'PROCESSED'        => 'packed',
            'PARTIALLY_SHIPPING'                      => 'packed',

            // Sudah di tangan kurir / buyer
            'IN_TRANSIT'                              => 'shipped',
            'SHIPPED', 'TO_CONFIRM_RECEIVE'           => 'shipped',
            'DELIVERED', 'COMPLETED'                  => 'shipped',

            // Request batal/retur — masuk ke pending (akan distinguishing
            // ke tab khusus via channel_status di iterasi berikutnya)
            'IN_CANCEL', 'TO_RETURN'                  => 'pending',
            'CANCELLED'                               => 'cancelled',
            default                                   => 'pending',
        };
    }

    private const STATUS_RANK = [
        'pending'   => 0,
        'reserved'  => 1,
        'picked'    => 2,
        'packed'    => 3,
        'shipped'   => 4,
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

        $currentRank = self::STATUS_RANK[$currentStatus] ?? -1;
        $newRank = self::STATUS_RANK[$newStatus] ?? -1;

        return $newRank > $currentRank ? $newStatus : $currentStatus;
    }

    private function reconcileStockTransition(SalesOrder $order, ?string $previousStatus, string $finalStatus): bool
    {
        if ($finalStatus === 'cancelled') {
            return $this->releaseStockForStatus($order, $previousStatus);
        }

        if ($previousStatus === null) {

            $this->reserveStockForOrder($order, false);

            $toRank = self::STATUS_RANK[$finalStatus] ?? 0;
            for ($rank = 2; $rank <= $toRank; $rank++) {
                match ($rank) {
                    2       => $this->pickStockForOrder($order),
                    4       => $this->shipStockForOrder($order),
                    default => null,
                };
            }

            return true;
        }

        if ($previousStatus === 'cancelled') {
            return false;
        }

        $fromRank = self::STATUS_RANK[$previousStatus] ?? 0;
        if ($previousStatus === 'pending') {
            $fromRank = 1;
        }

        $toRank = self::STATUS_RANK[$finalStatus] ?? -1;

        if ($toRank <= $fromRank) {
            return false;
        }

        $mutated = false;

        for ($rank = $fromRank + 1; $rank <= $toRank; $rank++) {
            match ($rank) {
                1       => $this->reserveStockForOrder($order, false),
                2       => $this->pickStockForOrder($order),
                4       => $this->shipStockForOrder($order),
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
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];

        if (! in_array($to, $allowed)) {
            throw new InvalidStatusTransitionException($from, $to);
        }
    }

    protected function applyStockTransition(SalesOrder $order, string $newStatus): void
    {
        $order->load('items');

        match ($newStatus) {
            'reserved'  => $this->reserveStockForOrder($order),
            'picked'    => $this->pickStockForOrder($order),
            'shipped'   => $this->shipStockForOrder($order),
            'cancelled' => $this->releaseStockForOrder($order),
            default     => null,
        };
    }

    private function reserveStockForOrder(SalesOrder $order, bool $enforce = true): void
    {
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

    private function shipStockForOrder(SalesOrder $order): void
    {
        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            $this->stockService->ship(
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
        $this->releaseStockForStatus($order, $order->status);
    }

    private function releaseStockForStatus(SalesOrder $order, ?string $status): bool
    {
        if (! in_array($status, ['pending', 'reserved', 'picked', 'packed'], true)) {
            return false;
        }

        $order->loadMissing('items');

        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            if (in_array($status, ['pending', 'reserved'], true)) {
                $this->stockService->cancel(
                    $item->sku ?? "item:{$item->item_id}",
                    $item->item_id,
                    $locationId,
                    $item->qty_in_base,
                    $order->salesorder_no,
                );
            } else {
                $this->stockService->restore(
                    $item->sku ?? "item:{$item->item_id}",
                    $item->item_id,
                    $locationId,
                    $item->qty_in_base,
                    $order->salesorder_no,
                );
            }
        }

        return true;
    }

    private function resolveLocationId(SalesOrder $order): string
    {
        if ($order->location_id) {
            return $order->location_id;
        }

        if ($order->channel_shop_id) {
            $mapping = DB::table('channel_warehouses')
                ->where('store_id', $order->channel_shop_id)
                ->first();

            if ($mapping) {
                return $mapping->location_id;
            }
        }

        $defaultLocation = DB::table('locations')->where('is_warehouse', true)->where('is_active', true)->first();

        if (! $defaultLocation) {
            throw new LocationNotConfiguredException($order->salesorder_no ?? 'unknown');
        }

        return $defaultLocation->id;
    }

    private function isManualSource(?string $source): bool
    {
        return in_array($source, [null, '', 'manual'], true);
    }
}
