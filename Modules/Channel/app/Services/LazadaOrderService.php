<?php

namespace Modules\Channel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\ChannelCancelException;
use Modules\Channel\Exceptions\ChannelLabelUnsupportedException;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Jobs\RespondBuyerCancellationJob;
use Modules\Sales\Services\SalesOrderService;

class LazadaOrderService
{
    public function __construct(
        protected LazadaClient $client,
        protected LazadaToInternalOrderMapper $mapper,
        protected SalesOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
        protected LazadaAuthService $authService,
    ) {}

    public function pullOrders(string $shopId, ?string $updatedAfter = null, ?string $updatedBefore = null): int
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return 0;
        }

        $shop = $this->requireShop($shopId);

        $updateAfter = $updatedAfter ?: now()->subDays(7)->toIso8601String();
        $limit = 100;
        $offset = 0;
        $count = 0;

        $maxPages = 100;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = [
                'sort_by' => 'updated_at',
                'sort_direction' => 'ASC',
                'offset' => $offset,
                'limit' => $limit,
                'update_after' => $updateAfter,
            ];

            if ($updatedBefore) {
                $params['update_before'] = $updatedBefore;
            }

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/orders/get', $params, $token));

            $orders = $res['data']['orders'] ?? [];
            if (empty($orders)) {
                break;
            }

            $itemsByOrder = $this->fetchItemsForOrders($shop, array_column($orders, 'order_id'));

            foreach ($orders as $order) {
                $orderId = (string) ($order['order_id'] ?? '');

                try {
                    $internal = $this->mapper->map($order, $itemsByOrder[$orderId] ?? [], $shopId);
                    $this->orderService->upsertFromChannel($internal);
                    $count++;
                } catch (\Throwable $e) {
                    Log::error("Lazada: gagal upsert order {$orderId}: ".$e->getMessage());
                }
            }

            if (count($orders) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return $count;
    }

    public function listRecentOrderIds(string $shopId, ?string $updatedAfter = null): array
    {
        $shop = $this->requireShop($shopId);
        $updateAfter = $updatedAfter ?: now()->subDays(2)->toIso8601String();
        $limit = 100;
        $offset = 0;
        $ids = [];

        for ($page = 0; $page < 100; $page++) {
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/orders/get', [
                'sort_by' => 'updated_at',
                'sort_direction' => 'ASC',
                'offset' => $offset,
                'limit' => $limit,
                'update_after' => $updateAfter,
            ], $token));

            $orders = $res['data']['orders'] ?? [];
            foreach ($orders as $order) {
                $ids[] = (string) ($order['order_id'] ?? '');
            }

            if (count($orders) < $limit) {
                break;
            }

            $offset += $limit;
        }

        return array_values(array_filter($ids));
    }

    public function pullOrderById(string $shopId, string $orderId): int
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/order/get', ['order_id' => $orderId], $token));

        $order = $res['data'] ?? [];
        if (empty($order)) {
            return 0;
        }

        $itemsByOrder = $this->fetchItemsForOrders($shop, [$orderId]);

        $internal = $this->mapper->map($order, $itemsByOrder[$orderId] ?? [], $shopId);
        $this->orderService->upsertFromChannel($internal);

        return 1;
    }

    public function fulfillPack(string $shopId, string $orderId, string $shippingProviderId, string $deliveryType = 'dropship'): array
    {
        $shop = $this->requireShop($shopId);

        $items = $this->fetchOrderItemsWithStatus($shop, $orderId);
        $packableIds = $this->filterItemIdsByStatus($items, ['pending', 'repacked']);

        if (empty($packableIds)) {
            throw new \Exception("Order {$orderId} tidak punya item berstatus pending/repacked untuk di-pack.");
        }

        $params = [
            'delivery_type' => $deliveryType,
            'shipping_provider_id' => $shippingProviderId,
            'order_item_ids' => json_encode($packableIds),
        ];

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/fulfill/pack', $params, $token));

        Log::info('Lazada fulfillPack response', [
            'shop_id' => $shopId,
            'order_id' => $orderId,
            'params' => $params,
            'response' => $res,
        ]);

        $this->resyncLocalOrder($shopId, $orderId);

        return [
            'order_id' => $orderId,
            'order_item_ids' => $packableIds,
            'pack' => $res['data'] ?? $res['result']['data'] ?? [],
        ];
    }

    public function readyToShip(string $shopId, string $orderId, ?string $trackingNumber = null, ?string $packageId = null, string $deliveryType = 'dropship'): array
    {
        $shop = $this->requireShop($shopId);

        $items = $this->fetchOrderItemsWithStatus($shop, $orderId);
        $readyIds = $this->filterItemIdsByStatus($items, ['packed']);

        if (empty($readyIds)) {
            throw new \Exception("Order {$orderId} tidak punya item berstatus packed untuk ready-to-ship.");
        }

        $params = array_filter([
            'delivery_type' => $deliveryType,
            'order_item_ids' => json_encode($readyIds),
            'tracking_number' => $trackingNumber,
            'package_id' => $packageId,
        ], fn ($v) => $v !== null);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/package/rts', $params, $token));

        Log::info('Lazada readyToShip response', [
            'shop_id' => $shopId,
            'order_id' => $orderId,
            'params' => $params,
            'response' => $res,
        ]);

        $this->resyncLocalOrder($shopId, $orderId);

        return [
            'order_id' => $orderId,
            'order_item_ids' => $readyIds,
            'rts' => $res['data'] ?? $res['result']['data'] ?? [],
        ];
    }

    public function getOrderTrace(string $shopId, string $orderId): array
    {
        if ($orderId === '') {
            return [];
        }

        try {
            $shop = $this->requireShop($shopId);
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/logistic/order/trace', [
                'order_id' => $orderId,
            ], $token));

            return $res['data'] ?? $res['result'] ?? [];
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal ambil order trace {$orderId}: ".$e->getMessage());

            return [];
        }
    }

    public function printAwb(string $shopId, string $orderId, int $maxAttempts = 5, int $delayMicroseconds = 1_000_000): array
    {
        $attempt = 0;

        do {
            $document = $this->getDocument($shopId, $orderId, 'shippingLabel');

            if (! empty($document)) {
                return ['order_id' => $orderId, 'document' => $document];
            }

            $attempt++;
            if ($attempt < $maxAttempts) {
                usleep($delayMicroseconds);
            }
        } while ($attempt < $maxAttempts);

        throw new \Exception("Dokumen AWB order {$orderId} belum siap setelah {$maxAttempts} percobaan.");
    }

    public function cancelOrder(string $shopId, string $orderId, int|string $reasonId, ?string $reasonDetail = null): array
    {
        $shop = $this->requireShop($shopId);

        $itemIds = $this->orderItemIds($shop, $orderId);
        if (empty($itemIds)) {
            throw new \Exception("Order {$orderId} tidak punya item untuk dibatalkan.");
        }

        $cancelled = [];
        foreach ($itemIds as $itemId) {
            $params = [
                'reason_id' => (string) $reasonId,
                'order_item_id' => (string) $itemId,
            ];
            if ($reasonDetail) {
                $params['reason_detail'] = $reasonDetail;
            }

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/cancel', $params, $token));

            $code = (string) ($res['code'] ?? '0');
            if ($code !== '0' && $code !== '') {
                $message = $res['message'] ?? $code;

                $final = (bool) preg_match('/status|not[_ ]?allowed|reverse|invalid[_ ]?order/i', $code.' '.$message);

                throw new ChannelCancelException(
                    "Lazada menolak pembatalan {$orderId} (item {$itemId}): {$message}",
                    retryable: ! $final,
                    channelCode: $code,
                );
            }

            $cancelled[] = $itemId;
        }

        $this->resyncLocalOrder($shopId, $orderId);

        return ['order_id' => $orderId, 'cancelled_item_ids' => $cancelled];
    }

    public function getTransactionDetails(string $shopId, string $orderId): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/finance/transaction/details/get', [
            'trade_order_id' => $orderId,
            'start_time' => now()->subDays(180)->format('Y-m-d'),
            'end_time' => now()->format('Y-m-d'),
        ], $token));

        return $res['data'] ?? [];
    }

    public function getPayoutStatus(string $shopId, Carbon $createdAfter): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/finance/payout/status/get', [
            'created_after' => $createdAfter->format('Y-m-d'),
        ], $token));

        return $res['data'] ?? [];
    }

    public function getCancelReasons(string $shopId): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/order/failure_reason/get', [], $token));

        return $res['data'] ?? [];
    }

    public function getShipmentProviders(string $shopId): array
    {
        $shop = $this->requireShop($shopId);

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/shipment/providers/get', [], $token));

        return $res['data']['shipment_providers'] ?? ($res['data'] ?? []);
    }

    public function itemStatuses(string $shopId, string $orderId): array
    {
        $shop = $this->requireShop($shopId);
        $items = $this->fetchOrderItemsWithStatus($shop, $orderId);

        return array_map(
            fn (array $row) => strtolower((string) ($row['status'] ?? $row['item_status'] ?? '')),
            $items
        );
    }

    public function getDocument(string $shopId, string $orderId, string $docType = 'shippingLabel'): array
    {
        $shop = $this->requireShop($shopId);

        $itemIds = $this->orderItemIds($shop, $orderId);
        if (empty($itemIds)) {
            throw new \Exception("Order {$orderId} tidak punya item.");
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/order/document/get', [
            'doc_type' => $docType,
            'order_item_ids' => json_encode($itemIds),
        ], $token));

        return $res['data']['document'] ?? ($res['data'] ?? []);
    }

    public function getPackageDocument(string $shopId, array $packageIds, string $docType = 'PDF'): array
    {
        $packageIds = array_values(array_unique(array_filter(array_map('strval', $packageIds), fn ($v) => $v !== '')));
        if (empty($packageIds)) {
            throw new \RuntimeException("Order pada toko {$shopId} belum punya package_id untuk cetak dokumen Lazada.");
        }

        $shop = $this->requireShop($shopId);

        $packages = array_map(fn ($id) => ['package_id' => $id], array_slice($packageIds, 0, 20));

        $params = [
            'getDocumentReq' => json_encode([
                'doc_type' => $docType,
                'print_item_list' => false,
                'packages' => $packages,
            ]),
        ];

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/package/document/get', $params, $token));

        $result = $res['result'] ?? $res;
        $data = $result['data'] ?? [];

        $file = $data['file'] ?? null;
        $url = $data['pdf_url'] ?? $data['url'] ?? null;

        if (! empty($file) || ! empty($url)) {
            return [
                'file' => $file ?: null,
                'pdf_url' => $url ?: null,
                'doc_type' => strtoupper((string) ($data['doc_type'] ?? $docType)),
            ];
        }

        $errorCode = (string) ($result['error_code'] ?? '');
        $errorMsg = (string) ($result['error_msg'] ?? $res['message'] ?? '');

        if ($errorCode === '50008'
            || preg_match('/\bSOF\b|\bDBS\b|not support operation for sof/i', $errorMsg)) {
            throw new ChannelLabelUnsupportedException(
                'Order Lazada bertipe SOF/DBS — label tidak disediakan via API. Ambil resi dari Seller Center.'
            );
        }

        return [];
    }

    public function resolvePackageIds(string $shopId, string $orderId): array
    {
        $shop = $this->requireShop($shopId);
        $items = $this->fetchItemsForOrders($shop, [$orderId])[$orderId] ?? [];

        $ids = [];
        foreach ($items as $row) {
            $pid = $row['package_id'] ?? null;
            if ($pid !== null && $pid !== '') {
                $ids[(string) $pid] = true;
            }
        }

        return array_keys($ids);
    }

    protected function orderItemIds(object $shop, string $orderId): array
    {
        $items = $this->fetchItemsForOrders($shop, [$orderId])[$orderId] ?? [];

        return array_values(array_filter(array_map(
            fn (array $row) => isset($row['order_item_id']) ? (string) $row['order_item_id'] : null,
            $items
        )));
    }

    protected function fetchOrderItemsWithStatus(object $shop, string $orderId): array
    {
        return $this->fetchItemsForOrders($shop, [$orderId])[$orderId] ?? [];
    }

    protected function filterItemIdsByStatus(array $items, array $allowedStatuses): array
    {
        $allowed = array_map('strtolower', $allowedStatuses);

        return array_values(array_filter(array_map(function (array $row) use ($allowed) {
            $status = strtolower((string) ($row['status'] ?? $row['item_status'] ?? ''));

            if (! in_array($status, $allowed, true)) {
                return null;
            }

            return isset($row['order_item_id']) ? (string) $row['order_item_id'] : null;
        }, $items)));
    }

    protected function resyncLocalOrder(string $shopId, string $orderId): void
    {
        try {
            $this->pullOrderById($shopId, $orderId);
        } catch (\Throwable $e) {
            Log::warning("Lazada: resync order {$orderId} gagal pasca aksi: ".$e->getMessage());
        }
    }

    protected function fetchItemsForOrders(object $shop, array $orderIds): array
    {
        $itemsByOrder = [];

        foreach (array_chunk($orderIds, 50) as $chunk) {
            $params = ['order_ids' => json_encode(array_map('strval', $chunk))];

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/orders/items/get', $params, $token));

            foreach ($res['data'] ?? [] as $entry) {
                $itemsByOrder[(string) ($entry['order_id'] ?? '')] = $entry['order_items'] ?? [];
            }
        }

        return $itemsByOrder;
    }

    public function fetchReturnTracking(string $shopId, ?string $reverseOrderId): array
    {
        $empty = ['tracking_number' => null, 'carrier' => null, 'shipped_at' => null];

        if (! $reverseOrderId) {
            return $empty;
        }

        try {
            $shop = $this->requireShop($shopId);

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/reverse/order/detail/get',
                ['reverse_order_id' => $reverseOrderId],
                $token,
            ));

            $detail = $res['data'] ?? $res['result'] ?? $res;
            $logistics = $detail['reverse_logistics'] ?? $detail['logistics'] ?? [];

            $tracking = $detail['tracking_number']
                ?? $detail['return_tracking_number']
                ?? $logistics['tracking_number']
                ?? null;
            $carrier = $detail['shipping_provider']
                ?? $logistics['shipping_provider']
                ?? $logistics['logistics_provider']
                ?? null;
            $shippedAt = $detail['ship_time'] ?? $detail['return_ship_time'] ?? null;

            return [
                'tracking_number' => $tracking ? (string) $tracking : null,
                'carrier' => $carrier ? (string) $carrier : null,
                'shipped_at' => $shippedAt ? (string) $shippedAt : null,
            ];
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal ambil resi retur (reverse_order_id={$reverseOrderId}): ".$e->getMessage());

            return $empty;
        }
    }

    public function fetchReturnDetail(string $shopId, ?string $reverseOrderId): array
    {
        $empty = [
            'channel_status' => null,
            'reason_code' => null,
            'reason_text' => null,
            'refund_amount' => null,
            'refund_currency' => null,
            'shipping_fee_original' => null,
            'shipping_fee_return' => null,
            'tracking_number' => null,
            'carrier' => null,
            'shipped_at' => null,
            'raw' => [],
        ];

        if (! $reverseOrderId) {
            return $empty;
        }

        try {
            $shop = $this->requireShop($shopId);

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/order/reverse/return/detail/list',
                ['reverse_order_id' => $reverseOrderId],
                $token,
            ));

            $detail = $res['data'] ?? $res['result'] ?? $res;
            $logistics = $detail['reverse_logistics'] ?? $detail['logistics'] ?? [];

            $tracking = $detail['tracking_number']
                ?? $detail['return_tracking_number']
                ?? $logistics['tracking_number']
                ?? null;
            $carrier = $detail['shipping_provider']
                ?? $logistics['shipping_provider']
                ?? $logistics['logistics_provider']
                ?? null;
            $shippedAt = $detail['ship_time'] ?? $detail['return_ship_time'] ?? null;

            return [
                'channel_status' => isset($detail['reverse_status']) ? (string) $detail['reverse_status'] : null,
                'reason_code' => $detail['reason'] ?? null,
                'reason_text' => $detail['reason_text'] ?? null,
                'refund_amount' => isset($detail['refund_amount']) ? (float) $detail['refund_amount'] : null,
                'refund_currency' => $detail['currency'] ?? null,
                'shipping_fee_original' => isset($detail['origin_shipping_fee']) ? (float) $detail['origin_shipping_fee'] : null,
                'shipping_fee_return' => isset($detail['logistics_cost'])
                    ? (float) $detail['logistics_cost']
                    : (isset($detail['return_shipping_fee']) ? (float) $detail['return_shipping_fee'] : null),
                'tracking_number' => $tracking ? (string) $tracking : null,
                'carrier' => $carrier ? (string) $carrier : null,
                'shipped_at' => $shippedAt ? (string) $shippedAt : null,
                'raw' => $detail,
            ];
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal ambil detail retur (reverse_order_id={$reverseOrderId}): ".$e->getMessage());

            return $empty;
        }
    }

    public function fetchReturnHistory(string $shopId, string $reverseOrderId): array
    {
        try {
            $shop = $this->requireShop($shopId);

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/order/reverse/return/history/list',
                ['reverse_order_id' => $reverseOrderId],
                $token,
            ));

            $entries = $res['data']['history'] ?? $res['result']['history'] ?? [];

            $records = [];
            foreach ($entries as $entry) {
                $records[] = [
                    'type' => $entry['status'] ?? 'UNKNOWN',
                    'operator' => $entry['operator'] ?? 'PLATFORM',
                    'description' => $entry['comment'] ?? null,
                    'timestamp' => $entry['create_time'] ?? null,
                ];
            }

            return ['records' => $records];
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal ambil riwayat banding retur (reverse_order_id={$reverseOrderId}): ".$e->getMessage());

            return ['records' => []];
        }
    }

    public function getReverseOrdersForSeller(
        string $shopId,
        ?string $tradeOrderId = null,
        ?string $reverseOrderId = null,
        array $requestTypes = ['CANCEL'],
        array $reverseStatuses = ['CANCEL_INIT', 'REQUEST_INITIATE'],
    ): array {
        $shop = $this->requireShop($shopId);
        $params = [
            'request_type_list' => json_encode(array_values($requestTypes), JSON_THROW_ON_ERROR),
            'reverse_status_list' => json_encode(array_values($reverseStatuses), JSON_THROW_ON_ERROR),
            'page_size' => 100,
            'page_no' => 1,
        ];

        if ($tradeOrderId) {
            $params['trade_order_id'] = $tradeOrderId;
        }
        if ($reverseOrderId) {
            $params['reverse_order_id'] = $reverseOrderId;
        }

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
            'GET',
            (string) config('services.lazada.buyer_cancel_list_path', '/reverse/getreverseordersforseller'),
            $params,
            $token,
        ));

        return $res['result']['items']
            ?? $res['data']['items']
            ?? $res['data']['reverse_orders']
            ?? $res['reverse_orders']
            ?? [];
    }

    public function findBuyerCancellation(string $shopId, string $orderId, ?string $reverseOrderId = null): ?array
    {
        $items = $this->getReverseOrdersForSeller($shopId, $orderId, $reverseOrderId);

        foreach ($items as $item) {
            $requestType = strtoupper((string) ($item['request_type'] ?? $item['reverse_type'] ?? ''));
            $status = strtoupper((string) ($item['reverse_status'] ?? $item['status'] ?? ''));

            if ($requestType !== 'CANCEL' || ($status !== '' && ! in_array($status, ['CANCEL_INIT', 'REQUEST_INITIATE', 'CANCEL_PENDING'], true))) {
                continue;
            }

            $lines = $item['reverse_order_lines'] ?? $item['reverseOrderLineDTOList'] ?? [];
            $lineIds = array_values(array_filter(array_map(
                static fn (array $line): string => (string) ($line['trade_order_line_id'] ?? $line['tradeOrderLineId'] ?? $line['order_item_id'] ?? $line['reverse_order_line_id'] ?? $line['reverseOrderLineId'] ?? ''),
                is_array($lines) ? $lines : [],
            )));

            $refundAmount = 0.0;
            foreach ($lines as $line) {
                $refundAmount += (float) ($line['refund_amount'] ?? $line['refundAmount'] ?? 0);
            }

            return [
                'reverse_order_id' => (string) ($item['reverse_order_id'] ?? $item['reverseOrderId'] ?? $reverseOrderId ?? ''),
                'trade_order_id' => (string) ($item['trade_order_id'] ?? $orderId),
                'reverse_order_line_ids' => $lineIds,
                'refund_amount' => $refundAmount > 0 ? $refundAmount : (float) ($item['refund_amount'] ?? $item['total_refund'] ?? 0),
                'status' => $status,
                'raw' => $item,
            ];
        }

        if ($reverseOrderId) {
            $detail = $this->fetchReturnDetail($shopId, $reverseOrderId);
            $raw = $detail['raw'] ?? [];
            $lines = $raw['reverse_order_lines'] ?? $raw['reverseOrderLineDTOList'] ?? [];
            $requestType = strtoupper((string) ($raw['request_type'] ?? $raw['reverse_type'] ?? ''));

            if ($requestType === 'CANCEL' && is_array($lines)) {
                $lineIds = array_values(array_filter(array_map(
                    static fn (array $line): string => (string) ($line['trade_order_line_id'] ?? $line['tradeOrderLineId'] ?? $line['order_item_id'] ?? $line['reverse_order_line_id'] ?? ''),
                    $lines,
                )));

                if ($lineIds) {
                    return [
                        'reverse_order_id' => $reverseOrderId,
                        'trade_order_id' => $orderId,
                        'reverse_order_line_ids' => $lineIds,
                        'refund_amount' => (float) ($raw['refund_amount'] ?? $raw['total_refund'] ?? 0),
                        'status' => strtoupper((string) ($raw['reverse_status'] ?? '')),
                        'raw' => $raw,
                    ];
                }
            }
        }

        return null;
    }

    public function respondBuyerCancellation(
        string $shopId,
        string $orderId,
        string $decision,
        ?string $reverseOrderId = null,
        ?string $rejectComment = null,
    ): array {
        $reverse = $this->findBuyerCancellation($shopId, $orderId, $reverseOrderId);

        if (! $reverse || empty($reverse['reverse_order_id']) || empty($reverse['reverse_order_line_ids'])) {
            throw new \RuntimeException('Permintaan pembatalan buyer Lazada tidak ditemukan atau detail item belum tersedia.');
        }

        if (! in_array($decision, [
            RespondBuyerCancellationJob::ACCEPT,
            RespondBuyerCancellationJob::REJECT,
        ], true)) {
            throw new \InvalidArgumentException('Keputusan pembatalan buyer Lazada tidak valid.');
        }

        $accept = $decision === RespondBuyerCancellationJob::ACCEPT;
        if (! $accept && $rejectComment === null) {
            $rejectComment = 'Pesanan sudah diproses dan tidak dapat dibatalkan.';
        }

        $params = [
            'OrderId' => $orderId,
            'OrderItemIdList' => json_encode($reverse['reverse_order_line_ids'], JSON_THROW_ON_ERROR),
        ];

        $path = $accept
            ? (string) config('services.lazada.buyer_cancel_accept_path', '/v2/order/returnRefund/accept')
            : (string) config('services.lazada.buyer_cancel_reject_path', '/v2/order/returnRefund/reject');

        if ($accept) {
            if ((float) $reverse['refund_amount'] <= 0) {
                throw new \RuntimeException('Nominal pengembalian dana Lazada tidak tersedia untuk menerima pembatalan buyer.');
            }
            $params['refundAmount'] = (string) $reverse['refund_amount'];
        } else {
            $params['comment'] = $rejectComment;
            $params['reasonId'] = (string) config('services.lazada.buyer_cancel_reject_reason_id', '1022');
        }

        $shop = $this->requireShop($shopId);
        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', $path, $params, $token));

        try {
            $this->resyncLocalOrder($shopId, $orderId);
        } catch (\Throwable $e) {
            Log::warning('Lazada buyer cancellation berhasil, tetapi resync order lokal gagal', [
                'shop_id' => $shopId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'handled' => true,
            'order_id' => $orderId,
            'reverse_order_id' => $reverse['reverse_order_id'],
            'decision' => $decision,
            'response' => $res,
        ];
    }

    public function approveReturn(string $shopId, string $reverseOrderId): bool
    {
        try {
            $shop = $this->requireShop($shopId);

            $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'POST',
                '/order/reverse/return/approve',
                ['reverse_order_id' => $reverseOrderId],
                $token,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal setujui retur (reverse_order_id={$reverseOrderId}): ".$e->getMessage());

            return false;
        }
    }

    public function rejectReturn(string $shopId, string $reverseOrderId, string $reasonId, ?string $remark = null): bool
    {
        try {
            $shop = $this->requireShop($shopId);

            $params = ['reverse_order_id' => $reverseOrderId, 'reason_id' => $reasonId];
            if ($remark) {
                $params['remark'] = $remark;
            }

            $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'POST',
                '/order/reverse/return/reject',
                $params,
                $token,
            ));

            return true;
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal tolak retur (reverse_order_id={$reverseOrderId}): ".$e->getMessage());

            return false;
        }
    }

    public function getRejectReasons(string $shopId, string $reverseOrderId): array
    {
        try {
            $shop = $this->requireShop($shopId);

            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request(
                'GET',
                '/order/reverse/reason/list',
                ['reverse_order_id' => $reverseOrderId],
                $token,
            ));

            $reasons = $res['data']['reasons'] ?? $res['result']['reasons'] ?? [];

            return array_map(fn ($r) => [
                'id' => (string) ($r['reason_id'] ?? $r['id'] ?? ''),
                'text' => (string) ($r['reason_text'] ?? $r['text'] ?? ''),
            ], $reasons);
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal ambil alasan tolak retur (reverse_order_id={$reverseOrderId}): ".$e->getMessage());

            return [];
        }
    }

    protected function callWithRefresh(object $shop, callable $fn): array
    {
        try {
            return $fn($shop->access_token);
        } catch (TokenExpiredException $e) {
            Log::info("Lazada: token toko {$shop->shop_id} kedaluwarsa — refresh lalu retry.");

            $this->authService->refreshStoreToken((string) $shop->id);
            $fresh = $this->shopRepository->findByShopId($shop->shop_id);

            return $fn($fresh->access_token);
        }
    }

    protected function requireShop(string $shopId): object
    {
        $shop = $this->shopRepository->findByShopId($shopId);

        if (! $shop || ! $shop->access_token) {
            throw new \Exception("Toko Lazada tidak ditemukan atau belum terhubung: {$shopId}");
        }

        return $shop;
    }
}
