<?php

namespace Modules\Channel\Providers;

use Modules\Channel\Console\Commands\EvaluateOrderSyncHealth;
use Modules\Channel\Console\Commands\ReapStaleDownloadTransactions;
use Modules\Channel\Console\Commands\SyncTikTokAttributes;
use Modules\Channel\Models\ChannelShop;
use Modules\Channel\Observers\ChannelShopObserver;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class ChannelServiceProvider extends ModuleServiceProvider
{

    protected string $name = 'Channel';

    protected string $nameLower = 'channel';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected array $commands = [
        EvaluateOrderSyncHealth::class,
        ReapStaleDownloadTransactions::class,
        SyncTikTokAttributes::class,
    ];

    public function boot(): void
    {
        parent::boot();
        ChannelShop::observe(ChannelShopObserver::class);
    }
}
