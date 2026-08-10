<?php

use Illuminate\Support\Facades\Route;
use Modules\Channel\Http\Controllers\ChannelController;
use Modules\Channel\Http\Controllers\ProductStockSyncController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::post('products/sync-stock', [ProductStockSyncController::class, 'syncStock'])
        ->name('products.sync-stock')
        ->middleware('role_or_permission:owner|edit-produk');

    Route::get('marketplace/cancel-reasons', [\Modules\Channel\Http\Controllers\MarketplaceCancelReasonController::class, 'index'])->name('marketplace.cancel-reasons.index')->middleware('role_or_permission:owner|view-pesanan');
    Route::get('marketplace/cancel-reasons/{marketplace}', [\Modules\Channel\Http\Controllers\MarketplaceCancelReasonController::class, 'show'])->name('marketplace.cancel-reasons.show')->middleware('role_or_permission:owner|view-pesanan');

    Route::get('marketplace/stock-allocation', [ChannelController::class, 'stockAllocation'])->middleware('role_or_permission:owner|view-posisi-stok');

    Route::get('channel-sync-setting', [\Modules\Channel\Http\Controllers\ChannelSyncSettingController::class, 'show'])
        ->name('channel-sync-setting.show')
        ->middleware('role_or_permission:owner|view-integrasi-channel');
    Route::put('channel-sync-setting', [\Modules\Channel\Http\Controllers\ChannelSyncSettingController::class, 'update'])
        ->name('channel-sync-setting.update')
        ->middleware('role_or_permission:owner|edit-integrasi-channel');

    Route::middleware('role_or_permission:owner|view-integrasi-channel')->group(function () {
        Route::get('marketplace/store', [ChannelController::class, 'stores']);

        Route::get('channels/print-label-capabilities', [ChannelController::class, 'printLabelCapabilities'])
            ->name('channels.print-label-capabilities');
        Route::get('channels', [ChannelController::class, 'index'])->name('channel.index');

        Route::get('download-transactions', [\Modules\Channel\Http\Controllers\DownloadTransactionController::class, 'index']);
        Route::get('download-transactions/{id}', [\Modules\Channel\Http\Controllers\DownloadTransactionController::class, 'show']);
        Route::get('download-transactions/{id}/failures', [\Modules\Channel\Http\Controllers\DownloadTransactionController::class, 'failures']);
    });

    Route::middleware('role_or_permission:owner|edit-integrasi-channel')->group(function () {
        Route::patch('marketplace/store/{id}', [ChannelController::class, 'updateStore'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|delete-integrasi-channel')->group(function () {
        Route::delete('marketplace/store/{id}', [ChannelController::class, 'disconnectShop'])->whereUuid('id');
    });
});

Route::prefix('v1/tiktok')->group(function () {

    Route::post('webhook', [\Modules\Channel\Http\Controllers\TikTokWebhookController::class, 'handle'])->name('tiktok.webhook')->middleware('throttle:1200,1');
    Route::get('auth', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'redirect'])->name('tiktok.auth');
    Route::get('callback', [\Modules\Channel\Http\Controllers\TikTokAuthController::class, 'callback'])->name('tiktok.callback');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'cancelReasons'])->name('tiktok.cancel-reasons')->middleware('role_or_permission:owner|view-pesanan');

        Route::get('stores', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'index'])->name('tiktok.stores.index')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'show'])->whereUuid('id')->name('tiktok.stores.show')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'destroy'])->whereUuid('id')->name('tiktok.stores.destroy')->middleware('role_or_permission:owner|delete-integrasi-channel');
        Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\TikTokStoreController::class, 'refreshToken'])->whereUuid('id')->name('tiktok.stores.refresh')->middleware('role_or_permission:owner|edit-integrasi-channel');

        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrdersAll'])->name('tiktok.sync.pull-all')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('auto-sync/pull-products', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullProductsAll'])->name('tiktok.sync.pull-products')->middleware('role_or_permission:owner|edit-produk');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pullOrders'])->name('tiktok.sync.pull')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/accept', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'acceptOrder'])->name('tiktok.sync.accept')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/decline', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'declineOrder'])->name('tiktok.sync.decline')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/ship', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'shipOrder'])->name('tiktok.sync.ship')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::match(['get', 'post'], 'sync/awb', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'airwayBill'])->name('tiktok.sync.awb')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::get('sync/packages', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'packages'])->name('tiktok.sync.packages')->middleware('role_or_permission:owner|view-pesanan');
        Route::post('sync/handle-buyer-cancel', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'handleBuyerCancel'])->name('tiktok.sync.handle-buyer-cancel')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'cancelOrder'])->name('tiktok.sync.cancel')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/categories', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncCategories'])->name('tiktok.sync.categories')->middleware('role_or_permission:owner|edit-kategori');
        Route::post('sync/category-attributes', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncCategoryAttributes'])->name('tiktok.sync.category-attributes')->middleware('role_or_permission:owner|edit-kategori');
        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'pushProduct'])->name('tiktok.sync.products.push')->middleware('role_or_permission:owner|edit-produk');
        Route::post('sync/products/sync', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'syncProduct'])->name('tiktok.sync.products.sync')->middleware('role_or_permission:owner|edit-produk');
        Route::post('sync/products/bulk-push', [\Modules\Channel\Http\Controllers\TikTokSyncApiController::class, 'bulkPush'])->name('tiktok.sync.products.bulk-push')->middleware('role_or_permission:owner|edit-produk');
    });
});

