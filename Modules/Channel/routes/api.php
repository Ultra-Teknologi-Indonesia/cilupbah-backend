<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('marketplace/cancel-reasons', [\Modules\Channel\Http\Controllers\MarketplaceCancelReasonController::class, 'index'])->name('marketplace.cancel-reasons.index');
    Route::get('marketplace/cancel-reasons/{marketplace}', [\Modules\Channel\Http\Controllers\MarketplaceCancelReasonController::class, 'show'])->name('marketplace.cancel-reasons.show');

    Route::get('marketplace/stock-allocation', [ChannelController::class, 'stockAllocation']);

    Route::middleware('role_or_permission:owner|view-integrasi-channel')->group(function () {
        Route::get('marketplace/store', [ChannelController::class, 'stores']);

        Route::get('channels/print-label-capabilities', [ChannelController::class, 'printLabelCapabilities'])
            ->name('channels.print-label-capabilities');
        Route::get('channels', [ChannelController::class, 'index'])->name('channel.index');
        Route::get('channels/{channel}', [ChannelController::class, 'show'])->name('channel.show');

        Route::get('download-transactions', [\Modules\Channel\Http\Controllers\DownloadTransactionController::class, 'index']);
        Route::get('download-transactions/{id}', [\Modules\Channel\Http\Controllers\DownloadTransactionController::class, 'show']);
    });

    Route::middleware('role_or_permission:owner|create-integrasi-channel')->group(function () {
        Route::post('channels', [ChannelController::class, 'store'])->name('channel.store');
    });

    Route::middleware('role_or_permission:owner|edit-integrasi-channel')->group(function () {
        Route::match(['put', 'patch'], 'channels/{channel}', [ChannelController::class, 'update'])->name('channel.update');
        Route::patch('marketplace/store/{id}', [ChannelController::class, 'updateStore'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|delete-integrasi-channel')->group(function () {
        Route::delete('channels/{channel}', [ChannelController::class, 'destroy'])->name('channel.destroy');
        Route::delete('marketplace/store/{id}', [ChannelController::class, 'disconnectShop'])->whereUuid('id');
    });
});

Route::prefix('v1/tiktok')->group(function () {

    Route::post('webhook', [\Modules\Channel\Http\Controllers\TikTokWebhookController::class, 'handle'])->name('tiktok.webhook');
    Route::get('auth', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'redirect'])->name('tiktok.auth');
    Route::get('callback', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callback'])->name('tiktok.callback');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'cancelReasons'])->name('tiktok.cancel-reasons');

        Route::get('stores', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'index'])->name('tiktok.stores.index');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'show'])->whereUuid('id')->name('tiktok.stores.show');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'destroy'])->whereUuid('id')->name('tiktok.stores.destroy');
        Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'refreshToken'])->whereUuid('id')->name('tiktok.stores.refresh');

        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrdersAll'])->name('tiktok.sync.pull-all');
        Route::post('auto-sync/pull-products', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullProductsAll'])->name('tiktok.sync.pull-products');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrders'])->name('tiktok.sync.pull');
        Route::post('sync/accept', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'acceptOrder'])->name('tiktok.sync.accept');
        Route::post('sync/decline', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'declineOrder'])->name('tiktok.sync.decline');
        Route::post('sync/ship', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'shipOrder'])->name('tiktok.sync.ship');
        Route::match(['get', 'post'], 'sync/awb', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'airwayBill'])->name('tiktok.sync.awb');
        Route::get('sync/packages', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'packages'])->name('tiktok.sync.packages');
        Route::post('sync/handle-buyer-cancel', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'handleBuyerCancel'])->name('tiktok.sync.handle-buyer-cancel');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'cancelOrder'])->name('tiktok.sync.cancel');
        Route::post('sync/categories', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncCategories'])->name('tiktok.sync.categories');
        Route::post('sync/category-attributes', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncCategoryAttributes'])->name('tiktok.sync.category-attributes');
        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pushProduct'])->name('tiktok.sync.products.push');
        Route::post('sync/products/sync', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncProduct'])->name('tiktok.sync.products.sync');
        Route::post('sync/products/bulk-push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'bulkPush'])->name('tiktok.sync.products.bulk-push');
    });
});

Route::prefix('v1/lazada')->group(function () {
    Route::get('auth', [\Modules\Channel\Http\Controllers\LazadaAuthController::class, 'redirect'])->name('lazada.auth');
    Route::get('callback', [\Modules\Channel\Http\Controllers\LazadaAuthController::class, 'callback'])->name('lazada.callback');

    Route::get('webhook', [\Modules\Channel\Http\Controllers\LazadaWebhookController::class, 'verify'])->name('lazada.webhook.verify');
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
        Route::post('listing/validate', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'validateListing'])->name('lazada.listing.validate');

        Route::post('sync/fulfill-pack', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'fulfillPack'])->name('lazada.sync.fulfill-pack');
        Route::get('sync/awb', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'printAwb'])->name('lazada.sync.awb');
        Route::post('sync/rts', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'readyToShip'])->name('lazada.sync.rts');
        Route::post('sync/fulfill', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'processFulfillment'])->name('lazada.sync.fulfill');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'cancelOrder'])->name('lazada.sync.cancel');
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'cancelReasons'])->name('lazada.cancel-reasons');
        Route::get('logistics', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'logistics'])->name('lazada.logistics');
    });
});

