<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
use Modules\Sales\Exceptions\ChannelOrderBeforeIntakeCutoffException;
use Modules\Sales\Services\SalesOrderService;

class WooCommerceOrderService
{
    public function __construct(
        protected WooCommerceClient $client,
        protected WooCommerceToInternalOrderMapper $mapper,
        protected SalesOrderService $orderService,
    ) {}

    public function pullOrders(string $shopId, ?int $updatedAfter = null): int
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return 0;
        }

        $shop = $this->requireShop($shopId);

        $after = Carbon::createFromTimestamp($updatedAfter ?: now()->subDays(7)->timestamp);

        $orders = $this->client->paginate($shop, 'orders', [
            'after' => $after->toIso8601String(),
            'orderby' => 'modified',
            'order' => 'asc',
        ]);

        $count = 0;
        foreach ($orders as $order) {
            $orderId = (string) ($order['id'] ?? '');

            try {
                $internal = $this->mapper->map($order, $shopId);
                $localOrderId = $this->orderService->upsertFromChannel($internal);
                if (! $localOrderId) {
                    Log::warning("WooCommerce: order {$orderId} tidak tersimpan secara lokal setelah pull.", [
                        'shop_id' => $shopId,
                    ]);

                    continue;
                }

                $count++;
            } catch (ChannelOrderBeforeIntakeCutoffException) {
                continue;
            } catch (\Throwable $e) {
                Log::error("WooCommerce: gagal upsert order {$orderId}: ".$e->getMessage());
            }
        }

        return $count;
    }

    public function pullOrdersPage(string $shopId, ?int $updatedAfter, array $cursor = []): OrderPullPageResult
    {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return new OrderPullPageResult(0, true);
        }

        $shop = $this->requireShop($shopId);
        $after = Carbon::createFromTimestamp($updatedAfter ?: now()->subDays(7)->timestamp);
        $page = max(1, (int) ($cursor['page'] ?? 1));
        $response = $this->client->page($shop, 'orders', [
            'after' => $after->toIso8601String(),
            'orderby' => 'modified',
            'order' => 'asc',
        ], $page);

        $count = 0;
        foreach ($response['items'] as $order) {
            $orderId = (string) ($order['id'] ?? '');
            try {
                if ($orderId !== '' && $this->orderService->upsertFromChannel($this->mapper->map($order, $shopId))) {
                    $count++;
                }
            } catch (ChannelOrderBeforeIntakeCutoffException) {
                continue;
            } catch (\Throwable $e) {
                Log::error("WooCommerce: gagal upsert order {$orderId}: ".$e->getMessage());
                throw $e;
            }
        }

        $done = count($response['items']) === 0
            || ($response['total_pages'] > 0 && $page >= $response['total_pages'])
            || count($response['items']) < 100;

        return $done
            ? new OrderPullPageResult($count, true)
            : new OrderPullPageResult($count, false, ['page' => $page + 1]);
    }

    public function listRecentOrderIds(string $shopId, ?int $updatedAfter = null): array
    {
        $shop = $this->requireShop($shopId);
        $after = Carbon::createFromTimestamp($updatedAfter ?: now()->subDays(2)->timestamp);

        $orders = $this->client->paginate($shop, 'orders', [
            'after' => $after->toIso8601String(),
            'orderby' => 'modified',
            'order' => 'asc',
        ]);

        return array_values(array_filter(array_map(
            static fn (array $order): string => (string) ($order['id'] ?? ''),
            $orders,
        )));
    }

    public function pullOrderById(string $shopId, string $orderId): int
    {
        $shop = $this->requireShop($shopId);

        try {
            $order = $this->client->get($shop, "orders/{$orderId}");
        } catch (\Throwable $e) {
            Log::warning("WooCommerce: gagal ambil order {$orderId}: ".$e->getMessage());

            return 0;
        }

        if (empty($order['id'])) {
            return 0;
        }

        $internal = $this->mapper->map($order, $shopId);
        try {
            $localOrderId = $this->orderService->upsertFromChannel($internal);
        } catch (ChannelOrderBeforeIntakeCutoffException) {
            return 0;
        }
        if (! $localOrderId) {
            Log::warning("WooCommerce: order {$orderId} tidak tersimpan secara lokal setelah pull.", [
                'shop_id' => $shopId,
            ]);

            return 0;
        }

        return 1;
    }

    public function shipOrder(string $shopId, string $orderId, ?string $trackingNumber = null, ?string $shippingProvider = null): array
    {
        $shop = $this->requireShop($shopId);

        $payload = ['status' => 'completed'];

        $meta = [];
        if ($trackingNumber) {
            $meta[] = ['key' => '_tracking_number', 'value' => $trackingNumber];
        }
        if ($shippingProvider) {
            $meta[] = ['key' => '_tracking_provider', 'value' => $shippingProvider];
        }
        if (! empty($meta)) {
            $payload['meta_data'] = $meta;
        }

        $this->client->put($shop, "orders/{$orderId}", $payload);

        if ($trackingNumber) {
            try {
                $this->client->post($shop, "orders/{$orderId}/notes", [
                    'note' => 'Resi: '.$trackingNumber.($shippingProvider ? ' ('.$shippingProvider.')' : ''),
                    'customer_note' => true,
                ]);
            } catch (\Throwable $e) {
                Log::warning('WooCommerce: gagal tambah order note resi: '.$e->getMessage());
            }
        }

        $this->pullOrderById($shopId, $orderId);

        return ['success' => true, 'message' => 'Pesanan WooCommerce ditandai selesai'];
    }

    public function cancelOrder(string $shopId, string $orderId, ?string $reason = null): array
    {
        $shop = $this->requireShop($shopId);

        $this->client->put($shop, "orders/{$orderId}", ['status' => 'cancelled']);

        if ($reason) {
            try {
                $this->client->post($shop, "orders/{$orderId}/notes", [
                    'note' => 'Dibatalkan: '.$reason,
                    'customer_note' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('WooCommerce: gagal tambah order note batal: '.$e->getMessage());
            }
        }

        $this->pullOrderById($shopId, $orderId);

        return ['success' => true, 'message' => 'Pesanan WooCommerce dibatalkan'];
    }

    protected function requireShop(string $shopId): ChannelShop
    {
        $shop = ChannelShop::where('shop_id', $shopId)->whereNull('disconnected_at')->first();

        if (! $shop) {
            throw new \RuntimeException('Toko tidak ditemukan', 422);
        }

        return $shop;
    }
}
