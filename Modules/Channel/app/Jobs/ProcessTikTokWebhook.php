<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Repositories\ChannelShopRepository;
use Modules\Channel\Services\TikTokAuthService;
use Modules\Channel\Services\TikTokOrderService;
use Modules\Channel\Services\WebhookProductHandler;

class ProcessTikTokWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];
    public int $timeout = 120;

    private const EVENT_ORDER = 'order';
    private const EVENT_PACKAGE = 'package';
    private const EVENT_CANCELLATION = 'cancellation';
    private const EVENT_REVERSE = 'reverse';
    private const EVENT_REFUND_SUCCESS = 'refund_success';
    private const EVENT_AFTERSALES = 'aftersales';
    private const EVENT_PRODUCT_STATUS = 'product_status';
    private const EVENT_PRODUCT_INFO = 'product_info';
    private const EVENT_DEAUTHORIZATION = 'seller_deauthorization';
    private const EVENT_AUTH_EXPIRATION = 'authorization_expiration';
    private const EVENT_IGNORED = 'ignored';
    private const EVENT_UNKNOWN = 'unknown';

    private const TYPE_MAP = [
        1 => self::EVENT_ORDER,             
        2 => self::EVENT_REVERSE,           
        3 => self::EVENT_ORDER,             
        4 => self::EVENT_PACKAGE,           
        5 => self::EVENT_PRODUCT_STATUS,    
        6 => self::EVENT_DEAUTHORIZATION,   
        7 => self::EVENT_AUTH_EXPIRATION,   
        11 => self::EVENT_CANCELLATION,     
        12 => self::EVENT_REVERSE,          
        15 => self::EVENT_PRODUCT_INFO,     
        37 => self::EVENT_PRODUCT_STATUS,   
        50 => self::EVENT_PRODUCT_INFO,     
        64 => self::EVENT_AFTERSALES,       
        67 => self::EVENT_REFUND_SUCCESS,   

        27 => self::EVENT_IGNORED,          
        36 => self::EVENT_IGNORED,          
        42 => self::EVENT_IGNORED,          
        46 => self::EVENT_IGNORED,          
        51 => self::EVENT_IGNORED,          
        62 => self::EVENT_IGNORED,          
        66 => self::EVENT_IGNORED,          

    ];

    public array $payload;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function middleware(): array
    {
        return [new RateLimited('tiktok_api')];
    }

    public static function idempotencyKey(array $payload): string
    {
        $notificationId = (string) ($payload['tts_notification_id'] ?? '');

        if ($notificationId !== '') {
            return 'tiktok_webhook:nid:' . $notificationId;
        }

        return 'tiktok_webhook:hash:' . md5(json_encode($payload));
    }

    public function handle(
        TikTokOrderService $orderService,
        WebhookProductHandler $productHandler,
        ChannelShopRepository $shops,
        TikTokAuthService $authService,
    ): void {
        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
            return;
        }

        $type = (int) ($this->payload['type'] ?? -1);
        $shopId = (string) ($this->payload['shop_id'] ?? '');
        $data = $this->payload['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        if ($shopId === '') {
            $this->acknowledgeShoplessEvent($type);

            return;
        }

        $idempotencyKey = self::idempotencyKey($this->payload);

        if (! Cache::add($idempotencyKey, true, now()->addHours(24))) {
            Log::info("TikTok Webhook already processed (key: {$idempotencyKey})");

            return;
        }

        try {
            $event = $this->resolveEvent($type, $data);

            match ($event) {
                self::EVENT_ORDER => $this->skipsOrderIntake($shopId) ? null : $this->handleOrderEvent($orderService, $shopId, $data),
                self::EVENT_PACKAGE => $this->skipsOrderIntake($shopId) ? null : $this->handlePackageEvent($orderService, $shopId, $data),
                self::EVENT_CANCELLATION => $this->handleCancellationEvent($orderService, $shopId, $data),
                self::EVENT_REVERSE => $this->handleReverseEvent($orderService, $shopId, $data),
                self::EVENT_REFUND_SUCCESS => $this->handleRefundSuccessEvent($orderService, $shopId, $data),
                self::EVENT_AFTERSALES => $this->handleAftersalesEvent($shopId, $data),
                self::EVENT_PRODUCT_STATUS => $productHandler->handleProductStatusChange($data, $shopId),
                self::EVENT_PRODUCT_INFO => $productHandler->handleProductUpdate($data, $shopId),
                self::EVENT_DEAUTHORIZATION => $this->handleDeauthorization($shops, $shopId),
                self::EVENT_AUTH_EXPIRATION => $this->handleAuthExpiration($authService, $shops, $shopId),
                self::EVENT_IGNORED => Log::info('TikTok webhook diterima (tidak diproses) — topik di luar cakupan.', [
                    'type' => $type,
                    'shop_id' => $shopId,
                ]),
                default => Log::info('Unhandled TikTok webhook — logged for type discovery.', [
                    'type' => $type,
                    'shop_id' => $shopId,
                    'tts_notification_id' => $this->payload['tts_notification_id'] ?? null,
                    'data_keys' => array_keys($data),
                ]),
            };

            if ($this->orderIntakeSkipped) {
                ChannelWebhookInbox::markSkippedByKey($idempotencyKey, \Modules\Channel\Support\ChannelOrderIntakeGate::reason());
            } else {
                ChannelWebhookInbox::markProcessedByKey($idempotencyKey);
            }
        } catch (\Throwable $e) {
            Cache::forget($idempotencyKey);
            throw $e;
        }
    }

    private function resolveEvent(int $type, array $data): string
    {
        $overrides = (array) config('services.tiktok.webhook_type_overrides', []);
        $override = $overrides[$type] ?? $overrides[(string) $type] ?? null;
        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (isset(self::TYPE_MAP[$type])) {
            return self::TYPE_MAP[$type];
        }

        if ($this->hasAny($data, ['reverse_order_id', 'reverse_status', 'return_id', 'return_status'])) {
            return self::EVENT_REVERSE;
        }

        if ($this->hasAny($data, ['cancel_id', 'cancellation_id', 'cancel_status', 'cancellations_role'])) {
            return self::EVENT_CANCELLATION;
        }

        if (isset($data['order_id']) || isset($data['order_status'])) {
            return self::EVENT_ORDER;
        }

        if ($this->looksLikeAuthExpiration($data)) {
            return self::EVENT_AUTH_EXPIRATION;
        }

        if ($this->looksLikeDeauthorization($data)) {
            return self::EVENT_DEAUTHORIZATION;
        }

        if (isset($data['product_id'])) {
            return self::EVENT_PRODUCT_INFO;
        }

        return self::EVENT_UNKNOWN;
    }

    protected bool $orderIntakeSkipped = false;

    protected function skipsOrderIntake(string $shopId): bool
    {
        if (! \Modules\Channel\Support\ChannelOrderIntakeGate::blocksShop($shopId, 'tiktok')) {
            return false;
        }

        return $this->orderIntakeSkipped = true;
    }

    protected function handleOrderEvent(TikTokOrderService $orderService, string $shopId, array $data): void
    {
        $orderId = (string) ($data['order_id'] ?? '');
        if ($orderId === '') {
            Log::warning('TikTok order webhook tanpa order_id — diabaikan.', ['shop_id' => $shopId]);

            return;
        }

        $orderService->pullOrderById($shopId, $orderId);
        $this->recordTikTokTrackingEvent($orderId, $data);
    }

    protected function handlePackageEvent(TikTokOrderService $orderService, string $shopId, array $data): void
    {
        $orderIds = [];
        foreach ((array) ($data['package_list'] ?? []) as $package) {
            foreach ((array) ($package['order_id_list'] ?? []) as $orderId) {
                $orderIds[(string) $orderId] = true;
            }
        }

        if (empty($orderIds)) {
            Log::info('TikTok package webhook tanpa order_id_list — diabaikan.', ['shop_id' => $shopId]);

            return;
        }

        foreach (array_keys($orderIds) as $orderId) {
            $orderService->pullOrderById($shopId, $orderId);

            $localId = \Modules\Sales\Models\SalesOrder::query()
                ->where('source', 'tiktok')
                ->where('channel_order_no', (string) $orderId)
                ->value('id');
            if ($localId) {
                \Modules\Sales\Jobs\PrepareTikTokShippingLabelJob::dispatch((string) $localId);
            }
        }
    }

    protected function handleCancellationEvent(TikTokOrderService $orderService, string $shopId, array $data): void
    {
        $orderId = (string) ($data['order_id'] ?? '');
        if ($orderId === '') {
            Log::info('TikTok cancellation webhook tanpa order_id — diabaikan.', ['shop_id' => $shopId]);

            return;
        }

        $orderService->pullOrderById($shopId, $orderId);

        $cancelStatus = (string) ($data['cancel_status'] ?? '');

        if ($cancelStatus === 'CANCELLATION_REQUEST_CANCEL') {
            app(\Modules\Sales\Services\SalesOrderService::class)
                ->markChannelCancelRejected($orderId, 'TikTok menolak permintaan pembatalan');
        }

        Log::info("TikTok cancellation: order {$orderId} resynced.", [
            'shop_id' => $shopId,
            'cancel_status' => $cancelStatus,
        ]);
    }

    protected function handleReverseEvent(TikTokOrderService $orderService, string $shopId, array $data): void
    {
        $orderId = (string) ($data['order_id'] ?? '');
        if ($orderId === '') {
            Log::info('TikTok reverse/return webhook tanpa order_id — diabaikan.', ['shop_id' => $shopId]);

            return;
        }

        $orderService->pullOrderById($shopId, $orderId);
        Log::info("TikTok reverse/return: order {$orderId} resynced.", [
            'shop_id' => $shopId,
            'return_status' => $data['return_status'] ?? null,
        ]);

        $this->createChannelReturn(
            $shopId,
            $orderId,
            $data['return_id'] ?? $data['reverse_order_id'] ?? null,
            $data['return_reason'] ?? $data['reverse_status'] ?? $data['return_status'] ?? $data['reason'] ?? 'Retur TikTok',
        );
    }

    protected function handleRefundSuccessEvent(TikTokOrderService $orderService, string $shopId, array $data): void
    {

        $orderId = '';
        foreach ((array) ($data['line_items'] ?? []) as $line) {
            if (! empty($line['main_order_id'])) {
                $orderId = (string) $line['main_order_id'];
                break;
            }
        }

        if ($orderId === '') {
            Log::info('TikTok refund-success tanpa main_order_id — hanya dicatat.', [
                'shop_id' => $shopId,
                'rma_id' => $data['rma_id'] ?? null,
            ]);

            return;
        }

        $orderService->pullOrderById($shopId, $orderId);

        $this->createChannelReturn(
            $shopId,
            $orderId,
            null,
            'Refund TikTok berhasil (RMA ' . ($data['rma_id'] ?? '-') . ')',
        );
    }

    protected function handleAftersalesEvent(string $shopId, array $data): void
    {

        Log::info('TikTok aftersales request diterima — menunggu reverse/return untuk pembuatan retur.', [
            'shop_id' => $shopId,
            'aftersales_request_id' => $data['aftersales_request_id'] ?? null,
            'status' => $data['aftersales_request_status'] ?? null,
        ]);
    }

    protected function createChannelReturn(string $shopId, string $orderId, ?string $channelReturnId, string $reason): void
    {

        \Modules\Sales\Jobs\ProcessChannelReturnJob::dispatch([
            'source'            => 'tiktok',
            'channel_order_id'  => $orderId,
            'channel_return_id' => $channelReturnId,
            'channel_shop_id'   => $shopId,
            'reason'            => $reason,
            'created_by'        => 'system:tiktok-webhook',
        ]);
    }

    protected function handleDeauthorization(ChannelShopRepository $shops, string $shopId): void
    {
        $uuid = $shops->getIdByShopId($shopId);
        if (! $uuid) {
            Log::info('TikTok deauthorization untuk shop tak dikenal — diabaikan.', ['shop_id' => $shopId]);

            return;
        }

        $shops->markDeauthorized($uuid, 'TikTok seller deauthorization via webhook');
        Log::info('TikTok toko di-deauthorize via webhook.', ['shop_id' => $shopId]);
    }

    protected function handleAuthExpiration(TikTokAuthService $authService, ChannelShopRepository $shops, string $shopId): void
    {
        $uuid = $shops->getIdByShopId($shopId);
        if (! $uuid) {
            Log::info('TikTok expiry-alert untuk shop tak dikenal — diabaikan.', ['shop_id' => $shopId]);

            return;
        }

        try {
            $authService->refreshStoreToken($uuid);
            Log::info('TikTok token diperbarui via upcoming-expiration webhook.', ['shop_id' => $shopId]);
        } catch (\Throwable $e) {
            $shops->markIntegrationError($uuid, 'TikTok token akan kedaluwarsa & auto-refresh gagal: ' . $e->getMessage());
            Log::warning('TikTok auto-refresh via expiry webhook gagal.', [
                'shop_id' => $shopId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function recordTikTokTrackingEvent(string $orderId, array $data): void
    {
        $status = strtoupper((string) ($data['order_status'] ?? $data['status'] ?? ''));
        $isDriverEvent = in_array($status, [
            'AWAITING_COLLECTION',
            'IN_TRANSIT',
            'DELIVERED',
        ], true);
        if (! $isDriverEvent) {
            return;
        }

        $order = \Modules\Sales\Models\SalesOrder::query()
            ->where('source', 'tiktok')
            ->where('channel_order_no', $orderId)
            ->first();
        if (! $order) {
            return;
        }

        $shipment = \Modules\Outbound\Models\Shipment::query()
            ->whereHas('orders', fn ($q) => $q->where('order_id', $order->id))
            ->latest('id')
            ->first();
        if (! $shipment) {
            return;
        }

        $eventType = match ($status) {
            'AWAITING_COLLECTION' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_DRIVER_ASSIGNED,
            'IN_TRANSIT' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_IN_TRANSIT,
            'DELIVERED' => \Modules\Outbound\Models\ShipmentTrackingEvent::EVENT_DELIVERED,
            default => 'tiktok_' . strtolower($status),
        };

        $exists = \Modules\Outbound\Models\ShipmentTrackingEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('source', 'tiktok')
            ->where('event_type', $eventType)
            ->exists();
        if ($exists) {
            return;
        }

        \Modules\Outbound\Models\ShipmentTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'source' => 'tiktok',
            'event_type' => $eventType,
            'driver_name' => null,
            'driver_phone' => null,
            'driver_vehicle_plate' => null,
            'raw_payload' => $data,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);

        \Modules\Outbound\Jobs\RefreshInstantTrackingJob::dispatch($shipment->id);
    }

    private function looksLikeAuthExpiration(array $data): bool
    {
        $blob = strtolower((string) json_encode($data));

        return str_contains($blob, 'expir')
            && (str_contains($blob, 'author') || str_contains($blob, 'token'));
    }

    private function looksLikeDeauthorization(array $data): bool
    {
        $blob = strtolower((string) json_encode($data));

        return str_contains($blob, 'deauth')
            || str_contains($blob, 'unauthor')
            || str_contains($blob, 'revoke');
    }

    private function acknowledgeShoplessEvent(int $type): void
    {

        ChannelWebhookInbox::markProcessedByKey(self::idempotencyKey($this->payload));

        $isInventoryChanged = isset($this->payload['event_id'], $this->payload['seller_id'])
            && (isset($this->payload['quantity_snapshot_after_change']) || isset($this->payload['change_detail']));

        if ($isInventoryChanged) {
            Log::info('TikTok inventory-changed webhook diterima (tidak dikonsumsi — ERP pemilik stok).', [
                'event_id' => $this->payload['event_id'] ?? null,
                'seller_id' => $this->payload['seller_id'] ?? null,
            ]);

            return;
        }

        $sellerRef = $this->payload['seller_open_id']
            ?? $this->payload['creator_open_id']
            ?? $this->payload['seller_id']
            ?? null;

        if ($sellerRef !== null) {
            Log::info('TikTok webhook seller-scoped (FBT/global) diterima — di luar cakupan.', [
                'type' => $type,
                'seller_ref' => $sellerRef,
            ]);

            return;
        }

        Log::warning('TikTok Webhook tanpa shop_id — diabaikan.', [
            'type' => $type,
            'tts_notification_id' => $this->payload['tts_notification_id'] ?? null,
        ]);
    }

    private function hasAny(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    public function failed(\Throwable $e): void
    {
        Cache::forget(self::idempotencyKey($this->payload));

        \Modules\Channel\Support\WebhookFailureHandler::record(
            'tiktok',
            self::idempotencyKey($this->payload),
            [
                'shop_id' => $this->payload['shop_id'] ?? null,
                'type' => $this->payload['type'] ?? null,
            ],
            $e,
        );
    }
}
