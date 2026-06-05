<?php

namespace Modules\Order\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Order\Exceptions\DuplicateOrderException;
use Modules\Order\Services\OrderService;

class ProcessMarketplaceOrder implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 15, 30];

    public function __construct(
        public readonly string $marketplace,
        public readonly string $marketplaceOrderId,
        public readonly array $orderData,
    ) {
        $this->onQueue(config('queue.names.orders'));
    }

    public function uniqueId(): string
    {
        return "{$this->marketplace}_{$this->marketplaceOrderId}";
    }

    public function uniqueFor(): int
    {
        return 60;
    }

    public function handle(OrderService $orderService): void
    {
        $idempotencyKey = "order:done:{$this->marketplace}:{$this->marketplaceOrderId}";

        if (Cache::has($idempotencyKey)) {
            Log::info("Order already processed, skipping", [
                'marketplace' => $this->marketplace,
                'order_id'    => $this->marketplaceOrderId,
            ]);
            return;
        }

        $lock = Cache::lock("order:process:{$this->uniqueId()}", 30);

        if (! $lock->get()) {
            $this->release(5);
            return;
        }

        try {
            $orderService->createOrder(array_merge($this->orderData, [
                'source'        => $this->marketplace,
                'salesorder_no' => $this->marketplaceOrderId,
            ]));
        } finally {
            $lock->release();
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessMarketplaceOrder failed', [
            'marketplace'          => $this->marketplace,
            'marketplace_order_id' => $this->marketplaceOrderId,
            'exception'            => $exception->getMessage(),
            'trace'                => $exception->getTraceAsString(),
        ]);

        AdminAlertJob::dispatch(
            "ProcessMarketplaceOrder failed: {$this->marketplace}:{$this->marketplaceOrderId}",
            $exception->getMessage(),
            [
                'marketplace'          => $this->marketplace,
                'marketplace_order_id' => $this->marketplaceOrderId,
                'order_data'           => $this->orderData,
            ]
        )->onQueue(config('queue.names.failed_jobs'));
    }
}
