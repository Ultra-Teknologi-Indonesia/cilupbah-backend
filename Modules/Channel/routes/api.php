<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('marketplace/store', [ChannelController::class, 'stores']);
    Route::patch('marketplace/store/{id}', [ChannelController::class, 'updateStore'])->whereUuid('id');
    Route::delete('marketplace/store/{id}', [ChannelController::class, 'disconnectShop'])->whereUuid('id');

    Route::apiResource('channels', ChannelController::class)->names('channel');

    Route::get('download-transactions', [\Modules\Channel\Http\Controllers\DownloadTransactionController::class, 'index']);
    Route::get('download-transactions/{id}', [\Modules\Channel\Http\Controllers\DownloadTransactionController::class, 'show']);
});

Route::prefix('v1/tiktok')->group(function () {

    Route::post('webhook', [\Modules\Channel\Http\Controllers\TikTokWebhookController::class, 'handle']);
    Route::get('auth', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'redirect']);
    Route::get('callback', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callback']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'getCancelReasons']);
        Route::post('cancel-product', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'cancelProduct']);

        Route::get('stores', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'index']);
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'show'])->whereUuid('id');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'destroy'])->whereUuid('id');
        Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'refreshToken'])->whereUuid('id');

        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrdersAll']);
        Route::post('auto-sync/pull-products', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullProductsAll']);

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrders']);
        Route::post('sync/accept', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'acceptOrder']);
        Route::post('sync/decline', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'declineOrder']);
        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pushProduct']);
        Route::post('sync/products/sync', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncProduct']);
        Route::post('sync/products/bulk-push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'bulkPush']);
    });
});

Route::prefix('v1/lazada')->group(function () {
    Route::get('auth', [\Modules\Channel\Http\Controllers\LazadaAuthController::class, 'redirect'])->name('lazada.auth');
    Route::get('callback', [\Modules\Channel\Http\Controllers\LazadaAuthController::class, 'callback'])->name('lazada.callback');

    Route::post('webhook', [\Modules\Channel\Http\Controllers\LazadaWebhookController::class, 'handle'])->name('lazada.webhook');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'index'])->name('lazada.stores.index');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'show'])->whereUuid('id')->name('lazada.stores.show');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'destroy'])->whereUuid('id')->name('lazada.stores.destroy');
        Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'refreshToken'])->whereUuid('id')->name('lazada.stores.refresh');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'pullOrders'])->name('lazada.sync.pull');
        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'pullOrdersAll'])->name('lazada.sync.pull-all');

        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'pushProduct'])->name('lazada.sync.products.push');
        Route::post('sync/categories', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'syncCategories'])->name('lazada.sync.categories');
        Route::post('sync/category-attributes', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'syncCategoryAttributes'])->name('lazada.sync.category-attributes');

        Route::post('sync/pack', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'packOrder'])->name('lazada.sync.pack');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'cancelOrder'])->name('lazada.sync.cancel');
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'cancelReasons'])->name('lazada.cancel-reasons');
        Route::get('logistics', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'logistics'])->name('lazada.logistics');
    });
});

Route::middleware(['auth:sanctum'])->prefix('v1/{channel}')->group(function () {
    Route::post('download', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'download']);
    Route::post('download/bulk', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'downloadBulk']);
});
