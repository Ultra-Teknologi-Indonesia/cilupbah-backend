<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\ChannelWebhookAuditService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Product\Models\ProductChannelMapping;

class ProcessShopeeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];
    public int $timeout = 120;

    private const PUSH_SHOP_AUTHORIZED = 1;
    private const PUSH_SHOP_DEAUTHORIZED = 2;
    private const PUSH_ORDER_STATUS = 3;
    private const PUSH_TRACKING_NO = 4;
    private const PUSH_SHOPEE_UPDATES = 5;
    private const PUSH_RESERVED_STOCK_CHANGE = 8;
    private const PUSH_SHIPPING_DOC = 15;
    private const PUSH_ITEM_PRICE_UPDATE = 22;
    private const PUSH_BOOKING_STATUS = 23;
    private const PUSH_BOOKING_TRACKING_NO = 24;
    private const PUSH_BOOKING_SHIPPING_DOC = 25;
    private const PUSH_RETURN_UPDATE = 29;
    private const PUSH_PACKAGE_FULFILLMENT = 30;
    private const PUSH_COURIER_DELIVERY_BINDING = 37;

    public function __construct(
        public array $payload,
    ) {
        $this->onQueue(self::resolveQueueName($payload));
    }

    public static function resolveQueueName(array $payload): string
    {
        $code = (int) ($payload['code'] ?? -1);

        return match ($code) {
            self::PUSH_ORDER_STATUS => config('queue.names.shopee_orders', 'shopee-orders'),
            self::PUSH_TRACKING_NO,
            self::PUSH_SHIPPING_DOC,
            self::PUSH_BOOKING_STATUS,
            self::PUSH_BOOKING_TRACKING_NO,
            self::PUSH_BOOKING_SHIPPING_DOC,
            self::PUSH_PACKAGE_FULFILLMENT,
            self::PUSH_COURIER_DELIVERY_BINDING => config('queue.names.shopee_tracking', 'shopee-tracking'),
            self::PUSH_RETURN_UPDATE => config('queue.names.shopee_aftersales', 'shopee-aftersales'),
            self::PUSH_RESERVED_STOCK_CHANGE,
            self::PUSH_ITEM_PRICE_UPDATE,
            self::PUSH_SHOPEE_UPDATES => config('queue.names.shopee_catalog', 'shopee-catalog'),
            default => config('queue.names.shopee_webhooks', 'shopee-webhooks'),
        };
    }

    public function middleware(): array
    {
        return [new RateLimited('webhook_download')];
    }

    public static function idempotencyKey(array $payload): string
    {
        $data = $payload['data'] ?? [];

        return 'shopee:webhook:' . md5(json_encode([
            $payload['shop_id'] ?? '',
            $payload['code'] ?? '',
            $payload['timestamp'] ?? '',
            $data['ordersn'] ?? $data['order_sn'] ?? $data['item_id'] ?? '',
            $data['status'] ?? '',
        ]));
    }

    protected bool $orderIntakeSkipped = false;

    public function handle(
        ShopeeOrderService $orderService,
        ChannelDownloadService $downloadService,
        ?ChannelWebhookAuditService $webhookAudit = null,
    ): void
    {
        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
            return;
        }

        $shopId = (string) ($this->payload['shop_id'] ?? '');
        $code = (int) ($this->payload['code'] ?? -1);
        $data = $this->payload['data'] ?? [];

        if ($shopId === '') {
            Log::warning('Shopee webhook tanpa shop_id — diabaikan.', ['payload' => $this->payload]);

            return;
        }

        $idempotencyKey = self::idempotencyKey($this->payload);
        if (! Cache::add($idempotencyKey, true, now()->addHours(24))) {
            Log::info("Shopee webhook already processed (key: {$idempotencyKey})");

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
            self::PUSH_COURIER_DELIVERY_BINDING => $this->handleOrderEventOrSkip($orderService, $shopId, $data),
            self::PUSH_RETURN_UPDATE => $this->handleReturnEvent($orderService, $shopId, $data),
            self::PUSH_RESERVED_STOCK_CHANGE,
            self::PUSH_ITEM_PRICE_UPDATE => $this->logItemEvent($downloadService, $shopId, $data),
            default => Log::info("Shopee webhook code {$code} belum ditangani — diabaikan.", ['shop_id' => $shopId]),
        };

        if (! $this->orderIntakeSkipped && in_array($code, [
            self::PUSH_TRACKING_NO,
            self::PUSH_PACKAGE_FULFILLMENT,
            self::PUSH_COURIER_DELIVERY_BINDING,
            self::PUSH_BOOKING_TRACKING_NO,
        ], true)) {
            $this->recordShopeeTrackingEvent($shopId, $code, $data);
        }

        $eventKey = self::idempotencyKey($this->payload);

        if (! $this->orderIntakeSkipped && $webhookAudit) {
            $webhookAudit->recordFromInbox('shopee', $eventKey, $this->payload);
        }

        if ($this->orderIntakeSkipped) {
            ChannelWebhookInbox::markSkippedByKey($eventKey, \Modules\Channel\Support\ChannelOrderIntakeGate::reason());

            return;
        }

        ChannelWebhookInbox::markProcessedByKey($eventKey);
    }

    protected function handleOrderEventOrSkip(ShopeeOrderService $orderService, string $shopId, array $data): void
    {
        if (\Modules\Channel\Support\ChannelOrderIntakeGate::blocksShop($shopId, 'shopee')) {
            $this->orderIntakeSkipped = true;

            return;
        }

        $this->handleOrderEvent($orderService, $shopId, $data);
    }

    protected function recordShopeeTrackingEvent(string $shopId, int $code, array $data): void
    {
        $orderSn = (string) ($data['ordersn'] ?? $data['order_sn'] ?? '');
        if ($orderSn === '') return;

        $order = \Modules\Sales\Models\SalesOrder::query()
            ->where('source', 'shopee')
            ->where('channel_order_no', $orderSn)
            ->first();
        if (! $order) return;

        $shipment = \Modules\Outbound\Models\Shipment::query()
            ->whereHas('orders', fn ($q) => $q->where('order_id', $order->id))
            ->latest('id')
            ->first();
        if (! $shipment) return;

        $eventType = match ($code) {
            self::PUSH_TRACKING_NO,
            self::PUSH_BOOKING_TRACKING_NO => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_DRIVER_ASSIGNED,
            self::PUSH_PACKAGE_FULFILLMENT => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_PICKED_UP,
            self::PUSH_COURIER_DELIVERY_BINDING => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_DRIVER_ARRIVED,
            default => 'shopee_event_' . $code,
        };

        $exists = \Modules\Outbound\Models\ShipmentTrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'shopee')
            ->where('event_type', $eventType)
            ->exists();
        if ($exists) return;

        \Modules\Outbound\Models\ShipmentTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'source' => 'shopee',
            'event_type' => $eventType,
            'driver_name' => null,
            'driver_phone' => null,
            'driver_vehicle_plate' => null,
            'raw_payload' => $data,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        $trackingNo = $data['tracking_no'] ?? null;
        if ($trackingNo) {
            $shipment->orders()
                ->where('order_id', $order->id)
                ->update(['tracking_number' => $trackingNo]);
        }

        \Modules\Outbound\Jobs\RefreshInstantTrackingJob::dispatch($shipment->id);
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

        $recentKey = "shopee_pulled_recent:{$shopId}:{$orderSn}";
        if (Cache::has($recentKey)) {
            Log::info("Shopee webhook {$orderSn} di-debounce (sudah di-pull dalam 15 detik terakhir).");
            $this->recordDeliveredEventIfApplicable($orderSn, $data);
            return;
        }

        Cache::put($recentKey, true, 15);
        $orderService->pullOrderById($shopId, $orderSn);

        $this->recordDeliveredEventIfApplicable($orderSn, $data);
    }

    protected function recordDeliveredEventIfApplicable(string $orderSn, array $data): void
    {
        $status = strtolower((string) ($data['status'] ?? ''));
        if (! in_array($status, ['completed', 'to_confirm_receive', 'shipped'], true)) {
            return;
        }

        $order = \Modules\Sales\Models\SalesOrder::query()
            ->where('source', 'shopee')
            ->where('channel_order_no', $orderSn)
            ->first();
        if (! $order) return;

        $shipment = \Modules\Outbound\Models\Shipment::query()
            ->whereHas('orders', fn ($q) => $q->where('order_id', $order->id))
            ->latest('id')
            ->first();
        if (! $shipment) return;

        $eventType = match ($status) {
            'shipped' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_IN_TRANSIT,
            'to_confirm_receive', 'completed' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_DELIVERED,
            default => null,
        };
        if (! $eventType) return;

        $exists = \Modules\Outbound\Models\ShipmentTrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'shopee')
            ->where('event_type', $eventType)
            ->exists();
        if ($exists) return;

        \Modules\Outbound\Models\ShipmentTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'source' => 'shopee',
            'event_type' => $eventType,
            'driver_name' => null,
            'driver_phone' => null,
            'driver_vehicle_plate' => null,
            'raw_payload' => $data,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    protected function handleReturnEvent(ShopeeOrderService $orderService, string $shopId, array $data): void
    {
        $orderSn = (string) ($data['ordersn'] ?? $data['order_sn'] ?? '');

        if ($orderSn === '') {
            Log::warning('Shopee webhook refund tanpa ordersn — diabaikan.', ['data' => $data]);

            return;
        }

        $orderService->pullOrderById($shopId, $orderSn);

        \Modules\Sales\Jobs\ProcessChannelReturnJob::dispatch([
            'source'            => 'shopee',
            'channel_order_id'  => $orderSn,
            'channel_return_id' => $data['return_sn'] ?? $data['refund_id'] ?? null,
            'channel_shop_id'   => $shopId,
            'reason'            => $data['reason'] ?? 'Retur Shopee',
            'created_by'        => 'system:shopee-webhook',
        ]);
    }

    protected function logItemEvent(ChannelDownloadService $downloadService, string $shopId, array $data): void
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

        try {
            $downloadService->downloadProductDebounced('shopee', $shopId, $itemId);
        } catch (\Throwable $e) {
            Log::warning('Shopee re-sync produk gagal: ' . $e->getMessage(), ['item_id' => $itemId]);
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
        Cache::forget(self::idempotencyKey($this->payload));

        \Modules\Channel\Support\WebhookFailureHandler::record(
            'shopee',
            self::idempotencyKey($this->payload),
            [
                'shop_id' => $this->payload['shop_id'] ?? null,
                'code' => $this->payload['code'] ?? null,
            ],
            $e,
        );
    }
}
