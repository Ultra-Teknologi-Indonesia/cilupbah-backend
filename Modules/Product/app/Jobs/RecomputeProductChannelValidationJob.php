<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Models\Product;
use Modules\Product\Services\ProductChannelValidationService;

class RecomputeProductChannelValidationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120, 300];

    public int $uniqueFor = 600;

    public function __construct(public string $productId)
    {
        $this->onConnection(config('queue.routing.channel_sync.connection', 'redis-channel-sync'));
        $this->onQueue(config('queue.names.product'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pcv:{$this->productId}"))->dontRelease()];
    }

    public function uniqueId(): string
    {
        return $this->productId;
    }

    public function handle(ProductChannelValidationService $service): void
    {
        $product = Product::find($this->productId);
        if ($product) {
            $service->recompute($product);
        }
    }

    public function tags(): array
    {
        return ['product', 'recompute-channel-validation', "product:{$this->productId}"];
    }
}
