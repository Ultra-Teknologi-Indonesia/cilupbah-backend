<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokProductService;
use Modules\Channel\Services\TikTokClient;
use Modules\Channel\Services\TikTokProductMapper;

class PushTikTokProduct extends Command
{

    protected $signature = 'tiktok:push-product {product_id} {shop_id}';

    protected $description = 'Push an internal product to TikTok Shop API';

    public function handle(TikTokClient $client, TikTokProductMapper $mapper)
    {
        $productId = $this->argument('product_id');
        $shopId = $this->argument('shop_id');

        $this->info("Starting push for Product ID: {$productId} to Shop ID: {$shopId}");

        $service = new TikTokProductService($client, $mapper);

        try {
            $response = $service->pushProduct((int)$productId, $shopId);
            $this->info("Successfully pushed product!");
            $this->line(json_encode($response, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error("Failed to push product: " . $e->getMessage());

            $this->line($e->getTraceAsString());
        }
    }
}
