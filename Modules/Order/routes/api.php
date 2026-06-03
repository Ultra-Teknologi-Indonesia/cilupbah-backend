<?php

use Illuminate\Support\Facades\Route;
use Modules\Order\Http\Controllers\OrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('orders', OrderController::class)->names('order');
    
    // Unified Marketplace Orders API
    Route::get('{marketplace}/orders', [OrderController::class, 'getMarketplaceOrder']);
    Route::get('{marketplace}/orders/{order_id}', [OrderController::class, 'showMarketplaceOrder']);
    Route::post('{marketplace}/orders/{order_id}/ship', [OrderController::class, 'shipMarketplaceOrder']);
    Route::get('{marketplace}/orders/{order_id}/shipping-document', [OrderController::class, 'shippingDocumentMarketplaceOrder']);
});
