<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Channel\Exceptions\TokenExpiredException;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Sales\Services\SalesOrderService;

/**
 * Tarik order Lazada → SalesOrder internal (via SalesOrderService::upsertFromChannel —
 * idempoten by salesorder_no + lockForUpdate + transisi stok resmi, pola TikTokOrderService).
 * Token kedaluwarsa di tengah panggilan → refresh sekali lalu ulang.
 */
class LazadaOrderService
{
    public function __construct(
        protected LazadaClient $client,
        protected LazadaToInternalOrderMapper $mapper,
        protected SalesOrderService $orderService,
        protected ChannelShopRepository $shopRepository,
        protected LazadaAuthService $authService,
    ) {}

    /**
     * Tarik order terbaru satu toko (default: yang berubah 7 hari terakhir, max 100).
     * Return jumlah order yang berhasil di-upsert.
     */
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

    /**
     * Tarik satu order spesifik (dipakai handler webhook order-status-changed).
     */
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

    /**
     * Ambil baris item untuk banyak order sekaligus (batch /orders/items/get, chunk 50).
     *
     * @return array<string, array> keyed by order_id
     */
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

    /**
     * Jalankan panggilan API; bila token kedaluwarsa → refresh token toko lalu ulang SEKALI.
     */
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
