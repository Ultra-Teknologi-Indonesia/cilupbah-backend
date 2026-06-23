<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Facades\Log;
use Modules\Sales\Services\SalesOrderService as OrderService;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Repositories\ChannelOrderRepository;

class TikTokOrderService
{
    protected TikTokClient $client;
    protected TikTokToInternalOrderMapper $mapper;
    protected OrderService $orderService;
    protected ChannelShopRepository $shopRepository;
    protected ChannelOrderRepository $orderRepository;

    public function __construct(
        TikTokClient $client, 
        TikTokToInternalOrderMapper $mapper, 
        OrderService $orderService,
        ChannelShopRepository $shopRepository,
        ChannelOrderRepository $orderRepository
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->orderService = $orderService;
        $this->shopRepository = $shopRepository;
        $this->orderRepository = $orderRepository;
    }

    public function pullOrders(string $shopId): int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $queries = [
            'shop_cipher' => $shopCipher,
            'page_size' => 100,
        ];

        $body = [
            'sort_by' => 'CREATE_TIME',
            'sort_type' => 'DESC'
        ];

        $res = $this->client->request('POST', '/order/202309/orders/search', $queries, $body, $accessToken);

        if (!isset($res['data']['orders'])) {
            return 0; 
        }

        $count = 0;
        foreach ($res['data']['orders'] as $item) {
            try {
                $internalData = $this->mapper->map($item, $shopId);
                $this->orderService->upsertFromChannel($internalData);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to pull order {$item['id']}: " . $e->getMessage());
            }
        }

