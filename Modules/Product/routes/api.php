<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\MarketplaceProductController;
use Modules\Product\Http\Controllers\MediaController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class)->names('product');

    // General Media Upload Endpoint
    Route::post('media/upload', [MediaController::class, 'upload']);
});

// Marketplace specific product routes (Temporarily Unprotected for Testing)
Route::prefix('v1/{marketplace}')->group(function () {
    Route::get('products/categories', [MarketplaceProductController::class, 'categories']);
    Route::put('products/{id}/activate', [MarketplaceProductController::class, 'activate']);
    Route::put('products/{id}/deactivate', [MarketplaceProductController::class, 'deactivate']);
    Route::put('products/{id}/stock', [MarketplaceProductController::class, 'updateStock']);
    Route::put('products/{id}/price', [MarketplaceProductController::class, 'updatePrice']);
    
    // Marketplace unified products resource
    Route::apiResource('products', MarketplaceProductController::class)->names('marketplace.product');
});
