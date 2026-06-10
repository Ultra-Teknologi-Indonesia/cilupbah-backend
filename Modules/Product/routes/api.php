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
use Modules\Product\Http\Controllers\RaiseProductController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Harus didefinisikan sebelum apiResource agar tidak tertangkap products/{id}
    Route::get('products/uploadable', [ProductController::class, 'uploadable']);
    Route::get('products/channel-drafts', [ProductChannelDraftController::class, 'list']);
    Route::post('products/channel-drafts/bulk-upload', [ProductChannelDraftController::class, 'bulkUpload']);
    Route::post('products/channel-drafts/{draft}/upload', [ProductChannelDraftController::class, 'upload']);

    Route::get('products/master', [MasterFeedController::class, 'index']);
    Route::get('products/master/{id}', [MasterFeedController::class, 'show']);

    Route::get('products/reviews', [ReviewFeedController::class, 'index']);

    Route::get('products/archives', [ArchiveFeedController::class, 'index']);
    Route::get('products/archives/{id}', [ArchiveFeedController::class, 'show']);

    Route::get('products/channel-products', [ChannelProductListingController::class, 'index']);
    Route::get('products/channel-products/{id}', [ChannelProductListingController::class, 'show']);

    Route::get('raise-products', [RaiseProductController::class, 'index']);
    Route::get('raise-products/{id}', [RaiseProductController::class, 'show']);
    Route::post('raise-products', [RaiseProductController::class, 'store']);
    Route::post('raise-products/{id}/raise', [RaiseProductController::class, 'raise']);
    Route::post('raise-products/{id}/products', [RaiseProductController::class, 'addProduct']);
    Route::patch('raise-products/{id}/products/{detailId}', [RaiseProductController::class, 'updateProduct']);
    Route::delete('raise-products/{id}/products/{detailId}', [RaiseProductController::class, 'removeProduct']);
    Route::delete('raise-products/{id}', [RaiseProductController::class, 'destroy']);

    // ── Merge & Auto-Merge produk (lintas store & channel) ──
    // Akses berbasis Spatie Permission: selama user punya permission-nya, boleh.
    // Role `owner` otomatis lolos via Gate::before (AppServiceProvider).
    // Permission di-seed oleh ProductPermissionSeeder.
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
        // "master" harus didefinisikan sebelum "{product}" agar tidak tertangkap param
        Route::delete('products/merge/master', [ProductMergeController::class, 'unmergeMaster']);
        Route::delete('products/merge/{product}', [ProductMergeController::class, 'unmerge']);
    });

    Route::middleware('permission:hide-product')->group(function () {
        Route::post('products/merge/hide', [ProductMergeController::class, 'hide']);
        Route::post('products/merge/unhide', [ProductMergeController::class, 'unhide']);
    });

    Route::apiResource('products', ProductController::class)->names('product');

    // Product lifecycle transitions
    Route::post('products/{id}/submit-review', [ProductController::class, 'submitForReview']);
    Route::post('products/{id}/approve', [ProductController::class, 'approve']);
    Route::post('products/{id}/reject', [ProductController::class, 'reject']);
    Route::post('products/{id}/archive', [ProductController::class, 'archive']);
    Route::post('products/{id}/restore', [ProductController::class, 'restore']);

    // Channel listing drafts (sub-tab Draft)
    Route::get('products/{id}/channel-drafts', [ProductChannelDraftController::class, 'index']);
    Route::post('products/{id}/channel-drafts', [ProductChannelDraftController::class, 'store']);
    Route::put('products/{id}/channel-drafts/{draft}', [ProductChannelDraftController::class, 'update']);
    Route::delete('products/{id}/channel-drafts/{draft}', [ProductChannelDraftController::class, 'destroy']);

    // Riwayat upload & download
    Route::get('upload-histories', [ProductSyncLogController::class, 'uploadHistories']);
    Route::post('upload-histories/bulk-delete', [ProductSyncLogController::class, 'bulkDestroy']);
    Route::post('upload-histories/{id}/re-upload', [ProductSyncLogController::class, 'reupload']);
    Route::delete('upload-histories/{id}', [ProductSyncLogController::class, 'destroy']);
    Route::get('download-histories', [ProductSyncLogController::class, 'downloadHistories']);

    // Pantauan — monitoring status sync produk di channel
    Route::get('channel-monitor', [ChannelMonitorController::class, 'index']);
    Route::get('channel-monitor/summary', [ChannelMonitorController::class, 'summary']);
    Route::get('channel-monitor/{shop_id}', [ChannelMonitorController::class, 'detail']);
    Route::get('channel-monitor/{shop_id}/products', [ChannelMonitorController::class, 'products']);


    // Master Data
    Route::apiResource('categories', CategoryController::class)->names('category');
    Route::post('categories/{category}/map-channel', [CategoryController::class, 'mapChannel']);
    
    Route::apiResource('brands', BrandController::class)->names('brand');
    Route::apiResource('attributes', AttributeController::class)->names('attribute');
    Route::post('attributes/{attribute}/map-channel', [AttributeController::class, 'mapChannel']);
    Route::post('attributes/options/{option}/map-channel', [AttributeController::class, 'mapOptionChannel']);

    // General Media Upload Endpoint
    Route::post('media/upload', [MediaController::class, 'upload']);

    // Import Endpoints
    Route::get('products/import/template/single', [\Modules\Product\Http\Controllers\ProductImportController::class, 'downloadSingleTemplate']);
    Route::get('products/import/template/bundle', [\Modules\Product\Http\Controllers\ProductImportController::class, 'downloadBundleTemplate']);
    Route::post('products/import/single', [\Modules\Product\Http\Controllers\ProductImportController::class, 'importSingle']);
    Route::post('products/import/bundle', [\Modules\Product\Http\Controllers\ProductImportController::class, 'importBundle']);

    // ── Jubelio compatibility (Product/Inventory items & categories) ──
    // Item & bundle
    Route::get('inventory/items/by-sku/{sku}', [ProductController::class, 'showBySku']);
    Route::get('inventory/item-bundles', [ProductController::class, 'bundles']);
    Route::post('inventory/items/all-stocks', [ProductController::class, 'allStocks']);
    Route::post('inventory/items/prices', [ProductController::class, 'prices']);
    Route::post('inventory/items', [ProductController::class, 'storeBundle']);
    // Channel attributes (global)
    Route::get('inventory/items/channel-category-attributes', [\Modules\Product\Http\Controllers\ChannelAttributeController::class, 'all']);
    // Kategori → channel mapping & store categories
    Route::get('inventory/categories/category-map/{id}', [CategoryController::class, 'channelMap']);
    Route::get('inventory/categories/{channel_id}/store-categories/{store_id}', [ChannelCategoryController::class, 'storeCategories']);
});

// Channel specific routes
Route::middleware(['auth:sanctum'])->prefix('v1/{channel}')->group(function () {
    // Channel Categories
    Route::get('categories', [ChannelCategoryController::class, 'index']);
    Route::get('categories/{categoryId}/attributes', [\Modules\Product\Http\Controllers\ChannelAttributeController::class, 'index']);

    // Channel Products
    Route::get('products/categories', [ChannelProductController::class, 'categories']);
    Route::put('products/{id}/activate', [ChannelProductController::class, 'activate']);
    Route::put('products/{id}/deactivate', [ChannelProductController::class, 'deactivate']);
    Route::put('products/{id}/stock', [ChannelProductController::class, 'updateStock']);
    Route::put('products/{id}/price', [ChannelProductController::class, 'updatePrice']);

    // Putus koneksi produk dari 1 channel (tanpa menghapus produk lokal)
    Route::delete('products/{id}/link', [ChannelProductController::class, 'unlink']);

    // Channel unified products resource
    Route::apiResource('products', ChannelProductController::class)->names('channel.product');
});
