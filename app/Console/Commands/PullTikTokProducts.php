<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokProductService;

class PullTikTokProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tiktok:pull-products {shop_id : The TikTok Shop ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull and sync products from TikTok Shop to internal DB';

    /**
     * Execute the console command.
     */
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
