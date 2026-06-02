<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokOrderService;

class AcceptTikTokOrder extends Command
{
    protected $signature = 'tiktok:accept-order {shop_id} {order_id}';

    protected $description = 'Accept (pack) an order from TikTok Shop';

    public function handle(TikTokOrderService $service)
    {
        $shopId = $this->argument('shop_id');
        $orderId = $this->argument('order_id');
        
        $this->info("Accepting order ID: {$orderId} for Shop ID: {$shopId}...");

        try {
            $res = $service->acceptOrder($shopId, $orderId);
            $this->info("Successfully accepted order!");
            $this->line(json_encode($res, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error("Failed to accept order: " . $e->getMessage());
        }
    }
}
