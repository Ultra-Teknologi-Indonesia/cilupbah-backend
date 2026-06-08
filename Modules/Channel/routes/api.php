<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('channels', ChannelController::class)->names('channel');
});

Route::prefix('v1/tiktok')->group(function () {
    Route::post('webhook', [\Modules\Channel\Http\Controllers\TikTokWebhookController::class, 'handle']);
    
    Route::get('auth', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'redirect']);
    Route::get('callback', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callback']);
    Route::get('callback-debug', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callbackDebug']);
    Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'getCancelReasons']);
    Route::post('cancel-product', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'cancelProduct']);

    Route::get('stores', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'index']);
    Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'show']);
    Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'destroy']);
    Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'refreshToken']);
    
    Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrdersAll']);
    Route::post('auto-sync/pull-products', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullProductsAll']);
    
    Route::post('sync/pull', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrders']);
    Route::post('sync/accept', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'acceptOrder']);
    Route::post('sync/decline', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'declineOrder']);
    Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pushProduct']);
    Route::post('sync/products/sync', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncProduct']);
    Route::post('sync/products/bulk-push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'bulkPush']);
});

// Generic per-channel download (generalisasi pull, tidak terikat TikTok)
Route::middleware(['auth:sanctum'])->prefix('v1/{channel}')->group(function () {
    Route::post('download', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'download']);
    Route::post('download/bulk', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'downloadBulk']);
});
