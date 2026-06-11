<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Token Lazada hanya berlaku ±7 hari — refresh harian sebelum kedaluwarsa.
Schedule::command('lazada:refresh-tokens')->dailyAt('02:00')->withoutOverlapping();

// Snapshot metrik Horizon (tab Metrics: throughput & wait time per queue/job).
// Tanpa ini tab Metrics kosong.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
