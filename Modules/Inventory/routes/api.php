<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\InventoryTransactionController;
use Modules\Inventory\Http\Controllers\StockAdjustmentController;
use Modules\Inventory\Http\Controllers\ReservedStockController;
use Modules\Inventory\Http\Controllers\PutawayController;
use Modules\Inventory\Http\Controllers\StockOpnameController;
use Modules\Inventory\Http\Controllers\StockRevaluationController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('inventory/stocks', [InventoryController::class, 'index'])->name('inventory.stocks.index');
    Route::get('inventory/stocks/{itemId}', [InventoryController::class, 'show'])->name('inventory.stocks.show');
    Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements.index');

    Route::get('inventory/activity', [InventoryController::class, 'movements'])->name('inventory.activity.index');

    Route::get('inventory/items/to-stock', [InventoryController::class, 'itemsToStock'])->name('inventory.items.toStock');
    Route::get('inventory/items/item-on-stock', [InventoryController::class, 'itemsToStock'])->name('inventory.items.itemOnStock');
    Route::get('inventory/items/to-sell/{locationId}', [InventoryController::class, 'toSell'])->name('inventory.items.toSell');
    Route::get('inventory/items/to-sales-return', [InventoryController::class, 'toSalesReturn'])->name('inventory.items.toSalesReturn');
    Route::get('inventory/items/{id}/batch-number', [InventoryController::class, 'batchNumbers'])->name('inventory.items.batchNumber');
    Route::post('inventory/items/to-adjust', [InventoryController::class, 'toAdjust'])->name('inventory.items.toAdjust');
    Route::post('inventory/items/split-item', [InventoryController::class, 'splitItem'])->name('inventory.items.splitItem');
    Route::get('inventory/items/by-bill/{docId}', [InventoryController::class, 'itemsByBill'])->name('inventory.items.byBill');
    Route::get('inventory/items/by-invoice/{invoiceId}', [InventoryController::class, 'itemsByInvoice'])->name('inventory.items.byInvoice');
    Route::get('inventory/out-of-stock-in-order', [InventoryController::class, 'outOfStockInOrder'])->name('inventory.outOfStockInOrder');
    Route::get('inventory/need-restock', [InventoryController::class, 'needRestock'])->name('inventory.needRestock');
    Route::get('inventory/stock-products', [InventoryController::class, 'stockProducts'])->name('inventory.stockProducts');
    Route::get('inventory/history', [InventoryController::class, 'history'])->name('inventory.history');
    Route::get('inventory/items/by-location/{locationId}', [InventoryController::class, 'byLocation'])->name('inventory.items.byLocation');
    Route::get('inventory/purchase-order/items', [InventoryController::class, 'purchaseOrderItems'])->name('inventory.purchaseOrder.items');

    Route::post('inventory/adjustments', [InventoryTransactionController::class, 'adjust'])->name('inventory.adjust');
    Route::post('inventory/putaway', [InventoryTransactionController::class, 'putaway'])->name('inventory.putaway');

    Route::prefix('inventory/adjustments/documents')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index'])->name('inventory.adjustments.documents.index');
        Route::post('/', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.documents.store');
        Route::get('/{id}', [StockAdjustmentController::class, 'show'])->name('inventory.adjustments.documents.show');
        Route::post('/{id}/approve', [StockAdjustmentController::class, 'approve'])->name('inventory.adjustments.documents.approve');
        Route::post('/{id}/cancel', [StockAdjustmentController::class, 'cancel'])->name('inventory.adjustments.documents.cancel');
        Route::delete('/{id}', [StockAdjustmentController::class, 'destroy'])->name('inventory.adjustments.documents.destroy');
    });

    Route::prefix('inventory/reserved-stocks')->group(function () {
        Route::get('/', [ReservedStockController::class, 'index'])->name('inventory.reservedStocks.index');
        Route::post('/', [ReservedStockController::class, 'store'])->name('inventory.reservedStocks.store');
        Route::get('/{id}', [ReservedStockController::class, 'show'])->name('inventory.reservedStocks.show');
        Route::post('/{id}/cancel', [ReservedStockController::class, 'cancel'])->name('inventory.reservedStocks.cancel');
    });

    Route::get('inventory/transfers/transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.transit');
    Route::get('inventory/transfers/all-transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.allTransit');
    Route::get('inventory/transfers/in', [InventoryTransactionController::class, 'transfersIn'])->name('inventory.transfers.in');
    Route::get('inventory/transfers/out', [InventoryTransactionController::class, 'transfersOut'])->name('inventory.transfers.out');
    Route::get('inventory/transfers/out-finished', [InventoryTransactionController::class, 'finishedList'])->name('inventory.transfers.outFinished');
    Route::get('inventory/transfers', [InventoryTransactionController::class, 'transfersList'])->name('inventory.transfers.index');
    Route::post('inventory/transfers', [InventoryTransactionController::class, 'transferOut'])->name('inventory.transferOut');
    Route::get('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferShow'])->name('inventory.transfers.show');
    Route::delete('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferDestroy'])->name('inventory.transfers.destroy');
    Route::post('inventory/transfers/{id}/receive', [InventoryTransactionController::class, 'transferIn'])->name('inventory.transferIn');

    Route::post('inventory/transfer/mark-printed', [InventoryTransactionController::class, 'markTransferPrinted'])->name('inventory.transfer.markPrinted');
    Route::get('inventory/transfer/delivery', [InventoryTransactionController::class, 'transferDelivery'])->name('inventory.transfer.delivery');

    Route::get('inventory/items/by-transfer/{id}', [InventoryTransactionController::class, 'transferShow'])->name('inventory.items.byTransfer');

    Route::post('inventory/catalog/set-master', function (\Illuminate\Http\Request $request) {
        $product = \Modules\Product\Models\Product::findOrFail($request->input('product_id'));
        $userId = $request->user()->name ?? $request->user()->email;
        $result = app(\Modules\Product\Services\ProductLifecycleService::class)->approve($product, $userId);
        return response()->json(['success' => true, 'data' => $result, 'message' => 'Product berhasil di-set sebagai master.']);
    })->name('inventory.catalog.setMaster');

    Route::prefix('inventory/revaluations')->group(function () {
        Route::get('/', [StockRevaluationController::class, 'index'])->name('inventory.revaluations.index');
        Route::post('/', [StockRevaluationController::class, 'store'])->name('inventory.revaluations.store');
        Route::get('/{id}', [StockRevaluationController::class, 'show'])->name('inventory.revaluations.show');
        Route::post('/{id}/approve', [StockRevaluationController::class, 'approve'])->name('inventory.revaluations.approve');
        Route::post('/{id}/cancel', [StockRevaluationController::class, 'cancel'])->name('inventory.revaluations.cancel');
    });

    Route::prefix('inventory/stock-opname')->group(function () {
        Route::get('/bins', [StockOpnameController::class, 'bins'])->name('inventory.stockOpname.bins');
        Route::get('/floors', [StockOpnameController::class, 'floors'])->name('inventory.stockOpname.floors');
        Route::get('/rows', [StockOpnameController::class, 'rows'])->name('inventory.stockOpname.rows');
        Route::get('/columns', [StockOpnameController::class, 'columns'])->name('inventory.stockOpname.columns');
        Route::get('/', [StockOpnameController::class, 'index'])->name('inventory.stockOpname.index');
        Route::post('/', [StockOpnameController::class, 'store'])->name('inventory.stockOpname.store');
        Route::get('/{id}', [StockOpnameController::class, 'show'])->name('inventory.stockOpname.show');
        Route::get('/{id}/items', [StockOpnameController::class, 'items'])->name('inventory.stockOpname.items');
        Route::get('/{id}/items/filtered', [StockOpnameController::class, 'filteredItems'])->name('inventory.stockOpname.filteredItems');
        Route::post('/{id}/start', [StockOpnameController::class, 'start'])->name('inventory.stockOpname.start');
        Route::post('/{id}/items/{itemId}/count', [StockOpnameController::class, 'countItem'])->name('inventory.stockOpname.countItem');
        Route::post('/{id}/finalize', [StockOpnameController::class, 'finalize'])->name('inventory.stockOpname.finalize');
        Route::post('/{id}/cancel', [StockOpnameController::class, 'cancel'])->name('inventory.stockOpname.cancel');
        Route::post('/{id}/mark-printed', [StockOpnameController::class, 'markPrinted'])->name('inventory.stockOpname.markPrinted');
        Route::delete('/{id}', [StockOpnameController::class, 'destroy'])->name('inventory.stockOpname.destroy');
    });

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
