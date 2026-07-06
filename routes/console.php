<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('lazada:refresh-tokens')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('tiktok:refresh-tokens')->dailyAt('02:30')->withoutOverlapping();

Schedule::command('shopee:refresh-tokens')->hourly()->withoutOverlapping();

Schedule::command('products:poll-review-status')->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('orders:sync-finance')->dailyAt('03:00')->withoutOverlapping();

Schedule::command('returns:sync-tracking')->everyThirtyMinutes()->withoutOverlapping();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