Route::prefix('v1/lazada')->group(function () {
    Route::get('auth', [\Modules\Channel\Http\Controllers\LazadaAuthController::class, 'redirect'])->name('lazada.auth');
    Route::get('callback', [\Modules\Channel\Http\Controllers\LazadaAuthController::class, 'callback'])->name('lazada.callback');

    Route::get('webhook', [\Modules\Channel\Http\Controllers\LazadaWebhookController::class, 'verify'])->name('lazada.webhook.verify')->middleware('throttle:120,1');
    Route::post('webhook', [\Modules\Channel\Http\Controllers\LazadaWebhookController::class, 'handle'])->name('lazada.webhook')->middleware('throttle:1200,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'index'])->name('lazada.stores.index')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'show'])->whereUuid('id')->name('lazada.stores.show')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'destroy'])->whereUuid('id')->name('lazada.stores.destroy')->middleware('role_or_permission:owner|delete-integrasi-channel');
        Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\LazadaStoreController::class, 'refreshToken'])->whereUuid('id')->name('lazada.stores.refresh')->middleware('role_or_permission:owner|edit-integrasi-channel');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'pullOrders'])->name('lazada.sync.pull')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'pullOrdersAll'])->name('lazada.sync.pull-all')->middleware('role_or_permission:owner|edit-pesanan');

        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'pushProduct'])->name('lazada.sync.products.push')->middleware('role_or_permission:owner|edit-produk');
        Route::post('sync/categories', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'syncCategories'])->name('lazada.sync.categories')->middleware('role_or_permission:owner|edit-kategori');
        Route::post('sync/category-attributes', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'syncCategoryAttributes'])->name('lazada.sync.category-attributes')->middleware('role_or_permission:owner|edit-kategori');
        Route::post('listing/validate', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'validateListing'])->name('lazada.listing.validate')->middleware('role_or_permission:owner|edit-produk-naik');

        Route::post('sync/fulfill-pack', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'fulfillPack'])->name('lazada.sync.fulfill-pack')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::get('sync/awb', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'printAwb'])->name('lazada.sync.awb')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/rts', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'readyToShip'])->name('lazada.sync.rts')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/fulfill', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'processFulfillment'])->name('lazada.sync.fulfill')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'cancelOrder'])->name('lazada.sync.cancel')->middleware('role_or_permission:owner|edit-pesanan');
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'cancelReasons'])->name('lazada.cancel-reasons')->middleware('role_or_permission:owner|view-pesanan');
        Route::get('logistics', [\Modules\Channel\Http\Controllers\LazadaSyncApiController::class, 'logistics'])->name('lazada.logistics')->middleware('role_or_permission:owner|view-pengiriman');
    });
});

