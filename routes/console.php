<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Outbound\Jobs\RefreshInstantTrackingJob;

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

Schedule::command('channel:pull-orders --queue --hours=1 --overlap-minutes=5')
    ->everyTwoMinutes()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command('channel:webhooks-replay --minutes=5 --limit=100 --max-seconds=30')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
Schedule::command('channel:monitor-queue-health')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

Schedule::command('channel:evaluate-order-sync')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('orders:sync-finance')->dailyAt('03:00')->withoutOverlapping()->onOneServer();

Schedule::command('orders:dispatch-due-finance')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('orders:finance-queue-health --json')->everyMinute()->withoutOverlapping(2)->onOneServer();
Schedule::command('settlements:sync')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

Schedule::command('returns:sync-tracking --limit=200')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('returns:sync-detail --limit=200')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('channel:reconcile-ingestion')->hourly()->withoutOverlapping()->onOneServer();

Schedule::command('channel:reconcile-orders')->hourlyAt(30)->withoutOverlapping()->onOneServer();

Schedule::command('raise-products:auto-raise')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('horizon:purge')->hourly()->withoutOverlapping()->onOneServer();

Schedule::job(new RefreshInstantTrackingJob)
    ->everyThreeMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::call(function () {
    @touch(storage_path('framework/scheduler-heartbeat'));
})->everyMinute()->name('scheduler-heartbeat');
