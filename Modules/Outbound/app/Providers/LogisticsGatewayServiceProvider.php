<?php

namespace Modules\Outbound\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Outbound\Services\Logistics\LazadaLogisticsService;
use Modules\Outbound\Services\Logistics\LogisticsGateway;
use Modules\Outbound\Services\Logistics\ShopeeLogisticsService;
use Modules\Outbound\Services\Logistics\TikTokLogisticsService;
use Modules\Outbound\Services\Logistics\WooCommerceLogisticsService;

class LogisticsGatewayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LogisticsGateway::class, function () {
            return new LogisticsGateway([
                'shopee' => ShopeeLogisticsService::class,
                'tiktok' => TikTokLogisticsService::class,
                'lazada' => LazadaLogisticsService::class,
                'woocommerce' => WooCommerceLogisticsService::class,
                'default' => WooCommerceLogisticsService::class,
            ]);
        });
    }
}
