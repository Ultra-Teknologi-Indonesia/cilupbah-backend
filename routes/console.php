<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (env('TIKTOK_DEFAULT_SHOP_ID')) {
    \Illuminate\Support\Facades\Schedule::command('tiktok:pull-products ' . env('TIKTOK_DEFAULT_SHOP_ID'))
        ->hourly()
        ->appendOutputTo(storage_path('logs/tiktok-pull.log'));
}
