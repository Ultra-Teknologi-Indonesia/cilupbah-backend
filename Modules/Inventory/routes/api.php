<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\InventoryTransactionController;
use Modules\Inventory\Http\Controllers\StockAdjustmentController;
use Modules\Inventory\Http\Controllers\ReservedStockController;
use Modules\Inventory\Http\Controllers\PutawayController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('inventory/stocks', [InventoryController::class, 'index'])->name('inventory.stocks.index');
    Route::get('inventory/stocks/{itemId}', [InventoryController::class, 'show'])->name('inventory.stocks.show');
    Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements.index');

    // Inventory enhancements
    Route::get('inventory/items/to-stock', [InventoryController::class, 'itemsToStock'])->name('inventory.items.toStock');
    Route::get('inventory/stock-products', [InventoryController::class, 'stockProducts'])->name('inventory.stockProducts');
    Route::get('inventory/history', [InventoryController::class, 'history'])->name('inventory.history');
    Route::get('inventory/items/by-location/{locationId}', [InventoryController::class, 'byLocation'])->name('inventory.items.byLocation');
    Route::get('inventory/purchase-order/items', [InventoryController::class, 'purchaseOrderItems'])->name('inventory.purchaseOrder.items');

    // Direct adjustment (legacy)
    Route::post('inventory/adjustments', [InventoryTransactionController::class, 'adjust'])->name('inventory.adjust');
    Route::post('inventory/putaway', [InventoryTransactionController::class, 'putaway'])->name('inventory.putaway');

    // Document-based Stock Adjustment
    Route::prefix('inventory/adjustments/documents')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index'])->name('inventory.adjustments.documents.index');
        Route::post('/', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.documents.store');
        Route::get('/{id}', [StockAdjustmentController::class, 'show'])->name('inventory.adjustments.documents.show');
        Route::post('/{id}/approve', [StockAdjustmentController::class, 'approve'])->name('inventory.adjustments.documents.approve');
        Route::post('/{id}/cancel', [StockAdjustmentController::class, 'cancel'])->name('inventory.adjustments.documents.cancel');
        Route::delete('/{id}', [StockAdjustmentController::class, 'destroy'])->name('inventory.adjustments.documents.destroy');
    });

    // Document-based Reserved Stock
    Route::prefix('inventory/reserved-stocks')->group(function () {
        Route::get('/', [ReservedStockController::class, 'index'])->name('inventory.reservedStocks.index');
        Route::post('/', [ReservedStockController::class, 'store'])->name('inventory.reservedStocks.store');
        Route::get('/{id}', [ReservedStockController::class, 'show'])->name('inventory.reservedStocks.show');
        Route::post('/{id}/cancel', [ReservedStockController::class, 'cancel'])->name('inventory.reservedStocks.cancel');
    });

    // Document-based transfers
    Route::get('inventory/transfers', [InventoryTransactionController::class, 'transfersList'])->name('inventory.transfers.index');
    Route::get('inventory/transfers/transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.transit');
    Route::post('inventory/transfers', [InventoryTransactionController::class, 'transferOut'])->name('inventory.transferOut');
    Route::get('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferShow'])->name('inventory.transfers.show');
    Route::post('inventory/transfers/{id}/receive', [InventoryTransactionController::class, 'transferIn'])->name('inventory.transferIn');

    // Standalone Putaway
    Route::prefix('putaway')->group(function () {
        Route::get('/', [PutawayController::class, 'index'])->name('putaway.index');
        Route::get('/not-started', [PutawayController::class, 'notStarted'])->name('putaway.notStarted');
        Route::get('/in-progress', [PutawayController::class, 'inProgress'])->name('putaway.inProgress');
        Route::get('/completed', [PutawayController::class, 'completed'])->name('putaway.completed');
        Route::get('/{id}', [PutawayController::class, 'show'])->name('putaway.show');
        Route::get('/{id}/items', [PutawayController::class, 'items'])->name('putaway.items');
        Route::post('/assign-staff', [PutawayController::class, 'assignStaff'])->name('putaway.assignStaff');
        Route::post('/{id}/start', [PutawayController::class, 'start'])->name('putaway.start');
        Route::post('/{id}/items/{itemId}/process', [PutawayController::class, 'processItem'])->name('putaway.processItem');
        Route::post('/{id}/complete', [PutawayController::class, 'complete'])->name('putaway.complete');
    });
});
