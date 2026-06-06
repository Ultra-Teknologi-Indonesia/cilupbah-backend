<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchase\Http\Controllers\PurchaseOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('purchase/orders', [PurchaseOrderController::class, 'index'])->name('purchase.orders.index');
    Route::get('purchase/orders/receivable', [PurchaseOrderController::class, 'receivable'])->name('purchase.orders.receivable');
    Route::get('purchase/orders/{id}', [PurchaseOrderController::class, 'show'])->name('purchase.orders.show');
    Route::post('purchase/orders', [PurchaseOrderController::class, 'store'])->name('purchase.orders.store');
    Route::post('purchase/orders/{id}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase.orders.approve');
    Route::post('purchase/orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase.orders.receive');
    Route::post('purchase/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase.orders.cancel');
    Route::delete('purchase/orders/{id}', [PurchaseOrderController::class, 'destroy'])->name('purchase.orders.destroy');
});
