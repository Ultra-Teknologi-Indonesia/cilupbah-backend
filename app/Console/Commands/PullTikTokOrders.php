<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokOrderService;

class PullTikTokOrders extends Command
{
    protected $signature = 'tiktok:pull-orders {shop_id}';

    protected $description = 'Pull orders from TikTok Shop API';

    public function handle(TikTokOrderService $service)
    {
        $shopId = $this->argument('shop_id');
        $this->info("Starting pull orders for Shop ID: {$shopId}");

        try {
            $count = $service->pullOrders($shopId);
            $this->info("Successfully pulled {$count} orders!");
        } catch (\Exception $e) {
            $this->error("Failed to pull orders: " . $e->getMessage());
            $this->line($e->getTraceAsString());
        }
    }
}
