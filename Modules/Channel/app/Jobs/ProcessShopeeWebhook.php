<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Product\Models\ProductChannelMapping;

class ProcessShopeeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    private const PUSH_SHOP_AUTHORIZED = 1;
    private const PUSH_SHOP_DEAUTHORIZED = 2;
    private const PUSH_ORDER_STATUS = 3;
    private const PUSH_TRACKING_NO = 4;
    private const PUSH_ITEM_UPDATE = 5;
    private const PUSH_SHIPPING_DOC = 15;
    private const PUSH_BOOKING_STATUS = 23;
    private const PUSH_BOOKING_TRACKING_NO = 24;
    private const PUSH_BOOKING_SHIPPING_DOC = 25;
    private const PUSH_PACKAGE_FULFILLMENT = 30;
    private const PUSH_COURIER_DELIVERY_BINDING = 37;

    public function __construct(
        public array $payload,
    ) {
        $this->onQueue('default');
    }

    public function handle(ShopeeOrderService $orderService): void
    {
        $shopId = (string) ($this->payload['shop_id'] ?? '');
        $code = (int) ($this->payload['code'] ?? -1);
        $data = $this->payload['data'] ?? [];

        if ($shopId === '') {
            Log::warning('Shopee webhook tanpa shop_id — diabaikan.', ['payload' => $this->payload]);

            return;
        }

        match ($code) {
            self::PUSH_SHOP_DEAUTHORIZED => $this->handleDeauthorized($shopId),
            self::PUSH_ORDER_STATUS,
            self::PUSH_TRACKING_NO,
            self::PUSH_SHIPPING_DOC,
            self::PUSH_BOOKING_STATUS,
            self::PUSH_BOOKING_TRACKING_NO,
            self::PUSH_BOOKING_SHIPPING_DOC,
            self::PUSH_PACKAGE_FULFILLMENT,
            self::PUSH_COURIER_DELIVERY_BINDING => $this->handleOrderEvent($orderService, $shopId, $data),
            self::PUSH_ITEM_UPDATE => $this->logItemEvent($shopId, $data),
            default => Log::info("Shopee webhook code {$code} belum ditangani — diabaikan.", ['shop_id' => $shopId]),
        };
    }

    protected function handleDeauthorized(string $shopId): void
    {
        $shopUuid = DB::table('channel_shops')
            ->where('shop_id', $shopId)
            ->whereNull('disconnected_at')
            ->value('id');

        if (! $shopUuid) {
            return;
        }

        DB::table('channel_shops')->where('id', $shopUuid)->update([
            'is_active' => false,
            'integration_status' => 'error',
            'last_error' => 'Shopee deauthorized via webhook',
            'updated_at' => now(),
        ]);

        Log::info('Shopee toko di-deauthorize via webhook.', ['shop_id' => $shopId]);
    }

    protected function handleOrderEvent(ShopeeOrderService $orderService, string $shopId, array $data): void
    {
        $orderSn = (string) ($data['ordersn'] ?? $data['order_sn'] ?? '');

        if ($orderSn === '') {
            Log::warning('Shopee webhook order tanpa ordersn — diabaikan.', ['data' => $data]);

            return;
        }

        $orderService->pullOrderById($shopId, $orderSn);
    }

    protected function logItemEvent(string $shopId, array $data): void
    {
        $itemId = (string) ($data['item_id'] ?? '');
        if ($itemId === '') {
            return;
        }

        $shopUuid = DB::table('channel_shops')->where('shop_id', $shopId)->value('id');
        if (! $shopUuid) {
            return;
        }

        $mapping = ProductChannelMapping::where('channel_shop_id', $shopUuid)
            ->where('external_product_id', $itemId)
            ->first();

        if (! $mapping) {
            return;
        }

        $status = strtolower((string) ($data['status'] ?? ''));

        if (in_array($status, ['normal', 'active'], true)) {
            $mapping->markApproved();
        } elseif ($status === 'banned') {
            $mapping->markRejected('Shopee item banned: ' . ($data['ban_reason'] ?? $status));
        } elseif (in_array($status, ['deleted', 'unlist'], true)) {
            $mapping->update([
                'sync_status' => ProductChannelMapping::STATUS_DEACTIVATED,
            ]);
        } else {
            Log::info('Shopee item event diterima.', ['shop_id' => $shopId, 'item_id' => $itemId, 'status' => $status]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessShopeeWebhook gagal permanen: ' . $e->getMessage(), ['payload' => $this->payload]);
    }
}
