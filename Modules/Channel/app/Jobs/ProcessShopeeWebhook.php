<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\ChannelWebhookAuditService;
use Modules\Channel\Services\ShopeeOrderService;
use Modules\Channel\Support\ChannelOrderIntakeGate;
use Modules\Channel\Support\ChannelOrderPullGuard;
use Modules\Channel\Support\WebhookFailureHandler;
use Modules\Outbound\Jobs\RefreshInstantTrackingJob;
use Modules\Outbound\Models\Shipment;
use Modules\Outbound\Models\ShipmentTrackingEvent;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Sales\Jobs\ProcessChannelReturnJob;
use Modules\Sales\Models\SalesOrder;

class ProcessShopeeWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 86400;

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

        return 'shopee:webhook:'.md5(json_encode([
            $payload['shop_id'] ?? '',
            $payload['code'] ?? '',
            $payload['timestamp'] ?? '',
            $data['ordersn'] ?? $data['order_sn'] ?? $data['item_id'] ?? '',
            $data['status'] ?? '',
        ]));
    }

    public function uniqueId(): string
    {
        return self::idempotencyKey($this->payload);
    }

    protected bool $orderIntakeSkipped = false;

    public function handle(
        ShopeeOrderService $orderService,
        ChannelDownloadService $downloadService,
        ?ChannelWebhookAuditService $webhookAudit = null,
    ): void {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return;
        }

        $shopId = (string) ($this->payload['shop_id'] ?? '');
        $code = (int) ($this->payload['code'] ?? -1);
        $data = $this->payload['data'] ?? [];

        if ($shopId === '') {
            Log::warning('Shopee webhook tanpa shop_id — diabaikan.', [
                'code' => $code,
                'event_key' => self::idempotencyKey($this->payload),
            ]);

            return;
        }

        $idempotencyKey = self::idempotencyKey($this->payload);
        if (! Cache::add($idempotencyKey, true, now()->addHours(24))) {
            Log::info("Shopee webhook already processed (key: {$idempotencyKey})");

            return;
        }

        try {
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
        } catch (\Throwable $e) {
            Cache::forget($idempotencyKey);
            throw $e;
        }

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
            ChannelWebhookInbox::markSkippedByKey($eventKey, ChannelOrderIntakeGate::reason());

            return;
        }

        ChannelWebhookInbox::markProcessedByKey($eventKey);
    }

    protected function handleOrderEventOrSkip(ShopeeOrderService $orderService, string $shopId, array $data): void
    {
        if (ChannelOrderIntakeGate::blocksShop($shopId, 'shopee')) {
            $this->orderIntakeSkipped = true;

            return;
        }

        $this->handleOrderEvent($orderService, $shopId, $data);
    }

    protected function recordShopeeTrackingEvent(string $shopId, int $code, array $data): void
    {
        $orderSn = (string) ($data['ordersn'] ?? $data['order_sn'] ?? '');
        if ($orderSn === '') {
            return;
        }

        $order = SalesOrder::query()
            ->where('source', 'shopee')
            ->where('channel_order_no', $orderSn)
            ->first();
        if (! $order) {
            return;
        }

        $shipment = Shipment::query()
            ->whereHas('orders', fn ($q) => $q->where('order_id', $order->id))
            ->latest('id')
            ->first();
        if (! $shipment) {
            return;
        }

        $eventType = match ($code) {
            self::PUSH_TRACKING_NO,
            self::PUSH_BOOKING_TRACKING_NO => ShipmentTrackingEvent::EVENT_DRIVER_ASSIGNED,
            self::PUSH_PACKAGE_FULFILLMENT => ShipmentTrackingEvent::EVENT_PICKED_UP,
            self::PUSH_COURIER_DELIVERY_BINDING => ShipmentTrackingEvent::EVENT_DRIVER_ARRIVED,
            default => 'shopee_event_'.$code,
        };

        $exists = ShipmentTrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'shopee')
            ->where('event_type', $eventType)
            ->exists();
        if ($exists) {
            return;
        }

        ShipmentTrackingEvent::create([
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

        RefreshInstantTrackingJob::dispatch($shipment->id);
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

        if (! ChannelOrderPullGuard::pullOnce(
            'shopee',
            $shopId,
            $orderSn,
            fn (): int => $orderService->pullOrderById($shopId, $orderSn),
        )) {
            Log::info("Shopee webhook {$orderSn} di-debounce (sudah di-pull dalam 15 detik terakhir).");
            $this->recordDeliveredEventIfApplicable($orderSn, $data);

            return;
        }

        $this->recordDeliveredEventIfApplicable($orderSn, $data);
    }

    protected function recordDeliveredEventIfApplicable(string $orderSn, array $data): void
    {
        $status = strtolower((string) ($data['status'] ?? ''));
        if (! in_array($status, ['completed', 'to_confirm_receive', 'shipped'], true)) {
            return;
        }

        $order = SalesOrder::query()
            ->where('source', 'shopee')
            ->where('channel_order_no', $orderSn)
            ->first();
        if (! $order) {
            return;
        }

        $shipment = Shipment::query()
            ->whereHas('orders', fn ($q) => $q->where('order_id', $order->id))
            ->latest('id')
            ->first();
        if (! $shipment) {
            return;
        }

        $eventType = match ($status) {
            'shipped' => ShipmentTrackingEvent::EVENT_IN_TRANSIT,
            'to_confirm_receive', 'completed' => ShipmentTrackingEvent::EVENT_DELIVERED,
            default => null,
        };
        if (! $eventType) {
            return;
        }

        $exists = ShipmentTrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'shopee')
            ->where('event_type', $eventType)
            ->exists();
        if ($exists) {
            return;
        }

        ShipmentTrackingEvent::create([
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

        ChannelOrderPullGuard::pullOnce(
            'shopee',
            $shopId,
            $orderSn,
            fn (): int => $orderService->pullOrderById($shopId, $orderSn),
        );

        ProcessChannelReturnJob::dispatch([
            'source' => 'shopee',
            'channel_order_id' => $orderSn,
            'channel_return_id' => $data['return_sn'] ?? $data['refund_id'] ?? null,
            'channel_shop_id' => $shopId,
            'reason' => $data['reason'] ?? 'Retur Shopee',
            'channel_status' => $data['status'] ?? $data['return_status'] ?? $data['refund_status'] ?? null,
            'created_by' => 'system:shopee-webhook',
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
            Log::warning('Shopee re-sync produk gagal: '.$e->getMessage(), ['item_id' => $itemId]);
        }

        $status = strtolower((string) ($data['status'] ?? ''));

        if (in_array($status, ['normal', 'active'], true)) {
            $mapping->markApproved();
        } elseif ($status === 'banned') {
            $mapping->markRejected('Shopee item banned: '.($data['ban_reason'] ?? $status));
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

        WebhookFailureHandler::record(
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
