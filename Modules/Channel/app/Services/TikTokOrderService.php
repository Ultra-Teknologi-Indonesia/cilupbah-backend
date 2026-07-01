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
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $accessToken = $shop->access_token;
        $shopCipher = $shop->shop_cipher ?? '';

        $count = 0;
        $nextPageToken = '';

        do {
            $queries = [
                'shop_cipher' => $shopCipher,
                'page_size'   => 100,
            ];

            $body = [
                'sort_by'   => 'CREATE_TIME',
                'sort_type' => 'DESC',
            ];

            if ($nextPageToken !== '') {
                $body['next_page_token'] = $nextPageToken;
            }

            $res = $this->client->request('POST', '/order/202309/orders/search', $queries, $body, $accessToken);

            if (! isset($res['data']['orders'])) {
                break;
            }

            foreach ($res['data']['orders'] as $item) {
                try {
                    $internalData = $this->mapper->map($item, $shopId);
                    $this->orderService->upsertFromChannel($internalData);
                    $count++;
                } catch (\Exception $e) {
                    Log::error("Failed to pull order {$item['id']}: " . $e->getMessage());
                }
            }

            $nextPageToken = $res['data']['next_page_token'] ?? '';
        } while ($nextPageToken !== '');

        return $count;
    }

    public function pullOrderById(string $shopId, string $orderId): ?int
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = [
            'shop_cipher' => $shop->shop_cipher ?? '',
            'ids'         => $orderId,
        ];

        $res = $this->client->request('GET', '/order/202309/orders', $queries, [], $shop->access_token);

        if (! isset($res['data']['orders']) || empty($res['data']['orders'])) {
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

    protected function financeStatementPath(string $orderId): string
    {
        $template = config(
            'services.tiktok.finance_statement_path',
            '/finance/202309/orders/{order_id}/statement_transactions'
        );

        return str_replace('{order_id}', $orderId, $template);
    }

    public function getOrderStatement(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        $res = $this->client->request('GET', $this->financeStatementPath($orderId), $queries, [], $shop->access_token);

        return $res['data'] ?? [];
    }

    public function acceptOrder(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
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

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    public function readyToShip(string $shopId, string $orderId, ?array $handover = null): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
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
                    $shipBody = ['order_id' => $orderId];

                    if ($handover) {
                        if (! empty($handover['tracking_number'])) {
                            $shipBody['tracking_number'] = $handover['tracking_number'];
                        }
                        if (! empty($handover['shipping_provider_id'])) {
                            $shipBody['shipping_provider_id'] = $handover['shipping_provider_id'];
                        }
                    }

                    $res = $this->client->request(
                        'POST',
                        "/fulfillment/202309/packages/{$packageId}/ship",
                        $queries,
                        $shipBody,
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

            $someOk = collect($results)->contains('shipped', true);

            if ($allOk || $someOk) {
                $this->resyncLocalOrder($shopId, $orderId);
            }

            $this->fetchAndStoreTracking($shop, $orderId, $queries);

            return [
                'order_id' => $orderId,
                'shipped'  => $allOk,
                'message'  => $allOk
                    ? 'RTS berhasil.'
                    : ($someOk ? 'Sebagian package berhasil, sebagian gagal (PARTIALLY_SHIPPING).' : 'Semua package gagal di-RTS.'),
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

    public function getShippingDocument(string $shopId, string $packageId, string $documentType = 'SHIPPING_LABEL'): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = [
            'shop_cipher'   => $shop->shop_cipher ?? '',
            'document_type' => $documentType,
            'document_size' => 'A6',
        ];

        return $this->client->request('GET', "/fulfillment/202309/packages/{$packageId}/shipping_documents", $queries, [], $shop->access_token);
    }

    public function getShippingLabel(string $shopId, string $packageId): array
    {
        return $this->getShippingDocument($shopId, $packageId, 'SHIPPING_LABEL');
    }

    public function getPackingList(string $shopId, string $packageId): array
    {
        return $this->getShippingDocument($shopId, $packageId, 'PACKING_LIST');
    }

    public function getPackageDetail(string $shopId, string $packageId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];

        return $this->client->request('GET', "/fulfillment/202309/packages/{$packageId}", $queries, [], $shop->access_token);
    }

    public function acceptBuyerCancellation(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $orderId,
        ];

        $res = $this->client->request('POST', '/return_refund/202309/cancellations/approve', $queries, $body, $shop->access_token);

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    public function rejectBuyerCancellation(string $shopId, string $orderId): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id' => $orderId,
        ];

        $res = $this->client->request('POST', '/return_refund/202309/cancellations/reject', $queries, $body, $shop->access_token);

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    public function declineOrder(string $shopId, string $orderId, string $reason): array
    {
        $shop = $this->shopRepository->findByShopId($shopId);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$shopId}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id'           => $orderId,
            'cancel_reason_key'  => $reason,
            'cancel_reason'      => $reason,
        ];

        $res = $this->client->request('POST', '/return_refund/202309/cancellations', $queries, $body, $shop->access_token);

        $this->resyncLocalOrder($shopId, $orderId);

        return $res;
    }

    public function cancelProduct(string $orderId, string $reason): array
    {
        $order = $this->orderRepository->findOrderBySalesOrderNo($orderId);
        if (! $order) {
            throw new \Exception('Pesanan tidak ditemukan di sistem lokal');
        }

        if (! in_array($order->channel_status, ['ON_HOLD', 'AWAITING_SHIPMENT'])) {
            throw new \Exception("Pembatalan ditolak. Status pesanan saat ini adalah {$order->channel_status}. Hanya berlaku untuk ON_HOLD dan AWAITING_SHIPMENT.");
        }

        $shop = $this->shopRepository->findByShopId($order->channel_shop_id);
        if (! $shop || ! $shop->access_token) {
            throw new \Exception("No access token found for shop: {$order->channel_shop_id}");
        }

        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $body = [
            'order_id'           => $orderId,
            'cancel_reason_key'  => $reason,
            'cancel_reason'      => $reason,
        ];

        $res = $this->client->request('POST', '/return_refund/202309/cancellations', $queries, $body, $shop->access_token);

        $this->resyncLocalOrder($order->channel_shop_id, $orderId);

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
        if (! $shop || ! $shop->access_token) {
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

    protected function resyncLocalOrder(string $shopId, string $orderId): void
    {
        try {
            $this->pullOrderById($shopId, $orderId);
        } catch (\Throwable $e) {
            Log::warning("TikTok: resync order {$orderId} gagal pasca aksi: " . $e->getMessage());
        }
    }

    protected function resolvePackageIds(object $shop, string $orderId, array $queries): array
    {
        $ids = $this->fetchPackageIds($shop, $orderId, $queries);

        if (! empty($ids)) {
            return $ids;
        }

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

    public function fetchAndStoreTracking(object $shop, string $orderId, array $queries): void
    {
        try {
            $detailQueries = array_merge($queries, ['ids' => $orderId]);
            $res = $this->client->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);

            $orders = $res['data']['orders'] ?? [];
            if (empty($orders)) {
                return;
            }

            $order = $orders[0];
            $packages = $order['packages'] ?? [];

            $trackingNumber = null;
            $shippingProvider = null;

            foreach ($packages as $pkg) {
                $tn = $pkg['tracking_number'] ?? null;
                if ($tn !== null && $tn !== '') {
                    $trackingNumber = (string) $tn;
                    $shippingProvider = $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null;
                    break;
                }
            }

            if (! $trackingNumber) {
                foreach ($packages as $pkg) {
                    $packageId = $pkg['id'] ?? null;
                    if (! $packageId) {
                        continue;
                    }
                    try {
                        $shop2 = $shop;
                        $docRes = $this->getShippingDocument((string) ($shop2->shop_id ?? ''), (string) $packageId, 'SHIPPING_LABEL');
                        $tn = $docRes['data']['tracking_number'] ?? null;
                        if ($tn !== null && $tn !== '') {
                            $trackingNumber = (string) $tn;
                            $shippingProvider = $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null;
                            break;
                        }
                    } catch (\Throwable $docErr) {
                        Log::debug('fetchAndStoreTracking: shipping document fallback gagal', [
                            'order_id'   => $orderId,
                            'package_id' => $packageId,
                            'error'      => $docErr->getMessage(),
                        ]);
                    }
                }
            }

            if ($trackingNumber) {
                $this->orderRepository->updateTrackingByOrderNo($orderId, $trackingNumber, $shippingProvider);
            }
        } catch (\Throwable $e) {
            Log::warning('TikTok: gagal fetch tracking post-RTS', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function resolveTrackingNumber(object $shop, string $orderId): ?array
    {
        $queries = ['shop_cipher' => $shop->shop_cipher ?? ''];
        $detailQueries = array_merge($queries, ['ids' => $orderId]);

        $res = $this->client->request('GET', '/order/202309/orders', $detailQueries, [], $shop->access_token);

        $orders = $res['data']['orders'] ?? [];
        if (empty($orders)) {
            return null;
        }

        $packages = $orders[0]['packages'] ?? [];

        foreach ($packages as $pkg) {
            $tn = $pkg['tracking_number'] ?? null;
            if ($tn !== null && $tn !== '') {
                return [
                    'tracking_number'   => (string) $tn,
                    'shipping_provider' => $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null,
                ];
            }
        }

        foreach ($packages as $pkg) {
            $packageId = $pkg['id'] ?? null;
            if (! $packageId) {
                continue;
            }
            try {
                $docRes = $this->getShippingDocument((string) ($shop->shop_id ?? ''), (string) $packageId, 'SHIPPING_LABEL');
                $tn = $docRes['data']['tracking_number'] ?? null;
                if ($tn !== null && $tn !== '') {
                    return [
                        'tracking_number'   => (string) $tn,
                        'shipping_provider' => $pkg['shipping_provider_name'] ?? $pkg['shipping_provider'] ?? null,
                    ];
                }
            } catch (\Throwable $e) {
                Log::debug('resolveTrackingNumber: shipping document fallback gagal', [
                    'order_id'   => $orderId,
                    'package_id' => $packageId,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
