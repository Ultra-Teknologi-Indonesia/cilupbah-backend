<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\ProductController;
use Modules\Product\Http\Controllers\ChannelProductController;
use Modules\Product\Http\Controllers\MediaController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('products', ProductController::class)->names('product');

    // General Media Upload Endpoint
    Route::post('media/upload', [MediaController::class, 'upload']);
});

// Channel specific product routes (Temporarily Unprotected for Testing)
Route::prefix('v1/{channel}')->group(function () {
    Route::get('products/categories', [ChannelProductController::class, 'categories']);
    Route::put('products/{id}/activate', [ChannelProductController::class, 'activate']);
    Route::put('products/{id}/deactivate', [ChannelProductController::class, 'deactivate']);
    Route::put('products/{id}/stock', [ChannelProductController::class, 'updateStock']);
    Route::put('products/{id}/price', [ChannelProductController::class, 'updatePrice']);
    
    // Channel unified products resource
    Route::apiResource('products', ChannelProductController::class)->names('channel.product');
});
