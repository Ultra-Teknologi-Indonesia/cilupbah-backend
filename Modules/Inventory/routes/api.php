<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\InventoryTransactionController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('inventory/stocks', [InventoryController::class, 'index'])->name('inventory.stocks.index');
    Route::get('inventory/stocks/{itemId}', [InventoryController::class, 'show'])->name('inventory.stocks.show');
    Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements.index');

    Route::post('inventory/adjustments', [InventoryTransactionController::class, 'adjust'])->name('inventory.adjust');
    Route::post('inventory/putaway', [InventoryTransactionController::class, 'putaway'])->name('inventory.putaway');

    // Document-based transfers
    Route::get('inventory/transfers', [InventoryTransactionController::class, 'transfersList'])->name('inventory.transfers.index');
    Route::get('inventory/transfers/transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.transit');
    Route::post('inventory/transfers', [InventoryTransactionController::class, 'transferOut'])->name('inventory.transferOut');
    Route::get('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferShow'])->name('inventory.transfers.show');
    Route::post('inventory/transfers/{id}/receive', [InventoryTransactionController::class, 'transferIn'])->name('inventory.transferIn');
});
