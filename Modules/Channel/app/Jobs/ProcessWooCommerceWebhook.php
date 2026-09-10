<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Models\ChannelWebhookInbox;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\ChannelSyncSettingService;
use Modules\Channel\Services\ChannelWebhookAuditService;
use Modules\Channel\Services\WooCommerceOrderService;
use Modules\Channel\Support\ChannelOrderIntakeGate;
use Modules\Channel\Support\ChannelOrderPullGuard;
use Modules\Channel\Support\WebhookFailureHandler;
use Modules\Channel\Support\WebhookRetryPolicy;
use Modules\Product\Models\ProductChannelMapping;
use Modules\Sales\Jobs\ProcessChannelReturnJob;

class ProcessWooCommerceWebhook implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use WebhookRetryPolicy;

    public array $backoff = [10, 60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 86400;

    public function __construct(
        public string $shopId,
        public string $topic,
        public string $resourceId,
        public array $payload = [],
    ) {
        $this->onQueue(config('queue.names.webhook_downloads'));
    }

    public static function idempotencyKey(string $shopId, string $topic, array $payload): string
    {
        return 'woocommerce:webhook:'.md5(json_encode([
            $shopId,
            $topic,
            $payload['id'] ?? '',
            $payload['date_modified'] ?? ($payload['status'] ?? ''),
        ]));
    }

    public function uniqueId(): string
    {
        return self::idempotencyKey($this->shopId, $this->topic, $this->payload);
    }

    public function handle(
        WooCommerceOrderService $orderService,
        ChannelDownloadService $downloadService,
        ?ChannelWebhookAuditService $webhookAudit = null,
    ): void {
        if (app(ChannelSyncSettingService::class)->isPaused()) {
            return;
        }

        if ($this->shopId === '' || $this->resourceId === '') {
            return;
        }

        $idempotencyKey = self::idempotencyKey($this->shopId, $this->topic, $this->payload);
        if (! Cache::add($idempotencyKey, true, now()->addHours(24))) {
            Log::info("WooCommerce webhook already processed (key: {$idempotencyKey})");

            return;
        }

        $resource = strtok($this->topic, '.');

        try {
            match ($resource) {
                'order' => $this->handleOrderEvent($orderService),
                'product' => $this->handleProductEvent($downloadService),
                default => Log::info("WooCommerce webhook topic '{$this->topic}' belum ditangani — diabaikan.", [
                    'shop_id' => $this->shopId,
                ]),
            };
        } catch (\Throwable $e) {
            Cache::forget($idempotencyKey);
            throw $e;
        }

        $eventKey = self::idempotencyKey($this->shopId, $this->topic, $this->payload);

        if (! $this->orderIntakeSkipped && $webhookAudit) {
            $webhookAudit->recordFromInbox('woocommerce', $eventKey, $this->payload + [
                '_webhook_topic' => $this->topic,
            ]);
        }

        if ($this->orderIntakeSkipped) {
            ChannelWebhookInbox::markSkippedByKey($eventKey, ChannelOrderIntakeGate::reason());

            return;
        }

        ChannelWebhookInbox::markProcessedByKey($eventKey);
    }

    protected bool $orderIntakeSkipped = false;

    protected function handleOrderEvent(WooCommerceOrderService $orderService): void
    {
        if (ChannelOrderIntakeGate::blocksShop((string) $this->shopId, 'woocommerce')) {
            $this->orderIntakeSkipped = true;

            return;
        }

        ChannelOrderPullGuard::pullOnce(
            'woocommerce',
            $this->shopId,
            $this->resourceId,
            fn (): int => $orderService->pullOrderById($this->shopId, $this->resourceId),
        );

        if ($this->topic === 'order.deleted') {
            return;
        }

        $this->detectAndHandleRefunds();
    }

    protected function detectAndHandleRefunds(): void
    {
        $refunds = $this->payload['refunds'] ?? [];
        $status = strtolower((string) ($this->payload['status'] ?? ''));
        $orderId = (string) ($this->payload['id'] ?? $this->resourceId);

        if (empty($refunds) && $status !== 'refunded') {
            return;
        }

        $refundEntries = ! empty($refunds)
            ? $refunds
            : [[
                'id' => 'full-'.$orderId,
                'reason' => 'Order fully refunded',
                'total' => $this->payload['total'] ?? 0,
            ]];

        foreach ($refundEntries as $refund) {
            $refundId = (string) ($refund['id'] ?? '');
            if ($refundId === '') {
                continue;
            }

            $reason = (string) ($refund['reason'] ?? '');

            ProcessChannelReturnJob::dispatch([
                'source' => 'woocommerce',
                'channel_order_id' => $orderId,
                'channel_return_id' => $refundId,
                'channel_shop_id' => $this->shopId,
                'reason' => $reason !== '' ? $reason : 'Refund WooCommerce',
                'channel_status' => $status !== '' ? strtoupper($status) : 'REFUNDED',
                'created_by' => 'system:woocommerce-webhook',
            ]);
        }
    }

    protected function handleProductEvent(ChannelDownloadService $downloadService): void
    {
        $channelShopId = DB::table('channel_shops')
            ->where('shop_id', $this->shopId)
            ->value('id');

        if (! $channelShopId || ! ProductChannelMapping::query()
            ->where('channel_shop_id', $channelShopId)
            ->where('external_product_id', $this->resourceId)
            ->exists()) {
            Log::debug('WooCommerce product webhook diabaikan karena listing belum terhubung.', [
                'shop_id' => $this->shopId,
                'product_id' => $this->resourceId,
            ]);

            return;
        }

        try {
            $downloadService->downloadProductDebounced('woocommerce', $this->shopId, $this->resourceId);
        } catch (\Throwable $e) {
            Log::warning('WooCommerce re-sync produk gagal: '.$e->getMessage(), [
                'shop_id' => $this->shopId,
                'product_id' => $this->resourceId,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        Cache::forget(self::idempotencyKey($this->shopId, $this->topic, $this->payload));

        WebhookFailureHandler::record(
            'woocommerce',
            self::idempotencyKey($this->shopId, $this->topic, $this->payload),
            [
                'shop_id' => $this->shopId,
                'topic' => $this->topic,
                'resource_id' => $this->resourceId,
            ],
            $e,
        );
    }
}
