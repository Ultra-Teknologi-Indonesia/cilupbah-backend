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

    public function packOrder(string $shopId, string $orderId, ?string $shippingProvider = null): array
    {
        $shop = $this->requireShop($shopId);

        $itemIds = $this->orderItemIds($shop, $orderId);
        if (empty($itemIds)) {
            throw new \Exception("Order {$orderId} tidak punya item untuk diproses.");
        }

        $packParams = [
            'delivery_type' => 'dropship',
            'order_item_ids' => json_encode($itemIds),
        ];
        if ($shippingProvider) {
            $packParams['shipping_provider'] = $shippingProvider;
        }

        $packRes = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/pack', $packParams, $token));

        $trackingNumber = $packRes['data']['order_items'][0]['tracking_number'] ?? '';

        $rtsParams = [
            'delivery_type' => 'dropship',
            'order_item_ids' => json_encode($itemIds),
            'shipment_provider' => $shippingProvider ?? ($packRes['data']['order_items'][0]['shipment_provider'] ?? ''),
            'tracking_number' => $trackingNumber,
        ];

        $rtsRes = $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/rts', $rtsParams, $token));

        $this->resyncLocalOrder($shopId, $orderId);

        return [
            'order_id' => $orderId,
            'order_item_ids' => $itemIds,
            'tracking_number' => $trackingNumber,
            'rts' => $rtsRes['data'] ?? [],
        ];
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

            $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/cancel', $params, $token));
            $cancelled[] = $itemId;
        }

        $this->resyncLocalOrder($shopId, $orderId);

        return ['order_id' => $orderId, 'cancelled_item_ids' => $cancelled];
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
