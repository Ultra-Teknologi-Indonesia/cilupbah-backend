<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('channels', ChannelController::class)->names('channel');
});

Route::prefix('tiktok')->group(function () {
    Route::get('auth', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'redirect']);
    Route::get('callback', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callback']);
});
