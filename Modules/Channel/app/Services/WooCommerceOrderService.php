<?php

namespace Modules\Channel\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelShop;
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
        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
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
                $this->orderService->upsertFromChannel($internal);
                $count++;
            } catch (\Throwable $e) {
                Log::error("WooCommerce: gagal upsert order {$orderId}: " . $e->getMessage());
            }
        }

        return $count;
    }

    public function pullOrderById(string $shopId, string $orderId): int
    {
        $shop = $this->requireShop($shopId);

        try {
            $order = $this->client->get($shop, "orders/{$orderId}");
        } catch (\Throwable $e) {
            Log::warning("WooCommerce: gagal ambil order {$orderId}: " . $e->getMessage());

            return 0;
        }

        if (empty($order['id'])) {
            return 0;
        }

        $internal = $this->mapper->map($order, $shopId);
        $this->orderService->upsertFromChannel($internal);

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
                    'note' => 'Resi: ' . $trackingNumber . ($shippingProvider ? ' (' . $shippingProvider . ')' : ''),
                    'customer_note' => true,
                ]);
            } catch (\Throwable $e) {
                Log::warning('WooCommerce: gagal tambah order note resi: ' . $e->getMessage());
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
                    'note' => 'Dibatalkan: ' . $reason,
                    'customer_note' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('WooCommerce: gagal tambah order note batal: ' . $e->getMessage());
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
