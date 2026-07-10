<?php

namespace Modules\Channel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Channel\Services\ChannelDownloadService;
use Modules\Channel\Services\WooCommerceOrderService;

class ProcessWooCommerceWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300];

    public function __construct(
        public string $shopId,
        public string $topic,
        public string $resourceId,
    ) {
        $this->onQueue('default');
    }

    public function handle(WooCommerceOrderService $orderService, ChannelDownloadService $downloadService): void
    {
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
    }

    protected function handleOrderEvent(WooCommerceOrderService $orderService): void
    {
        $orderService->pullOrderById($this->shopId, $this->resourceId);

        if ($this->topic === 'order.deleted') {
            return;
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
        Log::error('ProcessWooCommerceWebhook gagal permanen: ' . $e->getMessage(), [
            'shop_id' => $this->shopId,
            'topic' => $this->topic,
            'resource_id' => $this->resourceId,
        ]);
    }
}
