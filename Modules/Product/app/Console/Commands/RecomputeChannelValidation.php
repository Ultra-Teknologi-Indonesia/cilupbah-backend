<?php

namespace Modules\Product\Console\Commands;

use Illuminate\Console\Command;
use Modules\Product\Jobs\RecomputeProductChannelValidationJob;
use Modules\Product\Models\Product;
use Modules\Product\Services\ProductChannelValidationService;

class RecomputeChannelValidation extends Command
{
    protected $signature = 'products:recompute-validation {--queue : Dispatch ke queue alih-alih jalan sinkron} {--chunk=200}';

    protected $description = 'Hitung ulang status Tidak Cocok (atribut/harga/sku) per produk × channel ke product_channel_validations';

    public function handle(ProductChannelValidationService $service): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $queue = (bool) $this->option('queue');
        $count = 0;

        Product::query()
            ->whereHas('channelMappings')
            ->select('id')
            ->chunkById($chunk, function ($products) use ($service, $queue, &$count) {
                foreach ($products as $product) {
                    if ($queue) {
                        RecomputeProductChannelValidationJob::dispatch($product->id);
                    } else {
                        $service->recompute($product);
                    }
                    $count++;
                }
            });

        $mode = $queue ? 'didispatch ke queue' : 'dihitung ulang';
        $this->info("{$count} produk {$mode}.");

        return self::SUCCESS;
    }
}
