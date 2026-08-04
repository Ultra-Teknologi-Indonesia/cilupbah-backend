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
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\WooCommerceOrderService;

class ProcessWooCommerceWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];
    public int $timeout = 120;

    public function __construct(
        public string $shopId,
        public string $topic,
        public string $resourceId,
        public array $payload = [],
    ) {
        $this->onQueue(config('queue.names.webhook_downloads'));
    }

    public function middleware(): array
    {
        return [new RateLimited('webhook_download')];
    }

    public static function idempotencyKey(string $shopId, string $topic, array $payload): string
    {
        return 'woocommerce:webhook:' . md5(json_encode([
            $shopId,
            $topic,
            $payload['id'] ?? '',
            $payload['date_modified'] ?? ($payload['status'] ?? ''),
        ]));
    }

    public function handle(WooCommerceOrderService $orderService, ChannelDownloadService $downloadService): void
    {
        if (app(\Modules\Channel\Services\ChannelSyncSettingService::class)->isPaused()) {
            return;
        }

        if ($this->shopId === '' || $this->resourceId === '') {
            return;
        }

        $resource = strtok($this->topic, '.');

        match ($resource) {
            'order' => $this->handleOrderEvent($orderService),
            'product' => $this->handleProductEvent($downloadService),
            default => Log::info("WooCommerce webhook topic '{$this->topic}' belum ditangani — diabaikan.", [
                'shop_id' => $this->shopId,
            ]),
        };

        ChannelWebhookInbox::markProcessedByKey(
            self::idempotencyKey($this->shopId, $this->topic, $this->payload),
        );
    }

    protected function handleOrderEvent(WooCommerceOrderService $orderService): void
    {
        $orderService->pullOrderById($this->shopId, $this->resourceId);

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
                'id' => 'full-' . $orderId,
                'reason' => 'Order fully refunded',
                'total' => $this->payload['total'] ?? 0,
            ]];

        foreach ($refundEntries as $refund) {
            try {
                $refundId = (string) ($refund['id'] ?? '');
                if ($refundId === '') {
                    continue;
                }

                $reason = (string) ($refund['reason'] ?? '');
                $salesReturn = app(\Modules\Sales\Services\SalesReturnService::class)->createFromChannel([
                    'source'            => 'woocommerce',
                    'channel_order_id'  => $orderId,
                    'channel_return_id' => $refundId,
                    'channel_shop_id'   => $this->shopId,
                    'reason'            => $reason !== '' ? $reason : 'Refund WooCommerce',
                    'created_by'        => 'system:woocommerce-webhook',
                ]);

                if ($salesReturn) {
                    \Modules\Sales\Jobs\SyncReturnTrackingJob::dispatch((string) $salesReturn->id);
                    \Modules\Sales\Jobs\SyncReturnDetailJob::dispatch((string) $salesReturn->id);
                }
            } catch (\Throwable $e) {
                Log::warning('WooCommerce auto SalesReturn gagal: ' . $e->getMessage(), [
                    'shop_id' => $this->shopId,
                    'order_id' => $orderId,
                    'refund_id' => $refund['id'] ?? null,
                ]);
            }
        }
    }

    protected function handleProductEvent(ChannelDownloadService $downloadService): void
    {
        try {
            $downloadService->downloadProductDebounced('woocommerce', $this->shopId, $this->resourceId);
        } catch (\Throwable $e) {
            Log::warning('WooCommerce re-sync produk gagal: ' . $e->getMessage(), [
                'shop_id' => $this->shopId,
                'product_id' => $this->resourceId,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {

        Cache::forget(self::idempotencyKey($this->shopId, $this->topic, $this->payload));

        Log::error('ProcessWooCommerceWebhook gagal permanen: ' . $e->getMessage(), [
            'shop_id' => $this->shopId,
            'topic' => $this->topic,
            'resource_id' => $this->resourceId,
        ]);
    }
}
