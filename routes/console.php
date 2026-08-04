<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lazada:refresh-tokens')->dailyAt('02:00')->withoutOverlapping();

// Peringatan dini "perlu otorisasi ulang" (khususnya Lazada yang putus berkala ~30 hari).
Schedule::command('channel:alert-reauth')->dailyAt('08:00')->withoutOverlapping();

Schedule::command('shopee:refresh-tokens')->hourly()->withoutOverlapping();
Schedule::command('tiktok:refresh-tokens')->hourly()->withoutOverlapping();

Schedule::command('products:poll-review-status')->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('channel-downloads:reap-stale')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('channel:webhooks-replay')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('channel:evaluate-order-sync')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('orders:sync-finance')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('settlements:sync')->dailyAt('03:30')->withoutOverlapping();

Schedule::command('returns:sync-tracking')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('returns:sync-detail')->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('raise-products:auto-raise')->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::job(new \Modules\Outbound\Jobs\RefreshInstantTrackingJob())
    ->everyThreeMinutes()
    ->withoutOverlapping();
