<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ChannelProductController;
use Modules\Product\Http\Controllers\MediaController;
use Modules\Product\Http\Controllers\CategoryController;
use Modules\Product\Http\Controllers\BrandController;
use Modules\Product\Http\Controllers\AttributeController;
use Modules\Product\Http\Controllers\ChannelCategoryController;
use Modules\Product\Http\Controllers\ProductChannelDraftController;
use Modules\Product\Http\Controllers\ProductSyncLogController;
use Modules\Product\Http\Controllers\ChannelMonitorController;
use Modules\Product\Http\Controllers\ProductMergeController;
use Modules\Product\Http\Controllers\MasterFeedController;
use Modules\Product\Http\Controllers\ReviewFeedController;
use Modules\Product\Http\Controllers\ArchiveFeedController;
use Modules\Product\Http\Controllers\ChannelProductListingController;
use Modules\Product\Http\Controllers\ProductPantauanController;
use Modules\Product\Http\Controllers\ProductUploadListingController;
use Modules\Product\Http\Controllers\RaiseProductController;
use Modules\Product\Http\Controllers\VariantController;
use Modules\Product\Http\Controllers\PriceListController;
use Modules\Product\Http\Controllers\CatalogController;
use Modules\Product\Http\Controllers\ProductMasterDataController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('products/master-data/sales-taxes', [ProductMasterDataController::class, 'salesTaxes']);
    Route::get('products/master-data/purchase-taxes', [ProductMasterDataController::class, 'purchaseTaxes']);
    Route::get('products/master-data/sales-accounts', [ProductMasterDataController::class, 'salesAccounts']);
    Route::get('products/master-data/sales-return-accounts', [ProductMasterDataController::class, 'salesReturnAccounts']);
    Route::get('products/master-data/inventory-accounts', [ProductMasterDataController::class, 'inventoryAccounts']);
    Route::get('products/master-data/cogs-accounts', [ProductMasterDataController::class, 'cogsAccounts']);

    Route::get('products/uploadable', [ProductController::class, 'uploadable']);
    Route::get('products/channel-drafts', [ProductChannelDraftController::class, 'list']);
    Route::post('products/channel-drafts/bulk-upload', [ProductChannelDraftController::class, 'bulkUpload']);
    Route::post('products/channel-drafts/{draft}/upload', [ProductChannelDraftController::class, 'upload'])->whereUuid('draft');

    Route::get('products/master', [MasterFeedController::class, 'index']);
    Route::get('products/master/{id}', [MasterFeedController::class, 'show'])->whereUuid('id');

    Route::get('products/reviews', [ReviewFeedController::class, 'index']);

    Route::get('products/archives', [ArchiveFeedController::class, 'index']);
    Route::get('products/archives/{id}', [ArchiveFeedController::class, 'show'])->whereUuid('id');

    Route::get('products/channel-products', [ChannelProductListingController::class, 'index']);
    Route::get('products/channel-products/{id}', [ChannelProductListingController::class, 'show'])->whereUuid('id');

    Route::get('products/pantauan', [ProductPantauanController::class, 'index']);

    Route::get('raise-products', [RaiseProductController::class, 'index']);
    Route::get('raise-products/{id}', [RaiseProductController::class, 'show'])->whereUuid('id');
    Route::post('raise-products', [RaiseProductController::class, 'store']);
    Route::post('raise-products/{id}/raise', [RaiseProductController::class, 'raise'])->whereUuid('id');
    Route::post('raise-products/{id}/products', [RaiseProductController::class, 'addProduct'])->whereUuid('id');
    Route::patch('raise-products/{id}/products/{detailId}', [RaiseProductController::class, 'updateProduct'])->whereUuid('id')->whereUuid('detailId');
    Route::delete('raise-products/{id}/products/{detailId}', [RaiseProductController::class, 'removeProduct'])->whereUuid('id')->whereUuid('detailId');
    Route::delete('raise-products/{id}', [RaiseProductController::class, 'destroy'])->whereUuid('id');

    Route::middleware('permission:view-product-merge')->group(function () {
        Route::get('products/merge/catalog', [ProductMergeController::class, 'catalog']);
        Route::get('products/merge/suggestions', [ProductMergeController::class, 'suggestions']);
        Route::get('products/merge/applied', [ProductMergeController::class, 'applied']);
    });

    Route::middleware('permission:auto-merge-product')->group(function () {
        Route::post('products/merge/auto', [ProductMergeController::class, 'auto']);
    });

    Route::middleware('permission:merge-product')->group(function () {
        Route::post('products/merge/apply', [ProductMergeController::class, 'apply']);
        Route::post('products/merge/bulk', [ProductMergeController::class, 'bulk']);
    });

    Route::middleware('permission:unmerge-product')->group(function () {
        Route::post('products/merge/bulk-unmerge', [ProductMergeController::class, 'bulkUnmerge']);

        Route::delete('products/merge/master', [ProductMergeController::class, 'unmergeMaster']);
        Route::delete('products/merge/{product}', [ProductMergeController::class, 'unmerge'])->whereUuid('product');
    });

    Route::middleware('permission:hide-product')->group(function () {
        Route::post('products/merge/hide', [ProductMergeController::class, 'hide']);
        Route::post('products/merge/unhide', [ProductMergeController::class, 'unhide']);
    });

    Route::apiResource('products', ProductController::class)->names('product')
        ->where(['product' => '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}']);

    Route::post('products/{id}/approve', [ProductController::class, 'approve'])->whereUuid('id');
    Route::post('products/{id}/archive', [ProductController::class, 'archive'])->whereUuid('id');
    Route::post('products/{id}/restore', [ProductController::class, 'restore'])->whereUuid('id');

    Route::get('products/{id}/variants', [ProductController::class, 'variants'])->whereUuid('id');
    Route::post('products/{id}/variants/bulk', [ProductController::class, 'bulkVariants'])->whereUuid('id');

    Route::get('products/{id}/channel-listings', [ProductController::class, 'channelListings'])->whereUuid('id');

    Route::get('products/{id}/upload-listing', [ProductUploadListingController::class, 'index'])->whereUuid('id');
    Route::post('products/{id}/upload-listing/match', [ProductUploadListingController::class, 'match'])->whereUuid('id');

    Route::get('products/{id}/channel-prices', [ProductController::class, 'channelPrices'])->whereUuid('id');
    Route::get('products/{id}/price-book', [ProductController::class, 'priceBook'])->whereUuid('id');

    Route::get('products/{id}/channel-drafts', [ProductChannelDraftController::class, 'index'])->whereUuid('id');
    Route::post('products/{id}/channel-drafts', [ProductChannelDraftController::class, 'store'])->whereUuid('id');
    Route::put('products/{id}/channel-drafts/{draft}', [ProductChannelDraftController::class, 'update'])->whereUuid('id')->whereUuid('draft');
    Route::delete('products/{id}/channel-drafts/{draft}', [ProductChannelDraftController::class, 'destroy'])->whereUuid('id')->whereUuid('draft');

    Route::get('upload-histories', [ProductSyncLogController::class, 'uploadHistories']);
    Route::post('upload-histories/bulk-delete', [ProductSyncLogController::class, 'bulkDestroy']);
    Route::post('upload-histories/{id}/re-upload', [ProductSyncLogController::class, 'reupload'])->whereUuid('id');
    Route::delete('upload-histories/{id}', [ProductSyncLogController::class, 'destroy'])->whereUuid('id');
    Route::get('download-histories', [ProductSyncLogController::class, 'downloadHistories']);

    Route::post('channel-monitor/refresh', [ChannelMonitorController::class, 'refresh']);
    Route::get('channel-monitor', [ChannelMonitorController::class, 'index']);
    Route::get('channel-monitor/summary', [ChannelMonitorController::class, 'summary']);
    Route::get('channel-monitor/{shop_id}', [ChannelMonitorController::class, 'detail']);
    Route::get('channel-monitor/{shop_id}/products', [ChannelMonitorController::class, 'products']);

    Route::apiResource('categories', CategoryController::class)->names('category')->where(['category' => '[0-9]+']);
    Route::post('categories/{category}/map-channel', [CategoryController::class, 'mapChannel'])->whereNumber('category');

    Route::get('categories/{category}/form-attributes', [\Modules\Product\Http\Controllers\CategoryFormAttributeController::class, 'show'])->whereNumber('category')->name('category.form-attributes');

    Route::apiResource('brands', BrandController::class)->names('brand')->where(['brand' => '[0-9]+']);
    Route::apiResource('attributes', AttributeController::class)->names('attribute')->where(['attribute' => '[0-9]+']);
    Route::post('attributes/{attribute}/map-channel', [AttributeController::class, 'mapChannel'])->whereNumber('attribute');
    Route::post('attributes/options/{option}/map-channel', [AttributeController::class, 'mapOptionChannel'])->whereNumber('option');

    Route::post('media/upload', [MediaController::class, 'upload'])->name('media.upload');
    Route::get('media/upload/{uuid}', [MediaController::class, 'show'])->whereUuid('uuid')->name('media.show');
    Route::put('media/upload/{uuid}', [MediaController::class, 'replace'])->whereUuid('uuid')->name('media.replace');
    Route::delete('media/upload/{uuid}', [MediaController::class, 'destroy'])->whereUuid('uuid')->name('media.destroy');

    Route::get('products/import/template/single', [\Modules\Product\Http\Controllers\ProductImportController::class, 'downloadSingleTemplate']);
    Route::get('products/import/template/bundle', [\Modules\Product\Http\Controllers\ProductImportController::class, 'downloadBundleTemplate']);
    Route::post('products/import/single', [\Modules\Product\Http\Controllers\ProductImportController::class, 'importSingle']);
    Route::post('products/import/bundle', [\Modules\Product\Http\Controllers\ProductImportController::class, 'importBundle']);

    Route::get('inventory/items/by-sku/{sku}', [ProductController::class, 'showBySku']);
    Route::get('inventory/item-bundles', [ProductController::class, 'bundles']);
    Route::post('inventory/items/all-stocks', [ProductController::class, 'allStocks']);
    Route::post('inventory/items/prices', [ProductController::class, 'prices']);
    Route::post('inventory/items', [ProductController::class, 'storeBundle']);

    Route::get('inventory/items/channel-category-attributes', [\Modules\Product\Http\Controllers\ChannelAttributeController::class, 'all']);

    Route::get('inventory/categories/category-map/{id}', [CategoryController::class, 'channelMap'])->whereNumber('id');
    Route::get('inventory/categories/{channel_id}/store-categories/{store_id}', [ChannelCategoryController::class, 'storeCategories']);

    Route::get('variations', [VariantController::class, 'index']);
    Route::delete('inventory/items/item-variant', [VariantController::class, 'destroy']);

    Route::get('inventory/internal-price-list', [PriceListController::class, 'index']);
    Route::post('inventory/price-list', [PriceListController::class, 'update']);

    Route::post('inventory/catalog/listing', [ProductChannelDraftController::class, 'catalogListing']);

    Route::get('inventory/catalog/for-listing/{id}', [CatalogController::class, 'forListing'])->whereUuid('id');
    Route::get('inventory/items/group/{id}', [CatalogController::class, 'forListing'])->whereUuid('id');
    Route::get('inventory/catalog/{group_id}', [CatalogController::class, 'group']);
    Route::post('inventory/catalog/upload', [ProductChannelDraftController::class, 'bulkUpload']);
    Route::get('inventory/items/errors', [ProductSyncLogController::class, 'errors']);
});

Route::middleware(['auth:sanctum'])->prefix('v1/{channel}')->group(function () {

    Route::get('categories', [ChannelCategoryController::class, 'index']);
    Route::get('categories/{categoryId}/attributes', [\Modules\Product\Http\Controllers\ChannelAttributeController::class, 'index']);

    Route::get('products/categories', [ChannelProductController::class, 'categories']);
    Route::put('products/{id}/activate', [ChannelProductController::class, 'activate']);
    Route::put('products/{id}/deactivate', [ChannelProductController::class, 'deactivate']);
    Route::put('products/{id}/stock', [ChannelProductController::class, 'updateStock']);
    Route::put('products/{id}/price', [ChannelProductController::class, 'updatePrice']);

    Route::delete('products/{id}/link', [ChannelProductController::class, 'unlink']);

    Route::apiResource('products', ChannelProductController::class)->names('channel.product');
});
