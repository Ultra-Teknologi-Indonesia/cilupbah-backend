<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\MediaController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class)->names('product');
    
    // Unified Marketplace Products API
    Route::get('{marketplace}/products', [ProductController::class, 'getMarketplaceProduct']);
    Route::post('{marketplace}/products', [ProductController::class, 'storeMarketplaceProduct']);
    Route::get('{marketplace}/products/categories', [ProductController::class, 'getCategories']);
    Route::post('{marketplace}/products/images', [ProductController::class, 'uploadImage']);
    Route::get('{marketplace}/products/{product_id}', [ProductController::class, 'showMarketplaceProduct']);
    Route::put('{marketplace}/products/{product_id}', [ProductController::class, 'updateMarketplaceProduct']);
    Route::delete('{marketplace}/products/{product_id}', [ProductController::class, 'destroyMarketplaceProduct']);
    
    // Specific actions
    Route::put('{marketplace}/products/{product_id}/activate', [ProductController::class, 'activateMarketplaceProduct']);
    Route::put('{marketplace}/products/{product_id}/deactivate', [ProductController::class, 'deactivateMarketplaceProduct']);
    Route::put('{marketplace}/products/{product_id}/stock', [ProductController::class, 'updateStockMarketplaceProduct']);
    Route::put('{marketplace}/products/{product_id}/price', [ProductController::class, 'updatePriceMarketplaceProduct']);

    Route::post('media/upload', [MediaController::class, 'upload']);
});
