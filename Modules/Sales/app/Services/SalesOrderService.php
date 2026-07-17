<?php

namespace Modules\Sales\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Exceptions\CannotDeleteActiveOrderException;
use Modules\Sales\Exceptions\DuplicateOrderException;
use Modules\Sales\Exceptions\InsufficientStockException;
use Modules\Sales\Exceptions\InvalidStatusTransitionException;
use Modules\Sales\Exceptions\LocationNotConfiguredException;
use Modules\Sales\Exceptions\ProductNotMappableException;
use Modules\Sales\Exceptions\ShippingLabelPreparingException;
use Modules\Sales\Jobs\CancelChannelOrderJob;
use Modules\Sales\Jobs\PrepareShopeeShippingLabelJob;
use Modules\Sales\Jobs\RequestChannelAwbJob;
use Modules\Sales\Jobs\SyncStockJob;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderItem;
use Modules\Sales\Models\SalesOrderStatusHistory;
use Modules\Sales\Repositories\SalesOrderRepository;
use Modules\Outbound\Models\Picklist;
use Modules\Outbound\Models\PicklistItem;
use Modules\Outbound\Models\Shipment;
use Modules\Inventory\Jobs\AutoDetectStockReplenishmentJob;
use Modules\Notification\Services\NotificationDispatcher;
use Modules\Outbound\Models\ShipmentOrder;
use Modules\Warehouse\Models\Location;

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

    private const STATUS_HISTORY_ACTIONS = [
        'reserved'  => 'PROCESS',
        'picked'    => 'FINISH_PICK',
        'packed'    => 'FINISH_PACK',
        'shipped'   => 'SHIPPED',
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
        'tiktok'     => 'TT',
        'shopee'     => 'SP',
        'lazada'     => 'LZ',
        'tokopedia'  => 'TP',
        'blibli'     => 'BL',
    ];

    private const NOTIF_ORDER_PERMISSION = 'manage-pesanan';

    public function __construct(
        protected SalesOrderRepository $orderRepository,
        protected StockService $stockService,
        protected NotificationDispatcher $notifications,
    ) {}

    private function orderLink(string $id): string
    {
        return "/dashboard/pesanan/{$id}";
    }

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

    public function moveToReadyToProcess(array $orderIds, $actor = null): array
    {

        $skippedIds = SalesOrder::whereIn('id', $orderIds)
            ->where('status', 'reserved')
            ->hasStockShortfall()
            ->pluck('salesorder_no', 'id')
            ->all();

        $skipped = [];
        foreach ($skippedIds as $id => $orderNo) {
            $skipped[] = ['id' => (string) $id, 'salesorder_no' => $orderNo];
        }

        if (! empty($skipped)) {
            $actorId = null;
            if ($actor instanceof \App\Models\User) {
                $actorId = $actor->id;
            } elseif (is_array($actor)) {
                $actorId = $actor['id'] ?? null;
            }

            foreach ($skipped as $s) {
                $this->notifications->toPermission(self::NOTIF_ORDER_PERMISSION, [
                    'type' => 'order_empty_stock',
                    'title' => 'Pesanan tidak bisa diproses (stok kosong)',
                    'message' => "Pesanan {$s['salesorder_no']} di-skip karena ada SKU stok kosong.",
                    'data' => [
                        'sales_order_id' => $s['id'],
                        'salesorder_no' => $s['salesorder_no'],
                        'link' => $this->orderLink($s['id']),
                    ],
                ], excludeUserIds: array_filter([$actorId]));
            }
        }

        $eligibleIds = array_values(array_diff($orderIds, array_keys($skippedIds)));

        if (empty($eligibleIds)) {
            return ['moved' => 0, 'skipped' => $skipped];
        }

        [$count, $awbOrderIds, $shopeeLabelOrderIds] = DB::transaction(function () use ($eligibleIds, $actor) {
            $orders = SalesOrder::whereIn('id', $eligibleIds)
                ->where('status', 'reserved')
                ->get();

            $count = 0;
            $awbOrderIds = [];
            $shopeeLabelOrderIds = [];

            foreach ($orders as $order) {
                PicklistItem::where('order_id', $order->id)
                    ->whereHas('picklist', fn ($q) => $q->whereIn('status', [
                        Picklist::STATUS_DRAFT,
                        Picklist::STATUS_FAILED,
                    ]))
                    ->delete();

                $order->update([
                    'handed_to_warehouse_at' => now(),
                    'pick_failed_at'         => null,
                    'pick_failed_by'         => null,
                    'pick_fail_reason'       => null,
                ]);

                if (! $order->statusHistory()->where('action', 'PROCESS')->exists()) {
                    $this->logStatusHistory($order, 'PROCESS', [
                        'from' => 'reserved',
                        'to'   => 'reserved',
                    ], $actor);
                }

                $source = strtolower((string) $order->source);
                if (
                    in_array($source, ['shopee', 'tiktok', 'lazada'], true)
                    && empty($order->tracking_number)
                ) {
                    $awbOrderIds[] = $order->id;
                } elseif (
                    $source === 'shopee'
                    && ! empty($order->tracking_number)
                    && ! in_array($order->shipping_label_status, ['ready', 'self_design_required', 'preparing'], true)
                ) {
                    $shopeeLabelOrderIds[] = $order->id;
                }

                $count++;
            }

            return [$count, $awbOrderIds, $shopeeLabelOrderIds];
        });

        foreach ($awbOrderIds as $orderId) {
            try {
                RequestChannelAwbJob::dispatch($orderId);
            } catch (\Throwable $e) {
                Log::error('moveToReadyToProcess: gagal dispatch RequestChannelAwbJob', [
                    'order_id'  => $orderId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        foreach ($shopeeLabelOrderIds as $orderId) {
            try {
                PrepareShopeeShippingLabelJob::dispatch($orderId)
                    ->onQueue(config('queue.names.channel_sync'));
            } catch (\Throwable $e) {
                Log::error('moveToReadyToProcess: gagal dispatch PrepareShopeeShippingLabelJob', [
                    'order_id'  => $orderId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return ['moved' => $count, 'skipped' => $skipped];
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
                    'cancel_accepted_at' => now(),
                    'cancel_accepted_by' => $actorId,
                    'cancel_channel'     => $channel,
                    'cancel_reason'      => $finalReason,
                ]);

                Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));

                return $order->fresh();
            });

            $this->notifyOrderCancelled($result, $finalReason, $channel, $actorId);

            return $result;
        }

        $result = DB::transaction(function () use ($order, $actorId, $channel, $finalReason) {
            $this->applyStockTransition($order, 'cancelled');

            $order->update([
                'status'             => 'cancelled',
                'is_canceled'        => true,
                'cancel_reason'      => $finalReason,
                'cancel_accepted_at' => now(),
                'cancel_accepted_by' => $actorId,
                'cancel_channel'     => $channel,
            ]);

            Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));

            if ($order->source) {
                CancelChannelOrderJob::dispatch($order->id)->onQueue(config('queue.names.channel_sync'));
            }

            SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));

            return $order->fresh();
        });

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
            'cancel_requested_at'   => null,
            'cancel_request_reason' => null,
            'cancel_requested_by'   => null,
            'cancel_rejected_at'    => now(),
            'cancel_rejected_by'    => $actorId,
            'cancel_reject_reason'  => $reason,
        ]);

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
                $this->logStatusHistory($order, 'COMPLETED', ['to' => 'shipped']);
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

    public function saveCourierPickup(string $id, array $data): SalesOrder
    {
        $order = SalesOrder::findOrFail($id);
        $order->update([
            'courier_name'               => $data['courier_name'] ?? null,
            'courier_phone'              => $data['courier_phone'] ?? null,
            'pickup_code'                => $data['pickup_code'] ?? null,
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
            'is_paid'        => true,
            'paid_time'      => $data['paid_time'] ?? now(),
            'payment_method' => $data['payment_method'] ?? $order->payment_method,
        ]);
        $this->logStatusHistory($order, 'PAID');

        return $order->fresh();
    }

    public function requestAwb(array $data): array
    {
        $fulfillmentService = app(\Modules\Outbound\Services\OutboundFulfillmentService::class);
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
            $shopeeService = app(\Modules\Channel\Services\ShopeeOrderService::class);

            $requestedDocType = $options['document_type'] ?? null;

            $cacheUsable = $order->shipping_label_status === 'ready'
                && $order->shipping_label_doc_type
                && (! $requestedDocType || $requestedDocType === $order->shipping_label_doc_type);

            if ($cacheUsable) {
                $download = $shopeeService->downloadShippingDocument(
                    $shopId,
                    $channelOrderNo,
                    $order->shipping_label_doc_type
                );

                if (! empty($download['binary']) || ! empty($download['content'])) {
                    return [
                        'type'            => 'base64',
                        'content_type'    => $download['content_type'] ?? 'application/pdf',
                        'document_base64' => base64_encode((string) ($download['content'] ?? '')),
                        'source'          => 'shopee',
                    ];
                }

            }

            if ($order->shipping_label_status === 'self_design_required') {
                throw new \RuntimeException(
                    'Shopee mengharuskan label resi ini didesain manual (self-design AWB) di Seller Center. '
                    . 'Sistem tidak menyediakan cetak resi untuk kasus ini — resi hanya diambil dari channel.'
                );
            }

            if ($order->shipping_label_status === 'preparing') {
                $liveStatus = null;
                $liveDocType = $order->shipping_label_doc_type ?: 'THERMAL_AIR_WAYBILL';
                try {
                    $liveResult = $shopeeService->getShippingDocumentResult($shopId, $channelOrderNo, $liveDocType);
                    $liveRow = $liveResult['response']['result_list'][0] ?? [];
                    $liveStatus = strtoupper((string) ($liveRow['status'] ?? ''));
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('getShippingLabel: live get_shipping_document_result gagal, pakai cache', [
                        'order_id' => $order->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($liveStatus === 'READY') {
                    $download = $shopeeService->downloadShippingDocument($shopId, $channelOrderNo, $liveDocType);
                    if (! empty($download['binary']) || ! empty($download['content'])) {
                        $order->update([
                            'shipping_label_status'      => 'ready',
                            'shipping_label_doc_type'    => $liveDocType,
                            'shipping_label_prepared_at' => now(),
                        ]);
                        return [
                            'type'            => 'base64',
                            'content_type'    => $download['content_type'] ?? 'application/pdf',
                            'document_base64' => base64_encode((string) ($download['content'] ?? '')),
                            'source'          => 'shopee',
                        ];
                    }
                }

                if ($liveStatus === 'FAILED') {
                    $failMsg = $liveRow['fail_message'] ?? $liveRow['fail_error'] ?? 'Shopee menolak pembuatan label.';
                    $order->update(['shipping_label_status' => 'failed']);
                    throw new \RuntimeException("Shopee gagal membuat label: {$failMsg}");
                }

                $isStale = $order->shipping_label_prepared_at
                    ? $order->shipping_label_prepared_at->lt(now()->subMinutes(5))
                    : true;

                if ($isStale) {
                    PrepareShopeeShippingLabelJob::dispatch($order->id)
                        ->onQueue(config('queue.names.channel_sync'));
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
                    ->onQueue(config('queue.names.channel_sync'));

                throw new ShippingLabelPreparingException(
                    'Label sebelumnya gagal. Sedang dicoba ulang, tunggu 1-2 menit.'
                );
            }

            $shopeeDocType = $requestedDocType ?? 'NORMAL_AIR_WAYBILL';
            $result = $shopeeService->getAirwayBill($shopId, $channelOrderNo, $shopeeDocType);

            if (! empty($result['ready']) && ! empty($result['document_base64'])) {

                $order->update([
                    'shipping_label_status'      => 'ready',
                    'shipping_label_doc_type'    => $result['doc_type'] ?? $shopeeDocType,
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
                throw new \RuntimeException($result['message'] ?? $result['error']);
            }

            return [
                'type' => 'raw',
                'data' => $result,
                'source' => 'shopee',
            ];
        }

        if ($source === 'lazada') {
            $lazadaService = app(\Modules\Channel\Services\LazadaOrderService::class);
            $docType = $options['document_type'] ?? 'shippingLabel';
            $document = $lazadaService->getDocument($shopId, $channelOrderNo, $docType);

            $fileUrl = $document['url'] ?? ($document['file_url'] ?? null);
            if ($fileUrl) {
                return [
                    'type' => 'url',
                    'url' => $fileUrl,
                    'source' => 'lazada',
                ];
            }

            $file = $document['file'] ?? null;
            if (! empty($file)) {
                return [
                    'type' => 'base64',
                    'content_type' => 'application/pdf',
                    'document_base64' => $file,
                    'source' => 'lazada',
                ];
            }

            throw new \RuntimeException('Lazada tidak mengembalikan dokumen label.');
        }

        throw new \InvalidArgumentException("Channel '{$source}' belum mendukung cetak resi otomatis.");
    }

    public function retryShippingLabel(SalesOrder $order): void
    {
        if (strtolower((string) $order->source) !== 'shopee') {
            throw new \InvalidArgumentException('Retry label hanya tersedia untuk pesanan Shopee.');
        }

        if (empty($order->tracking_number)) {
            throw new \InvalidArgumentException('Pesanan belum memiliki nomor resi. Minta resi terlebih dahulu.');
        }

        $order->update([
            'shipping_label_status'      => null,
            'shipping_label_doc_type'    => null,
            'shipping_label_prepared_at' => null,
            'shipping_label_raw_data'    => null,
        ]);

        PrepareShopeeShippingLabelJob::dispatch($order->id)
            ->onQueue(config('queue.names.channel_sync'));
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
                $this->logStatusHistory($order, 'CREATED', ['to' => 'pending']);

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
                $this->logStatusHistory($order, 'PROCESS', ['from' => 'pending', 'to' => 'reserved']);

                return $order;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
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
            $kecilId = DB::table('locations')
                ->where('location_code', Location::SYSTEM_KECIL_CODE)
                ->value('id');

            if ($kecilId && $order->location_id === $kecilId && ! $this->isManualSource($order->source)) {
                AutoDetectStockReplenishmentJob::dispatch();
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal dispatch AutoDetectStockReplenishmentJob setelah createOrder', [
                'order_id' => $order->id ?? null,
                'error'    => $e->getMessage(),
            ]);
        }

        return $order->fresh('items');
    }

    public function logStatusHistory(
        SalesOrder $order,
        \Modules\Sales\Enums\OrderActivityAction|string $action,
        ?array $metadata = null,
        $actor = null,
        \Modules\Sales\Enums\OrderActivityEntity|string $entityType = \Modules\Sales\Enums\OrderActivityEntity::ORDER,
        ?string $entityId = null,
    ): void {
        $actionEnum = $action instanceof \Modules\Sales\Enums\OrderActivityAction
            ? $action
            : (\Modules\Sales\Enums\OrderActivityAction::tryFrom($action)
                ?? \Modules\Sales\Enums\OrderActivityAction::FIELD_CHANGED);

        $entityEnum = $entityType instanceof \Modules\Sales\Enums\OrderActivityEntity
            ? $entityType
            : (\Modules\Sales\Enums\OrderActivityEntity::tryFrom($entityType)
                ?? \Modules\Sales\Enums\OrderActivityEntity::ORDER);

        if ($actor instanceof \App\Models\User) {
            $email = $actor->email;
            $name  = $actor->name;
            $id    = $actor->id;
        } elseif (is_array($actor)) {
            $email = $actor['email'] ?? null;
            $name  = $actor['name'] ?? null;
            $id    = $actor['id'] ?? null;
        } else {
            $authUser = auth()->user();
            $email = $authUser?->email;
            $name  = $authUser?->name;
            $id    = $authUser?->id;
        }

        if ($email && (! $name || ! $id)) {
            $resolved = \App\Models\User::where('email', $email)->first();
            if ($resolved) {
                $name = $name ?: $resolved->name;
                $id   = $id ?: $resolved->id;
            }
        }

        SalesOrderStatusHistory::create([
            'salesorder_id' => $order->id,
            'entity_type'   => $entityEnum,
            'entity_id'     => $entityId,
            'action_id'     => $actionEnum->code(),
            'action'        => $actionEnum,
            'actor_email'   => $email ?? 'system',
            'actor_id'      => $id,
            'actor_name'    => $name ?? 'System',
            'metadata'      => $metadata,
            'created_at'    => now(),
        ]);
    }

    public function logFieldChange(
        SalesOrder $order,
        \Modules\Sales\Enums\OrderActivityAction|string $action,
        array $prev,
        array $new,
        ?string $entityNo = null,
        ?string $note = null,
        $actor = null,
        ?SalesOrderItem $item = null,
    ): void {
        $prev = collect($prev)->except(self::AUDIT_IGNORED)->all();
        $new  = collect($new)->except(self::AUDIT_IGNORED)->all();

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
        $newValues  = [];
        foreach ($changedKeys as $key) {
            $prevValues[$key] = $prev[$key] ?? null;
            $newValues[$key]  = $new[$key] ?? null;
        }

        $resolvedEntityNo = $entityNo
            ?? ($item ? ($item->description ?? $item->item_name ?? $order->salesorder_no) : $order->salesorder_no);

        $metadata = [
            'prev_values' => $prevValues,
            'new_values'  => $newValues,
            'entity_no'   => $resolvedEntityNo,
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
                ? \Modules\Sales\Enums\OrderActivityEntity::ITEM
                : \Modules\Sales\Enums\OrderActivityEntity::ORDER,
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

            if (isset(self::STATUS_HISTORY_ACTIONS[$newStatus])) {
                $this->logStatusHistory(
                    $order,
                    self::STATUS_HISTORY_ACTIONS[$newStatus],
                    ['from' => $previousStatus, 'to' => $newStatus],
                    $actor,
                );
            }

            $stockMutated = true;

            if ($newStatus === 'cancelled') {

                Cache::forget($this->idempotencyKey($order->source, $order->salesorder_no));
            }

            if ($newStatus === 'cancelled' && $order->source) {
                CancelChannelOrderJob::dispatch($order->id, $cancelReason)
                    ->onQueue(config('queue.names.orders'));
            }

            if ($newStatus === 'cancelled') {
                $actorId = null;
                if ($actor instanceof \App\Models\User) {
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
            throw new ProductNotMappableException();
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

            if ($wasUnmapped) {
                $fresh = $order->fresh('items');
                if ($fresh && ! $this->hasUnmappedItems($fresh) && $fresh->status !== 'cancelled') {
                    $this->notifyChannelOrderReady($fresh);
                }
            }
        }

        return $this->freshOrderWithItems($order);
    }

    private function notifyChannelOrderReady(SalesOrder $order): void
    {
        $marketplace = $order->source ?: 'channel';
        $this->notifications->toPermission(self::NOTIF_ORDER_PERMISSION, [
            'type' => 'order_new',
            'title' => 'Pesanan baru dari channel',
            'message' => "Pesanan {$order->salesorder_no} ({$marketplace}) masuk.",
            'data' => [
                'sales_order_id' => $order->id,
                'salesorder_no' => $order->salesorder_no,
                'source' => $order->source,
                'link' => $this->orderLink($order->id),
            ],
        ]);
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
        $source = $orderData['source'] ?? null;

        if (! empty($orderData['channel_order_no']) && empty($orderData['salesorder_no'])) {
            $numbering = $this->generateSalesOrderNo(
                $source,
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

            $wasNewOrder = $existing === null;
            $previousStatus = $existing?->status;

            if ($existing && $existing->handed_to_warehouse_at && in_array($mappedStatus, ['picked', 'packed'], true)) {
                $mappedStatus = $previousStatus;
            }

            $finalStatus = $this->resolveInternalStatus($previousStatus, $mappedStatus);
            $orderData['status'] = $finalStatus;

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

                }
            }

            if (isset($orderData['items']) && is_array($orderData['items'])) {
                $this->orderRepository->syncOrderItems($order->id, $orderData['items']);
            }

            $order->load('items');

            if ($order->source && $this->hasUnmappedItems($order) && $finalStatus !== 'cancelled') {
                if ($finalStatus !== 'pending') {
                    Log::info('Channel order quarantined: produk belum di-download', [
                        'order_id'        => $order->id,
                        'salesorder_no'   => $order->salesorder_no,
                        'source'          => $order->source,
                        'channel_status'  => $channelStatus,
                        'mapped_status'   => $finalStatus,
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
                    'to'             => $order->status,
                    'source'         => $order->source,
                    'channel_status' => $channelStatus,
                ]);
            }

            $stockMutated = $this->reconcileStockTransition($order, $previousStatus, $finalStatus);

            DB::commit();

            if ($wasNewOrder && ! $this->hasUnmappedItems($order)) {
                $this->notifyChannelOrderReady($order);
            }

            if ($stockMutated) {
                try {
                    SyncStockJob::dispatch($order->id)->onQueue(config('queue.names.stock_sync'));
                } catch (\Throwable $e) {
                    Log::warning('Dispatch SyncStockJob gagal setelah commit order', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            if (! $order->is_settled && $order->source && $order->channel_shop_id) {
                try {
                    \Modules\Sales\Jobs\SyncOrderFinanceJob::dispatch($order->id)
                        ->onQueue(config('queue.names.channel_sync'));
                } catch (\Throwable $e) {
                    Log::warning('Dispatch SyncOrderFinanceJob gagal setelah commit order', [
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            if ($finalStatus === 'packed' && ! empty($orderData['tracking_number'])) {
                try {
                    app(\Modules\Outbound\Services\ShipmentService::class)
                        ->autoCreateForChannelOrder($order->fresh());
                } catch (\Throwable $e) {
                    Log::warning('Auto-create shipment for channel order gagal', [
                        'order_id'       => $order->id,
                        'channel_status' => $channelStatus,
                        'error'          => $e->getMessage(),
                    ]);
                }
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
            'payment_voucher',
            'commission_fee',
            'service_fee',
            'transaction_fee',
            'affiliate_commission',
            'order_processing_fee',
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

            'UNPAID', 'PENDING', 'ON_HOLD'            => 'pending',

            'AWAITING_SHIPMENT', 'READY_TO_SHIP'      => 'reserved',
            'RETRY_SHIP'                              => 'reserved',

            'AWAITING_COLLECTION', 'PROCESSED'        => 'packed',
            'PARTIALLY_SHIPPING'                      => 'packed',

            'IN_TRANSIT'                              => 'shipped',
            'SHIPPED', 'TO_CONFIRM_RECEIVE'           => 'shipped',
            'DELIVERED', 'COMPLETED'                  => 'shipped',

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

        if (in_array($status, ['picked', 'packed'], true)) {
            return $this->restoreStockToOriginBins($order);
        }

        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }

            $locationId = $this->resolveLocationId($order);

            $this->stockService->cancel(
                $item->sku ?? "item:{$item->item_id}",
                $item->item_id,
                $locationId,
                $item->qty_in_base,
                $order->salesorder_no,
            );
        }

        return true;
    }

    private function restoreStockToOriginBins(SalesOrder $order): bool
    {
        $locationId = $this->resolveLocationId($order);

        $binAllocations = \Modules\Outbound\Models\PicklistItem::where('order_id', $order->id)
            ->whereNotNull('bin_id')
            ->get(['item_id', 'sku', 'bin_id', 'qty_picked', 'qty_ordered'])
            ->groupBy('item_id');

        $remainingByItem = [];
        foreach ($order->items as $item) {
            if (! $item->item_id) {
                continue;
            }
            $remainingByItem[$item->item_id] = [
                'sku'       => $item->sku ?? "item:{$item->item_id}",
                'qty'       => (int) $item->qty_in_base,
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

        $isManual = $this->isManualSource($order->source);

        if ($isManual && $order->location_id) {
            return $order->location_id;
        }

        $kecilId = DB::table('locations')
            ->where('location_code', Location::SYSTEM_KECIL_CODE)
            ->value('id');

        if ($kecilId) {
            return $kecilId;
        }

        if (! $isManual && $order->channel_shop_id) {
            $mapping = DB::table('channel_warehouses')
                ->where('store_id', $order->channel_shop_id)
                ->first();

            if ($mapping) {
                return $mapping->location_id;
            }
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

    private function isManualSource(?string $source): bool
    {
        return in_array($source, [null, '', 'manual'], true);
    }

    private const CONTACT_CHANNELS = ['marketplace_chat', 'whatsapp', 'phone', 'other'];
    private const CUSTOMER_DECISIONS = ['waiting', 'cancel', 'replace'];

    public function markContacted(string $orderId, ?string $channel = null, ?string $note = null): SalesOrder
    {
        $order = SalesOrder::findOrFail($orderId);
        $this->assertContactChannel($channel);

        $order->update([
            'contacted_at'    => now(),
            'contacted_by'    => Auth::id() ?: null,
            'contact_channel' => $channel,
            'contact_note'    => $note ?? $order->contact_note,
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
                    'contacted_at'    => $now,
                    'contacted_by'    => $actorId,
                    'contact_channel' => $channel ?? $order->contact_channel,
                    'contact_note'    => $note ?? $order->contact_note,
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
            'decision_at'       => now(),
            'decision_by'       => Auth::id() ?: null,
            'contact_note'      => $note ?? $order->contact_note,
        ];

        if ($decision === 'cancel' && empty($order->cancel_requested_at)) {
            $updates['cancel_requested_at']  = now();
            $updates['cancel_requested_by']  = Auth::id() ?: null;
            $updates['cancel_channel']       = 'manual';
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
            $oldSku    = $item->sku ?? "item:{$item->item_id}";
            $oldQty    = (int) $item->qty_in_base;

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
                $newSku    = $item->sku ?? "item:{$newItemId}";
                $newQty    = (int) $item->qty_in_base;

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

        $subTotal   = 0.0;
        $totalDisc  = 0.0;
        $totalTax   = 0.0;
        foreach ($order->items as $it) {
            $subTotal  += (float) $it->price * (float) $it->qty_in_base;
            $totalDisc += (float) $it->disc_amount;
            $totalTax  += (float) $it->tax_amount;
        }

        $grandTotal = $subTotal - $totalDisc + $totalTax
            + (float) $order->shipping_cost + (float) $order->insurance_cost;

        $order->update([
            'sub_total'   => $subTotal,
            'total_disc'  => $totalDisc,
            'total_tax'   => $totalTax,
            'grand_total' => $grandTotal,
        ]);
    }
}
