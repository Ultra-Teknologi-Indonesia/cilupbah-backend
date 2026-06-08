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

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Harus didefinisikan sebelum apiResource agar tidak tertangkap products/{id}
    Route::get('products/uploadable', [ProductController::class, 'uploadable']);
    Route::get('products/channel-drafts', [ProductChannelDraftController::class, 'list']);

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
    Route::get('download-histories', [ProductSyncLogController::class, 'downloadHistories']);

    // Pantauan — monitoring agregat status sync per channel
    Route::get('channel-monitor', [ChannelMonitorController::class, 'summary']);
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