        return $count;
    }

    public function pullOrderById(string $shopId, string $orderId): ?int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $queries = [
            'shop_cipher' => $shopCipher,
            'ids' => $orderId,
        ];

        $res = $this->client->request('GET', '/order/202309/orders', $queries, [], $accessToken);

        if (!isset($res['data']['orders']) || empty($res['data']['orders'])) {
            return 0; 
        }

        $count = 0;
        foreach ($res['data']['orders'] as $item) {
            try {
                $internalData = $this->mapper->map($item, $shopId);
                $this->orderService->upsertFromChannel($internalData);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to pull specific order {$item['id']}: " . $e->getMessage());
            }
        }

        return $count;
    }

    public function acceptOrder(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = ['order_id' => $orderId];

        try {
            $res = $this->client->request('POST', '/fulfillment/202309/packages', $queries, $body, $shop->access_token);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'invalid params') !== false) {
                $res = ['bypassed' => true];
            } else {
                throw $e;
            }
        }

        $this->orderRepository->updateOrderStatusByOrderNo($orderId, 'PROCESSING');

        return $res;
    }

    /**
     * Mark a TikTok order's package(s) as Ready To Ship (RTS).
     *
     * TikTok Shop requires shipping at the *package* level. A package must exist
     * before it can be shipped (created via the same endpoint acceptOrder() uses:
     * POST /fulfillment/202309/packages). The local SalesOrder does not persist
     * package_id, so we resolve packages live from the order detail; if none
     * exists yet we attempt to create one, then re-fetch.
     *
     * Returns a structured result; on a recoverable problem (e.g. no package_id
     * resolvable) it returns ['shipped' => false, ...] rather than throwing.
     */
    public function readyToShip(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        try {
            $packageIds = $this->resolvePackageIds($shop, $orderId, $queries);

            if (empty($packageIds)) {
                Log::warning('TikTok RTS: no package_id resolvable for order', [
                    'shop_id'  => $shopId,
                    'order_id' => $orderId,
                ]);

                return [
                    'order_id' => $orderId,
                    'shipped'  => false,
                    'message'  => 'Tidak ada package_id yang dapat di-resolve untuk order ini. Pastikan order sudah AWAITING_SHIPMENT dan package sudah dibuat.',
                    'packages' => [],
                ];
            }

            $results = [];
            $allOk = true;

            foreach ($packageIds as $packageId) {
                try {
                    $res = $this->client->request(
                        'POST',
                        "/fulfillment/202309/packages/{$packageId}/ship",
                        $queries,
                        ['order_id' => $orderId],
                        $shop->access_token
                    );

                    $results[] = ['package_id' => $packageId, 'shipped' => true, 'response' => $res['data'] ?? []];
                } catch (\Throwable $e) {
                    $allOk = false;
                    Log::error('TikTok RTS: gagal ship package', [
                        'shop_id'    => $shopId,
                        'order_id'   => $orderId,
                        'package_id' => $packageId,
                        'error'      => $e->getMessage(),
                    ]);
                    $results[] = ['package_id' => $packageId, 'shipped' => false, 'message' => $e->getMessage()];
                }
            }

            if ($allOk) {
                $this->orderRepository->updateOrderStatusByOrderNo($orderId, 'AWAITING_COLLECTION');
            }

            return [
                'order_id' => $orderId,
                'shipped'  => $allOk,
                'message'  => $allOk ? 'RTS berhasil.' : 'Sebagian package gagal di-RTS.',
                'packages' => $results,
            ];
        } catch (\Throwable $e) {
            Log::error('TikTok RTS: gagal', [
                'shop_id'  => $shopId,
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Resolve the package_id(s) for an order. Fetches order detail; if no package
     * exists, attempts to create one (mirrors acceptOrder) then re-fetches.
     *
     * @return array<int,string>
     */
    protected function resolvePackageIds(object $shop, string $orderId, array $queries): array
    {
        $ids = $this->fetchPackageIds($shop, $orderId, $queries);

        if (!empty($ids)) {
            return $ids;
        }

        // No package yet — try to create one, then re-fetch. acceptOrder() bypasses
        // "invalid params" (package already exists), so do the same here.
        try {
            $this->client->request('POST', '/fulfillment/202309/packages', $queries, ['order_id' => $orderId], $shop->access_token);
        } catch (\Throwable $e) {
            if (strpos($e->getMessage(), 'invalid params') === false) {
                Log::warning('TikTok RTS: gagal membuat package sebelum RTS', [
                    'order_id' => $orderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $this->fetchPackageIds($shop, $orderId, $queries);
    }

    /**
     * @return array<int,string>
     */
    protected function fetchPackageIds(object $shop, string $orderId, array $queries): array
    {
        $detailQueries = array_merge($queries, ['ids' => $orderId]);

        $res = $this->client->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);

        $orders = $res['data']['orders'] ?? [];
        $ids = [];

        foreach ($orders as $order) {
            foreach ($order['packages'] ?? [] as $package) {
                $pid = $package['id'] ?? null;
                if ($pid !== null && $pid !== '') {
                    $ids[] = (string) $pid;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function declineOrder(string $shopId, string $orderId, string $reason): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $orderId,
            'cancel_reason_key' => $reason,
            'cancel_reason' => $reason
        ];

        $res = $this->client->request('POST', "/return_refund/202309/cancellations", $queries, $body, $shop->access_token);

        $this->orderRepository->updateOrderStatusByOrderNo($orderId, 'CANCELLED');

        return $res;
    }

    public function getCancelReasons(): array
    {

        return collect(app(MarketplaceCancelReasonService::class)->for(MarketplaceCancelReasonService::TIKTOK))
            ->pluck('label', 'key')
            ->all();
    }

    public function getCancelReasonsLive(string $shopId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        $res = $this->client->request('GET', '/return_refund/202309/reject_reasons', $queries, [], $shop->access_token);

        $reasons = $res['data']['reasons'] ?? [];

        return array_values(array_filter(array_map(static function ($r) {
            $key = $r['name'] ?? $r['key'] ?? null;
            if ($key === null) {
                return null;
            }

            return [
                'key'   => (string) $key,
                'label' => (string) ($r['text'] ?? $r['label'] ?? $key),
            ];
        }, $reasons)));
    }

    public function cancelProduct(string $orderId, string $reason): array
    {
        $order = $this->orderRepository->findOrderBySalesOrderNo($orderId);
        if (!$order) {
            throw new \Exception('Pesanan tidak ditemukan di sistem lokal');
        }

        if (!in_array($order->channel_status, ['ON_HOLD', 'AWAITING_SHIPMENT'])) {
            throw new \Exception("Pembatalan ditolak. Status pesanan saat ini adalah {$order->channel_status}. Hanya berlaku untuk ON_HOLD dan AWAITING_SHIPMENT.");
        }

        $shop = $this->shopRepository->findByShopId($order->channel_shop_id);
        if (!$shop || !$shop->access_token) {
            throw new \Exception("No access token found for shop: {$order->channel_shop_id}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $orderId,
            'cancel_reason_key' => $reason,
            'cancel_reason' => $reason
        ];

        $res = $this->client->request('POST', "/return_refund/202309/cancellations", $queries, $body, $shop->access_token);

        return $res;
    }
}
