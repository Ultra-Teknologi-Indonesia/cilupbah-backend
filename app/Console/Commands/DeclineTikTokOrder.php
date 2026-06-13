<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Channel\Services\TikTokOrderService;

class DeclineTikTokOrder extends Command
{
    protected $signature = 'tiktok:decline-order {shop_id} {order_id} {--reason=OUT_OF_STOCK : Reason code for cancellation}';

    protected $description = 'Decline (cancel) an order from TikTok Shop';

    public function handle(TikTokOrderService $service)
    {
        $shopId = $this->argument('shop_id');
        $orderId = $this->argument('order_id');
        $reason = $this->option('reason');

        $this->info("Declining order ID: {$orderId} for Shop ID: {$shopId} (Reason: {$reason})...");

        try {
            $res = $service->declineOrder($shopId, $orderId, $reason);
            $this->info("Successfully declined order!");
            $this->line(json_encode($res, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            $this->error("Failed to decline order: " . $e->getMessage());
        }
    }
}