Route::prefix('v1/shopee')->group(function () {
    Route::get('auth', [\Modules\Channel\Http\Controllers\ShopeeAuthController::class, 'redirect'])->name('shopee.auth');
    Route::get('callback', [\Modules\Channel\Http\Controllers\ShopeeAuthController::class, 'callback'])->name('shopee.callback');

    Route::post('callback', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'handle'])->name('shopee.callback.push');

    Route::get('webhook', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'verify'])->name('shopee.webhook.verify');
    Route::post('webhook', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'handle'])->name('shopee.webhook');
    Route::get('push-debug', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'debug'])->name('shopee.push-debug');
    Route::match(['get', 'post'], 'push-ping', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'ping'])->name('shopee.push-ping');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'index'])->name('shopee.stores.index');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'show'])->whereUuid('id')->name('shopee.stores.show');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'destroy'])->whereUuid('id')->name('shopee.stores.destroy');
        Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'refreshToken'])->whereUuid('id')->name('shopee.stores.refresh');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'pullOrders'])->name('shopee.sync.pull');
        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'pullOrdersAll'])->name('shopee.sync.pull-all');

        Route::post('sync/ship', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'shipOrder'])->name('shopee.sync.ship');
        Route::post('sync/mass-ship', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'massShipOrder'])->name('shopee.sync.mass-ship');
        Route::match(['get', 'post'], 'sync/awb', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'airwayBill'])->name('shopee.sync.awb');
        Route::get('sync/packages', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'packages'])->name('shopee.sync.packages');
        Route::post('sync/update-shipping', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'updateShipping'])->name('shopee.sync.update-shipping');
        Route::post('sync/handle-buyer-cancel', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'handleBuyerCancel'])->name('shopee.sync.handle-buyer-cancel');
        Route::post('sync/split', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'splitOrder'])->name('shopee.sync.split');
        Route::post('sync/unsplit', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'unsplitOrder'])->name('shopee.sync.unsplit');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'cancelOrder'])->name('shopee.sync.cancel');
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'cancelReasons'])->name('shopee.cancel-reasons');
        Route::get('logistics', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'logistics'])->name('shopee.logistics');

        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'pushProduct'])->name('shopee.sync.products.push');
        Route::post('sync/categories', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'syncCategories'])->name('shopee.sync.categories');
        Route::post('sync/category-attributes', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'syncCategoryAttributes'])->name('shopee.sync.category-attributes');
        Route::get('products/{item}/models', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'getModels'])->name('shopee.products.models');
    });
});

Route::prefix('v1/woocommerce')->group(function () {

    Route::post('callback', [\Modules\Channel\Http\Controllers\WooCommerceAuthController::class, 'callback'])->name('woocommerce.callback');
    Route::get('webhook', [\Modules\Channel\Http\Controllers\WooCommerceWebhookController::class, 'verify'])->name('woocommerce.webhook.verify');
    Route::post('webhook', [\Modules\Channel\Http\Controllers\WooCommerceWebhookController::class, 'handle'])->name('woocommerce.webhook');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth', [\Modules\Channel\Http\Controllers\WooCommerceAuthController::class, 'redirect'])->name('woocommerce.auth');
        Route::post('connect', [\Modules\Channel\Http\Controllers\WooCommerceAuthController::class, 'connect'])->name('woocommerce.connect');

        Route::get('stores', [\Modules\Channel\Http\Controllers\WooCommerceStoreController::class, 'index'])->name('woocommerce.stores.index');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\WooCommerceStoreController::class, 'show'])->whereUuid('id')->name('woocommerce.stores.show');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\WooCommerceStoreController::class, 'destroy'])->whereUuid('id')->name('woocommerce.stores.destroy');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'pullOrders'])->name('woocommerce.sync.pull');
        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'pullOrdersAll'])->name('woocommerce.sync.pull-all');
        Route::post('sync/ship', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'shipOrder'])->name('woocommerce.sync.ship');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'cancelOrder'])->name('woocommerce.sync.cancel');
        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'pushProduct'])->name('woocommerce.sync.products.push');
    });
});

Route::middleware(['auth:sanctum'])->prefix('v1/{channel}')->group(function () {
    Route::post('download', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'download']);
    Route::post('download/bulk', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'downloadBulk']);
    Route::get('download/search', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'search']);

    Route::post('download-product', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'downloadProduct']);
});
