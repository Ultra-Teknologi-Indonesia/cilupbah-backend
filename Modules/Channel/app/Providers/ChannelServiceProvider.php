<?php

namespace Modules\Channel\Providers;

use Modules\Channel\Console\Commands\SyncTikTokAttributes;
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
        SyncTikTokAttributes::class,
    ];

}
