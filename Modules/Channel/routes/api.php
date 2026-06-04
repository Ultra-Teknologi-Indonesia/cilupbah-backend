<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('channels', ChannelController::class)->names('channel');
});

Route::prefix('v1/tiktok')->group(function () {
    Route::get('auth', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'redirect']);
    Route::get('callback', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callback']);
    Route::get('callback-debug', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callbackDebug']);
    Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\TikTokWebController::class, 'getCancelReasons']);
    Route::post('cancel-product', [\Modules\Channel\Http\Controllers\TikTokWebController::class, 'cancelProduct']);

    Route::get('stores', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'index']);
    Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'show']);
    Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'destroy']);
    Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'refreshToken']);
});
