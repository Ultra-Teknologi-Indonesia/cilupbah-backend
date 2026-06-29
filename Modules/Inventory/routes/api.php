<?php

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\InventoryTransactionController;
use Modules\Inventory\Http\Controllers\StockAdjustmentController;
use Modules\Inventory\Http\Controllers\ReservedStockController;
use Modules\Inventory\Http\Controllers\PutawayController;
use Modules\Inventory\Http\Controllers\StockOpnameController;
use Modules\Inventory\Http\Controllers\StockRevaluationController;
use Modules\Inventory\Http\Controllers\PriceListController;
use Modules\Inventory\Http\Controllers\BundleController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('inventory', [InventoryController::class, 'stockItems'])->name('inventory.index');
    Route::get('inventory/{itemId}', [InventoryController::class, 'stockItemShow'])->name('inventory.show')->where('itemId', '[0-9a-f-]{32,36}');
    Route::get('inventory/stocks', [InventoryController::class, 'index'])->name('inventory.stocks.index');
    Route::get('inventory/stocks/{itemId}', [InventoryController::class, 'show'])->name('inventory.stocks.show');
    Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements.index');

    Route::get('inventory/activity', [InventoryController::class, 'movements'])->name('inventory.activity.index');

    Route::get('inventory/items/to-stock', [InventoryController::class, 'itemsToStock'])->name('inventory.items.toStock');
    Route::get('inventory/items/item-on-stock', [InventoryController::class, 'itemsOnStock'])->name('inventory.items.itemOnStock');
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

    Route::get('inventory/items/by-sku/{sku}', [InventoryController::class, 'bySku'])->name('inventory.items.bySku');
    Route::post('inventory/items/all-stocks', [InventoryController::class, 'allStocksByIds'])->name('inventory.items.allStocks');
    Route::delete('inventory/items/item-variant', [InventoryController::class, 'deleteVariant'])->name('inventory.items.deleteVariant');

    Route::get('inventory/internal-price-list', [PriceListController::class, 'index'])->name('inventory.priceList.index');
    Route::post('inventory/price-list', [PriceListController::class, 'update'])->name('inventory.priceList.update');
    Route::post('inventory/items/prices', [PriceListController::class, 'pricesByIds'])->name('inventory.items.prices');

    Route::get('inventory/item-bundles', [BundleController::class, 'index'])->name('inventory.bundles.index');
    Route::post('inventory/items/bundle', [BundleController::class, 'store'])->name('inventory.bundles.store');

    Route::prefix('inventory/monitor')->group(function () {
        Route::get('summary', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'summary'])->name('inventory.monitor.summary');
        Route::get('out-of-stock', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'outOfStock'])->name('inventory.monitor.outOfStock');
        Route::get('low-stock', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'lowStock'])->name('inventory.monitor.lowStock');
        Route::get('on-order', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'onOrder'])->name('inventory.monitor.onOrder');

        Route::get('dead-stock', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'deadStock'])->name('inventory.monitor.deadStock');
        Route::get('fast-moving', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'fastMoving'])->name('inventory.monitor.fastMoving');
        Route::get('estimated-stock-out', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'estimatedStockOut'])->name('inventory.monitor.estimatedStockOut');

        Route::get('failed-sync', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'failedSync'])->name('inventory.monitor.failedSync');
        Route::post('failed-sync/{id}/retry', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'retrySync'])->name('inventory.monitor.retrySync');
        Route::post('failed-sync/retry-bulk', [\Modules\Inventory\Http\Controllers\MonitorStockController::class, 'retryBulkSync'])->name('inventory.monitor.retryBulkSync');
    });

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

    Route::get('inventory/warehouse-workers/{locationId}', function (string $locationId) {
        $users = \App\Models\User::where('warehouse_id', $locationId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
        return response()->json(['data' => $users]);
    })->name('inventory.warehouseWorkers');

    Route::get('inventory/transfers/transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.transit');
    Route::get('inventory/transfers/all-transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.allTransit');
    Route::get('inventory/transfers/in', [InventoryTransactionController::class, 'transfersIn'])->name('inventory.transfers.in');
    Route::get('inventory/transfers/out', [InventoryTransactionController::class, 'transfersOut'])->name('inventory.transfers.out');
    Route::get('inventory/transfers/drafts', [InventoryTransactionController::class, 'draftList'])->name('inventory.transfers.drafts');
    Route::get('inventory/transfers/approved', [InventoryTransactionController::class, 'approvedList'])->name('inventory.transfers.approved');
    Route::get('inventory/transfers/out-finished', [InventoryTransactionController::class, 'finishedList'])->name('inventory.transfers.outFinished');
    Route::get('inventory/transfers', [InventoryTransactionController::class, 'transfersList'])->name('inventory.transfers.index');
    Route::post('inventory/transfers', [InventoryTransactionController::class, 'transferOut'])->name('inventory.transferOut');
    Route::post('inventory/transfers/draft', [InventoryTransactionController::class, 'createDraft'])->name('inventory.transfers.createDraft');
    Route::patch('inventory/transfers/{id}', [InventoryTransactionController::class, 'updateDraft'])->name('inventory.transfers.updateDraft');
    Route::post('inventory/transfers/{id}/items', [InventoryTransactionController::class, 'addDraftItem'])->name('inventory.transfers.addDraftItem');
    Route::patch('inventory/transfers/{id}/items/{itemId}', [InventoryTransactionController::class, 'updateDraftItem'])->name('inventory.transfers.updateDraftItem');
    Route::delete('inventory/transfers/{id}/items/{itemId}', [InventoryTransactionController::class, 'removeDraftItem'])->name('inventory.transfers.removeDraftItem');
    Route::post('inventory/transfers/{id}/submit', [InventoryTransactionController::class, 'submitDraft'])->name('inventory.transfers.submitDraft');
    Route::get('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferShow'])->name('inventory.transfers.show');
    Route::delete('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferDestroy'])->name('inventory.transfers.destroy');
    Route::post('inventory/transfers/{id}/approve', [InventoryTransactionController::class, 'approveTransfer'])->name('inventory.transfers.approve');
    Route::post('inventory/transfers/{id}/cancel', [InventoryTransactionController::class, 'cancelTransfer'])->name('inventory.transfers.cancel');
    Route::post('inventory/transfers/{id}/ship', [InventoryTransactionController::class, 'shipTransfer'])->name('inventory.transfers.ship');
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

    Route::prefix('inventory/amount-adjustments')->group(function () {
        Route::get('/', [StockRevaluationController::class, 'index'])->name('inventory.amountAdjustments.index');
        Route::post('/', [StockRevaluationController::class, 'store'])->name('inventory.amountAdjustments.store');
        Route::get('/{id}', [StockRevaluationController::class, 'show'])->name('inventory.amountAdjustments.show');
        Route::post('/{id}/cancel', [StockRevaluationController::class, 'cancel'])->name('inventory.amountAdjustments.cancel');
    });

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
        Route::post('/', [PutawayController::class, 'store'])->name('putaway.store');
        Route::get('/not-started', [PutawayController::class, 'notStarted'])->name('putaway.notStarted');
        Route::get('/in-progress', [PutawayController::class, 'inProgress'])->name('putaway.inProgress');
        Route::get('/completed', [PutawayController::class, 'completed'])->name('putaway.completed');
        Route::get('/bins/lookup', [PutawayController::class, 'lookupBin'])->name('putaway.lookupBin');
        Route::get('/{id}', [PutawayController::class, 'show'])->name('putaway.show');
        Route::get('/{id}/items', [PutawayController::class, 'items'])->name('putaway.items');
        Route::post('/assign-staff', [PutawayController::class, 'assignStaff'])->name('putaway.assignStaff');
        Route::post('/{id}/start', [PutawayController::class, 'start'])->name('putaway.start');
        Route::post('/{id}/items/{itemId}/process', [PutawayController::class, 'processItem'])->name('putaway.processItem');
        Route::post('/{id}/complete', [PutawayController::class, 'complete'])->name('putaway.complete');
    });
});