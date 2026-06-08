<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ChannelProductController;
use Modules\Product\Http\Controllers\MediaController;
use Modules\Product\Http\Controllers\CategoryController;
use Modules\Product\Http\Controllers\BrandController;
use Modules\Product\Http\Controllers\AttributeController;
use Modules\Product\Http\Controllers\ChannelCategoryController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class)->names('product');

    // Product lifecycle transitions
    Route::post('products/{id}/approve', [ProductController::class, 'approve']);
    Route::post('products/{id}/reject', [ProductController::class, 'reject']);
    Route::post('products/{id}/archive', [ProductController::class, 'archive']);
    Route::post('products/{id}/restore', [ProductController::class, 'restore']);


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
    
    // Channel unified products resource
    Route::apiResource('products', ChannelProductController::class)->names('channel.product');
});
