<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('channels', ChannelController::class)->names('channel');
    
    Route::prefix('auth')->group(function () {
        Route::get('authorize', [\Modules\Channel\Http\Controllers\UnifiedAuthController::class, 'authorizeMarketplace']);
        Route::post('callback', [\Modules\Channel\Http\Controllers\UnifiedAuthController::class, 'callback']);
        Route::post('refresh', [\Modules\Channel\Http\Controllers\UnifiedAuthController::class, 'refresh']);
        Route::post('revoke', [\Modules\Channel\Http\Controllers\UnifiedAuthController::class, 'revoke']);
        Route::get('shops', [\Modules\Channel\Http\Controllers\UnifiedAuthController::class, 'shops']);
    });
});
