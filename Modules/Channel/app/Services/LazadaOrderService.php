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

    /**
     * Pack pesanan via endpoint package-based Lazada.
     * Hanya item berstatus "pending"/"repacked" yang boleh diproses — wajib panggil
     * GetOrderItems terlebih dahulu untuk memfilter (aturan Lazada, bukan pilihan kita).
     */
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

    /**
     * Ready-to-ship via endpoint package-based Lazada.
     * Hanya item berstatus "packed" yang boleh diproses — panggil GetOrderItems dulu.
     * Sebaiknya cetak AWB (printAwb()) di antara fulfillPack() dan readyToShip() —
     * sebagian penyedia logistik menolak RTS bila AWB belum digenerate.
     */
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

    /**
     * Cetak AWB dengan polling — beberapa penyedia logistik butuh waktu untuk
     * menyiapkan dokumen setelah fulfillPack(), sebelum bisa diambil.
     */
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

            $this->callWithRefresh($shop, fn (string $token) => $this->client->request('POST', '/order/cancel', $params, $token));
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

    /**
     * Status item saat ini (lowercase) — dipakai job retry/fallback untuk memutuskan
     * apakah fulfillPack()/readyToShip() masih perlu dipanggil (idempotensi).
     */
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

    /**
     * Selalu tarik ulang (bukan cache) — GetOrderItems wajib dipanggil segar sebelum
     * fulfillPack/readyToShip agar status-gating akurat & retry idempoten.
     */
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