Route::prefix('v1/shopee')->group(function () {
    Route::get('auth', [\Modules\Channel\Http\Controllers\ShopeeAuthController::class, 'redirect'])->name('shopee.auth');
    Route::get('callback', [\Modules\Channel\Http\Controllers\ShopeeAuthController::class, 'callback'])->name('shopee.callback');

    Route::post('callback', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'handle'])->name('shopee.callback.push')->middleware('throttle:1200,1');

    Route::get('webhook', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'verify'])->name('shopee.webhook.verify')->middleware('throttle:120,1');
    Route::post('webhook', [\Modules\Channel\Http\Controllers\ShopeeWebhookController::class, 'handle'])->name('shopee.webhook')->middleware('throttle:1200,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('stores', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'index'])->name('shopee.stores.index')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'show'])->whereUuid('id')->name('shopee.stores.show')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'destroy'])->whereUuid('id')->name('shopee.stores.destroy')->middleware('role_or_permission:owner|delete-integrasi-channel');
        Route::post('stores/{id}/refresh-token', [\Modules\Channel\Http\Controllers\ShopeeStoreController::class, 'refreshToken'])->whereUuid('id')->name('shopee.stores.refresh')->middleware('role_or_permission:owner|edit-integrasi-channel');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'pullOrders'])->name('shopee.sync.pull')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'pullOrdersAll'])->name('shopee.sync.pull-all')->middleware('role_or_permission:owner|edit-pesanan');

        Route::post('sync/ship', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'shipOrder'])->name('shopee.sync.ship')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/mass-ship', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'massShipOrder'])->name('shopee.sync.mass-ship')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::match(['get', 'post'], 'sync/awb', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'airwayBill'])->name('shopee.sync.awb')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::get('sync/packages', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'packages'])->name('shopee.sync.packages')->middleware('role_or_permission:owner|view-pesanan');
        Route::post('sync/update-shipping', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'updateShipping'])->name('shopee.sync.update-shipping')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/handle-buyer-cancel', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'handleBuyerCancel'])->name('shopee.sync.handle-buyer-cancel')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/split', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'splitOrder'])->name('shopee.sync.split')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/unsplit', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'unsplitOrder'])->name('shopee.sync.unsplit')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'cancelOrder'])->name('shopee.sync.cancel')->middleware('role_or_permission:owner|edit-pesanan');
        Route::get('cancel-reasons', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'cancelReasons'])->name('shopee.cancel-reasons')->middleware('role_or_permission:owner|view-pesanan');
        Route::get('logistics', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'logistics'])->name('shopee.logistics')->middleware('role_or_permission:owner|view-pengiriman');

        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'pushProduct'])->name('shopee.sync.products.push')->middleware('role_or_permission:owner|edit-produk');
        Route::post('sync/categories', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'syncCategories'])->name('shopee.sync.categories')->middleware('role_or_permission:owner|edit-kategori');
        Route::post('sync/category-attributes', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'syncCategoryAttributes'])->name('shopee.sync.category-attributes')->middleware('role_or_permission:owner|edit-kategori');
        Route::get('products/{item}/models', [\Modules\Channel\Http\Controllers\ShopeeSyncApiController::class, 'getModels'])->name('shopee.products.models')->middleware('role_or_permission:owner|view-produk');
    });
});

Route::prefix('v1/woocommerce')->group(function () {

    Route::post('callback', [\Modules\Channel\Http\Controllers\WooCommerceAuthController::class, 'callback'])->name('woocommerce.callback');
    Route::get('webhook', [\Modules\Channel\Http\Controllers\WooCommerceWebhookController::class, 'verify'])->name('woocommerce.webhook.verify')->middleware('throttle:120,1');
    Route::post('webhook', [\Modules\Channel\Http\Controllers\WooCommerceWebhookController::class, 'handle'])->name('woocommerce.webhook')->middleware('throttle:1200,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth', [\Modules\Channel\Http\Controllers\WooCommerceAuthController::class, 'redirect'])->name('woocommerce.auth')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::post('connect', [\Modules\Channel\Http\Controllers\WooCommerceAuthController::class, 'connect'])->name('woocommerce.connect')->middleware('role_or_permission:owner|create-integrasi-channel');

        Route::get('stores', [\Modules\Channel\Http\Controllers\WooCommerceStoreController::class, 'index'])->name('woocommerce.stores.index')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::get('stores/{id}', [\Modules\Channel\Http\Controllers\WooCommerceStoreController::class, 'show'])->whereUuid('id')->name('woocommerce.stores.show')->middleware('role_or_permission:owner|view-integrasi-channel');
        Route::delete('stores/{id}', [\Modules\Channel\Http\Controllers\WooCommerceStoreController::class, 'destroy'])->whereUuid('id')->name('woocommerce.stores.destroy')->middleware('role_or_permission:owner|delete-integrasi-channel');

        Route::post('sync/pull', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'pullOrders'])->name('woocommerce.sync.pull')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('auto-sync/pull-orders', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'pullOrdersAll'])->name('woocommerce.sync.pull-all')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/ship', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'shipOrder'])->name('woocommerce.sync.ship')->middleware('role_or_permission:owner|edit-pengiriman');
        Route::post('sync/cancel', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'cancelOrder'])->name('woocommerce.sync.cancel')->middleware('role_or_permission:owner|edit-pesanan');
        Route::post('sync/products/push', [\Modules\Channel\Http\Controllers\WooCommerceSyncApiController::class, 'pushProduct'])->name('woocommerce.sync.products.push')->middleware('role_or_permission:owner|edit-produk');
    });
});

Route::middleware(['auth:sanctum'])->prefix('v1/{channel}')->group(function () {
    Route::post('download', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'download'])->middleware('role_or_permission:owner|edit-pesanan');
    Route::post('download/bulk', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'downloadBulk'])->middleware('role_or_permission:owner|edit-pesanan');
    Route::get('download/search', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'search'])->middleware('role_or_permission:owner|view-pesanan');

    Route::post('download-product', [\Modules\Channel\Http\Controllers\ChannelDownloadController::class, 'downloadProduct'])->middleware('role_or_permission:owner|edit-produk');
});
