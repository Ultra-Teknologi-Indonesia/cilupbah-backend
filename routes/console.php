<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('shopee:refresh-tokens --hours=3')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('tiktok:refresh-tokens --hours=24')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('lazada:refresh-tokens --hours=48')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();

Schedule::command('channel:alert-reauth')->hourly()->withoutOverlapping(10)->onOneServer();

Schedule::command('products:poll-review-status')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('channel-downloads:reap-stale')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('bulk-shipping-labels:reap-stale')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('channel:monitor-download-health')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('channel:pull-orders --hours=1')->everyTwoMinutes()->withoutOverlapping(5)->onOneServer();

Schedule::command('channel:webhooks-replay --minutes=5 --limit=500')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('webhook:replay-failed --limit=100')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();

Schedule::command('channel:evaluate-order-sync')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('orders:sync-finance')->dailyAt('03:00')->withoutOverlapping()->onOneServer();
Schedule::command('settlements:sync')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

Schedule::command('returns:sync-tracking')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('returns:sync-detail')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('channel:reconcile-ingestion')->hourly()->withoutOverlapping()->onOneServer();

Schedule::command('channel:reconcile-orders')->hourlyAt(30)->withoutOverlapping()->onOneServer();

Schedule::command('raise-products:auto-raise')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::job(new \Modules\Outbound\Jobs\RefreshInstantTrackingJob())
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function () {
    @touch(storage_path('framework/scheduler-heartbeat'));
})->everyMinute()->name('scheduler-heartbeat');
