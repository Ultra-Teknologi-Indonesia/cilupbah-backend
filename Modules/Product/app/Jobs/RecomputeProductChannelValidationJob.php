<?php

namespace Modules\Product\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Modules\Product\Models\Product;
use Modules\Product\Services\ProductChannelValidationService;

class RecomputeProductChannelValidationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $productId)
    {
        $this->onConnection('redis');
        $this->onQueue(config('queue.names.product'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("pcv:{$this->productId}"))->releaseAfter(30)];
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
