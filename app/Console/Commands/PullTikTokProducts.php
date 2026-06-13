<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokProductService;

class PullTikTokProducts extends Command
{

    protected $signature = 'tiktok:pull-products {shop_id : The TikTok Shop ID}';

    protected $description = 'Pull and sync products from TikTok Shop to internal DB';

    public function handle(TikTokProductService $tiktokService)
    {
        $shopId = $this->argument('shop_id');
        $this->info("Starting product pull from Shop ID: {$shopId}");

        try {
            $count = $tiktokService->pullProducts($shopId);
            $this->info("Successfully pulled {$count} new products from TikTok!");
        } catch (\Exception $e) {
            $this->error("Failed to pull products: " . $e->getMessage());
        }
    }
}
