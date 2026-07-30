<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
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

    public function pullOrders(string $shopId, ?string $updatedAfter = null): int
    {
        $shop = $this->requireShop($shopId);

        $params = [
            'sort_by' => 'updated_at',
            'sort_direction' => 'DESC',
            'offset' => 0,
            'limit' => 100,
            'update_after' => $updatedAfter ?: now()->subDays(7)->toIso8601String(),
        ];

        $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/orders/get', $params, $token));

        $orders = $res['data']['orders'] ?? [];
        if (empty($orders)) {
            return 0;
        }

        $itemsByOrder = $this->fetchItemsForOrders($shop, array_column($orders, 'order_id'));

        $count = 0;
        foreach ($orders as $order) {
            $orderId = (string) ($order['order_id'] ?? '');

            try {
                $internal = $this->mapper->map($order, $itemsByOrder[$orderId] ?? [], $shopId);
                $this->orderService->upsertFromChannel($internal);
                $count++;
            } catch (\Throwable $e) {
                Log::error("Lazada: gagal upsert order {$orderId}: " . $e->getMessage());
            }
        }

        return $count;
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

        $this->resyncLocalOrder($shopId, $orderId);

        return [
            'order_id' => $orderId,
            'order_item_ids' => $packableIds,
            'pack' => $res['data'] ?? [],
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

        $this->resyncLocalOrder($shopId, $orderId);

        return [
            'order_id' => $orderId,
            'order_item_ids' => $readyIds,
            'rts' => $res['data'] ?? [],
        ];
    }

    public function getOrderTrace(string $shopId, string $orderId): array
    {
        if ($orderId === '') return [];

        try {
            $shop = $this->requireShop($shopId);
            $res = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('GET', '/logistic/order/trace', [
                'order_id' => $orderId,
            ], $token));

            return $res['data'] ?? $res['result'] ?? [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("Lazada: gagal ambil order trace {$orderId}: " . $e->getMessage());

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
                // Status sudah maju (RTS/shipped) / aksi reverse ditolak = final.
                $final = (bool) preg_match('/status|not[_ ]?allowed|reverse|invalid[_ ]?order/i', $code . ' ' . $message);

                throw new \Modules\Channel\Exceptions\ChannelCancelException(
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
            Log::warning("Lazada: resync order {$orderId} gagal pasca aksi: " . $e->getMessage());
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
                'carrier'         => $carrier ? (string) $carrier : null,
                'shipped_at'      => $shippedAt ? (string) $shippedAt : null,
            ];
        } catch (\Throwable $e) {
            Log::warning("Lazada: gagal ambil resi retur (reverse_order_id={$reverseOrderId}): " . $e->getMessage());

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
            Log::warning("Lazada: gagal ambil detail retur (reverse_order_id={$reverseOrderId}): " . $e->getMessage());

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
            Log::warning("Lazada: gagal ambil riwayat banding retur (reverse_order_id={$reverseOrderId}): " . $e->getMessage());

            return ['records' => []];
        }
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
            Log::warning("Lazada: gagal setujui retur (reverse_order_id={$reverseOrderId}): " . $e->getMessage());

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
            Log::warning("Lazada: gagal tolak retur (reverse_order_id={$reverseOrderId}): " . $e->getMessage());

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
            Log::warning("Lazada: gagal ambil alasan tolak retur (reverse_order_id={$reverseOrderId}): " . $e->getMessage());

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
