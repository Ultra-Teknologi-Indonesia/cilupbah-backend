<?php

use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\BundleController;
use Modules\Inventory\Http\Controllers\ImpexActivityController;
use Modules\Inventory\Http\Controllers\InventoryController;
use Modules\Inventory\Http\Controllers\InventorySettingController;
use Modules\Inventory\Http\Controllers\InventorySyncSettingController;
use Modules\Inventory\Http\Controllers\InventoryTransactionController;
use Modules\Inventory\Http\Controllers\MonitorStockController;
use Modules\Inventory\Http\Controllers\PutawayController;
use Modules\Inventory\Http\Controllers\RackImportController;
use Modules\Inventory\Http\Controllers\ReservedStockController;
use Modules\Inventory\Http\Controllers\StockAdjustmentController;
use Modules\Inventory\Http\Controllers\StockAdjustmentImportController;
use Modules\Inventory\Http\Controllers\StockOpnameController;
use Modules\Inventory\Http\Controllers\StockReplenishmentController;
use Modules\Inventory\Http\Controllers\StockRevaluationController;
use Modules\Inventory\Http\Controllers\TransferOutImportController;
use Modules\Product\Models\Product;
use Modules\Product\Services\ProductLifecycleService;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::prefix('impex/activities')->middleware('role_or_permission:owner|view-impex')->group(function () {
        Route::post('export/record', [ImpexActivityController::class, 'recordExport'])->name('impex.activities.recordExport');
        Route::get('{direction}', [ImpexActivityController::class, 'index'])->name('impex.activities.index')->where('direction', 'import|export');
        Route::get('{direction}/{id}/details', [ImpexActivityController::class, 'details'])->name('impex.activities.details')->where('direction', 'import|export');
    });

    Route::middleware('role_or_permission:owner|view-posisi-stok')->group(function () {
        Route::get('inventory', [InventoryController::class, 'stockItems'])->name('inventory.index');
        Route::get('inventory/{itemId}', [InventoryController::class, 'stockItemShow'])->name('inventory.show')->where('itemId', '[0-9a-f-]{32,36}');
        Route::get('inventory/stocks', [InventoryController::class, 'index'])->name('inventory.stocks.index');
        Route::get('inventory/stocks/{itemId}', [InventoryController::class, 'show'])->name('inventory.stocks.show');
        Route::get('inventory/movements', [InventoryController::class, 'movements'])->name('inventory.movements.index');

        Route::get('inventory/activity', [InventoryController::class, 'movements'])->name('inventory.activity.index');
    });

    Route::get('inventory/items/to-stock', [InventoryController::class, 'itemsToStock'])->name('inventory.items.toStock')->middleware('role_or_permission:owner|view-posisi-stok');
    Route::get('inventory/items/item-on-stock', [InventoryController::class, 'itemsOnStock'])->name('inventory.items.itemOnStock')->middleware('role_or_permission:owner|view-posisi-stok');
    Route::get('inventory/items/to-sell/{locationId}', [InventoryController::class, 'toSell'])->name('inventory.items.toSell')->middleware('role_or_permission:owner|view-posisi-stok');
    Route::get('inventory/items/to-sales-return', [InventoryController::class, 'toSalesReturn'])->name('inventory.items.toSalesReturn')->middleware('role_or_permission:owner|view-retur-penjualan');
    Route::get('inventory/items/{id}/batch-number', [InventoryController::class, 'batchNumbers'])->name('inventory.items.batchNumber')->middleware('role_or_permission:owner|view-produk');
    Route::post('inventory/items/to-adjust', [InventoryController::class, 'toAdjust'])->name('inventory.items.toAdjust')->middleware('role_or_permission:owner|view-penyesuaian-stok');
    Route::post('inventory/items/split-item', [InventoryController::class, 'splitItem'])->name('inventory.items.splitItem')->middleware('role_or_permission:owner|edit-produk');
    Route::get('inventory/items/by-bill/{docId}', [InventoryController::class, 'itemsByBill'])->name('inventory.items.byBill')->middleware('role_or_permission:owner|view-produk');
    Route::get('inventory/items/by-invoice/{invoiceId}', [InventoryController::class, 'itemsByInvoice'])->name('inventory.items.byInvoice')->middleware('role_or_permission:owner|view-produk');

    Route::middleware('role_or_permission:owner|view-posisi-stok')->group(function () {
        Route::get('inventory/out-of-stock-in-order', [InventoryController::class, 'outOfStockInOrder'])->name('inventory.outOfStockInOrder');
        Route::get('inventory/need-restock', [InventoryController::class, 'needRestock'])->name('inventory.needRestock');
        Route::get('inventory/stock-products', [InventoryController::class, 'stockProducts'])->name('inventory.stockProducts');
        Route::get('inventory/history', [InventoryController::class, 'history'])->name('inventory.history');
    });

    Route::get('inventory/movement-filters', [InventoryController::class, 'movementFilters'])->name('inventory.movementFilters')->middleware('role_or_permission:owner|view-posisi-stok');
    Route::get('inventory/items/by-location/{locationId}', [InventoryController::class, 'byLocation'])->name('inventory.items.byLocation')->middleware('role_or_permission:owner|view-posisi-stok');
    Route::get('inventory/purchase-order/items', [InventoryController::class, 'purchaseOrderItems'])->name('inventory.purchaseOrder.items')->middleware('role_or_permission:owner|view-transaksi-pembelian');

    Route::get('inventory/items/by-sku/{sku}', [InventoryController::class, 'bySku'])->name('inventory.items.bySku');
    Route::get('inventory/stock/by-sku/{sku}', [InventoryController::class, 'bySku'])->name('inventory.stock.bySku')->middleware('role_or_permission:owner|view-posisi-stok|view-penyesuaian-stok|create-penyesuaian-stok|view-pindah-bin|create-pindah-bin|view-transfer-keluar|create-transfer-keluar|view-pesanan|create-pesanan|view-manajemen-rak');
    Route::get('inventory/stock/by-bin-code/{binCode}', [InventoryController::class, 'byBinCode'])->name('inventory.stock.byBinCode')->middleware('role_or_permission:owner|view-posisi-stok|view-penyesuaian-stok|create-penyesuaian-stok|view-pindah-bin|create-pindah-bin|view-transfer-keluar|create-transfer-keluar|view-pesanan|create-pesanan|view-manajemen-rak');
    Route::get('inventory/stock/items', [InventoryController::class, 'stockedItems'])->name('inventory.stock.items')->middleware('role_or_permission:owner|view-posisi-stok|view-penyesuaian-stok|create-penyesuaian-stok|view-pindah-bin|create-pindah-bin|view-transfer-keluar|create-transfer-keluar|view-pesanan|create-pesanan|view-manajemen-rak');
    Route::post('inventory/items/all-stocks', [InventoryController::class, 'allStocksByIds'])->name('inventory.items.allStocks')->middleware('role_or_permission:owner|view-posisi-stok|view-penyesuaian-stok|create-penyesuaian-stok|view-pindah-bin|create-pindah-bin|view-transfer-keluar|create-transfer-keluar|view-pesanan|create-pesanan|view-manajemen-rak');
    Route::delete('inventory/items/item-variant', [InventoryController::class, 'deleteVariant'])->name('inventory.items.deleteVariant');

    Route::middleware('role_or_permission:owner|create-bundle')->group(function () {
        Route::post('inventory/items/bundle', [BundleController::class, 'store'])->name('inventory.bundles.store');
    });

    Route::prefix('inventory/monitor')->group(function () {
        Route::middleware('role_or_permission:owner|view-monitor-stok')->group(function () {
            Route::get('summary', [MonitorStockController::class, 'summary'])->name('inventory.monitor.summary');
            Route::get('out-of-stock', [MonitorStockController::class, 'outOfStock'])->name('inventory.monitor.outOfStock');
            Route::get('low-stock', [MonitorStockController::class, 'lowStock'])->name('inventory.monitor.lowStock');
            Route::get('on-order', [MonitorStockController::class, 'onOrder'])->name('inventory.monitor.onOrder');

            Route::get('dead-stock', [MonitorStockController::class, 'deadStock'])->name('inventory.monitor.deadStock');
            Route::get('fast-moving', [MonitorStockController::class, 'fastMoving'])->name('inventory.monitor.fastMoving');
            Route::get('estimated-stock-out', [MonitorStockController::class, 'estimatedStockOut'])->name('inventory.monitor.estimatedStockOut');

            Route::get('failed-sync', [MonitorStockController::class, 'failedSync'])->name('inventory.monitor.failedSync');
        });
        Route::middleware('role_or_permission:owner|edit-monitor-stok')->group(function () {
            Route::post('failed-sync/{id}/retry', [MonitorStockController::class, 'retrySync'])->name('inventory.monitor.retrySync');
            Route::post('failed-sync/retry-bulk', [MonitorStockController::class, 'retryBulkSync'])->name('inventory.monitor.retryBulkSync');
        });
    });

    Route::prefix('inventory/sync-settings')->group(function () {
        Route::middleware('role_or_permission:owner|view-pengaturan-persediaan')->group(function () {
            Route::get('/', [InventorySyncSettingController::class, 'index'])->name('inventory.syncSettings.index');
        });
        Route::middleware('role_or_permission:owner|edit-pengaturan-persediaan')->group(function () {
            Route::patch('/', [InventorySyncSettingController::class, 'update'])->name('inventory.syncSettings.update');
            Route::post('bulk', [InventorySyncSettingController::class, 'bulkUpdate'])->name('inventory.syncSettings.bulk');
        });
    });

    Route::prefix('inventory/settings')->group(function () {
        Route::middleware('role_or_permission:owner|view-pengaturan-persediaan')->group(function () {
            Route::get('products', [InventorySettingController::class, 'products'])->name('inventory.settings.products');
            Route::get('export/rack-allocation', [InventorySettingController::class, 'exportRackAllocation'])->name('inventory.settings.export.rack');
            Route::get('import/template/{type}', [InventorySettingController::class, 'importTemplate'])->name('inventory.settings.import.template');

            Route::get('rack-import/batches', [RackImportController::class, 'batches'])->name('inventory.settings.rack-import.batches');
            Route::get('rack-import/batches/{batch}', [RackImportController::class, 'show'])->whereUuid('batch')->name('inventory.settings.rack-import.show');
            Route::get('rack-import/batches/{batch}/rows', [RackImportController::class, 'rows'])->whereUuid('batch')->name('inventory.settings.rack-import.rows');
            Route::get('rack-import/batches/{batch}/errors/download', [RackImportController::class, 'downloadErrors'])->whereUuid('batch')->name('inventory.settings.rack-import.errors');
        });
        Route::middleware('role_or_permission:owner|edit-pengaturan-persediaan')->group(function () {
            Route::patch('products/{itemId}', [InventorySettingController::class, 'updateThresholds'])->whereUuid('itemId')->name('inventory.settings.products.update');
            Route::post('import/{type}/preview', [InventorySettingController::class, 'importPreview'])->name('inventory.settings.import.preview');
            Route::post('import/{type}/confirm', [InventorySettingController::class, 'importConfirm'])->name('inventory.settings.import.confirm');

            Route::post('rack-import', [RackImportController::class, 'upload'])->name('inventory.settings.rack-import.upload');
            Route::post('rack-import/batches/{batch}/confirm', [RackImportController::class, 'confirm'])->whereUuid('batch')->name('inventory.settings.rack-import.confirm');
        });
    });

    Route::middleware('role_or_permission:owner|create-penyesuaian-stok')->group(function () {
        Route::post('inventory/adjustments', [InventoryTransactionController::class, 'adjust'])->name('inventory.adjust');
    });

    Route::middleware('role_or_permission:owner|create-pindah-bin')->group(function () {
        Route::post('inventory/putaway', [InventoryTransactionController::class, 'putaway'])->name('inventory.putaway');
        Route::post('inventory/bin-transfer', [InventoryTransactionController::class, 'binTransfer'])->name('inventory.binTransfer');
        Route::post('inventory/bin-transfers', [InventoryTransactionController::class, 'binTransfer'])->name('inventory.binTransfers.store');
    });
    Route::middleware('role_or_permission:owner|view-pindah-bin')->group(function () {
        Route::get('inventory/bin-transfers', [InventoryTransactionController::class, 'binTransferIndex'])->name('inventory.binTransfers.index');

        Route::get('inventory/bin-transfer-receipts', [InventoryTransactionController::class, 'binTransferReceiptsIndex'])->name('inventory.binTransferReceipts.index');
        Route::get('inventory/bin-transfer-receipts/{id}', [InventoryTransactionController::class, 'binTransferReceiptShow'])->name('inventory.binTransferReceipts.show');
        Route::get('inventory/bin-transfers/{id}', [InventoryTransactionController::class, 'binTransferShow'])->name('inventory.binTransfers.show');
    });
    Route::middleware('role_or_permission:owner|export-pindah-bin')->group(function () {
        Route::get('inventory/bin-transfers/{id}/pdf', [InventoryTransactionController::class, 'binTransferPdf'])->name('inventory.binTransfers.pdf');
    });
    Route::middleware('role_or_permission:owner|edit-pindah-bin')->group(function () {
        Route::patch('inventory/bin-transfers/{id}', [InventoryTransactionController::class, 'binTransferUpdate'])->name('inventory.binTransfers.update');
        Route::post('inventory/bin-transfers/{id}/print', [InventoryTransactionController::class, 'binTransferPrint'])->name('inventory.binTransfers.print');
        Route::post('inventory/bin-transfers/{id}/revert-print', [InventoryTransactionController::class, 'binTransferRevertPrint'])->name('inventory.binTransfers.revertPrint');
        Route::post('inventory/bin-transfers/{id}/receipts', [InventoryTransactionController::class, 'binTransferReceive'])->name('inventory.binTransfers.receive');
        Route::delete('inventory/bin-transfer-receipts/{id}', [InventoryTransactionController::class, 'binTransferReceiptDestroy'])->name('inventory.binTransferReceipts.destroy');
        Route::delete('inventory/bin-transfers/{id}/items/{itemId}', [InventoryTransactionController::class, 'binTransferItemDestroy'])->name('inventory.binTransfers.items.destroy');
        Route::post('inventory/bin-transfers/{id}/items/reverse', [InventoryTransactionController::class, 'binTransferItemsDestroy'])->name('inventory.binTransfers.items.reverse');
    });
    Route::middleware('role_or_permission:owner|delete-pindah-bin')->group(function () {
        Route::delete('inventory/bin-transfers/{id}', [InventoryTransactionController::class, 'binTransferDestroy'])->name('inventory.binTransfers.destroy');
        Route::delete('inventory/bin-transfers/{id}/items', [InventoryTransactionController::class, 'binTransferItemsDestroy'])->name('inventory.binTransfers.itemsDestroy');
        Route::delete('inventory/bin-transfers/{id}/items/{itemId}', [InventoryTransactionController::class, 'binTransferItemDestroy'])->name('inventory.binTransfers.itemDestroy');
    });

    Route::prefix('inventory/adjustments/import')->middleware('role_or_permission:owner|import-penyesuaian-stok')->group(function () {
        Route::get('/template', [StockAdjustmentImportController::class, 'template'])->name('inventory.adjustments.import.template');
        Route::post('/preview', [StockAdjustmentImportController::class, 'preview'])->name('inventory.adjustments.import.preview');
        Route::post('/confirm', [StockAdjustmentImportController::class, 'confirm'])->name('inventory.adjustments.import.confirm');
    });

    Route::prefix('inventory/transfers/import')->middleware('role_or_permission:owner|create-barang-keluar')->group(function () {
        Route::get('/template', [TransferOutImportController::class, 'template'])->name('inventory.transfers.import.template');
        Route::post('/preview', [TransferOutImportController::class, 'preview'])->name('inventory.transfers.import.preview');
        Route::post('/confirm', [TransferOutImportController::class, 'confirm'])->name('inventory.transfers.import.confirm');
    });

    Route::prefix('inventory/adjustments/documents')->group(function () {
        Route::middleware('role_or_permission:owner|view-penyesuaian-stok')->group(function () {
            Route::get('/', [StockAdjustmentController::class, 'index'])->name('inventory.adjustments.documents.index');
            Route::get('/{id}', [StockAdjustmentController::class, 'show'])->name('inventory.adjustments.documents.show');
            Route::get('/{id}/items', [StockAdjustmentController::class, 'items'])->name('inventory.adjustments.documents.items');
        });
        Route::middleware('role_or_permission:owner|create-penyesuaian-stok|edit-penyesuaian-stok')->group(function () {
            Route::post('/', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.documents.store');
            Route::put('/{id}', [StockAdjustmentController::class, 'update'])->name('inventory.adjustments.documents.update');
        });
        Route::middleware('role_or_permission:owner|export-penyesuaian-stok')->group(function () {
            Route::post('/bulk/pdf', [StockAdjustmentController::class, 'bulkPdf'])->name('inventory.adjustments.documents.bulkPdf');
            Route::get('/{id}/pdf', [StockAdjustmentController::class, 'pdf'])->name('inventory.adjustments.documents.pdf');
            Route::get('/export/xlsx', [StockAdjustmentController::class, 'exportXlsx'])->name('inventory.adjustments.documents.exportXlsx');
        });
        Route::middleware('role_or_permission:owner|delete-penyesuaian-stok')->group(function () {
            Route::post('/bulk/delete', [StockAdjustmentController::class, 'bulkDelete'])->name('inventory.adjustments.documents.bulkDelete');
            Route::delete('/{id}', [StockAdjustmentController::class, 'destroy'])->name('inventory.adjustments.documents.destroy');
        });
    });

    Route::prefix('inventory/reserved-stocks')->group(function () {
        Route::middleware('role_or_permission:owner|view-posisi-stok')->group(function () {
            Route::get('/', [ReservedStockController::class, 'index'])->name('inventory.reservedStocks.index');
            Route::get('/{id}', [ReservedStockController::class, 'show'])->name('inventory.reservedStocks.show');
        });
        Route::middleware('role_or_permission:owner|edit-posisi-stok')->group(function () {
            Route::post('/', [ReservedStockController::class, 'store'])->name('inventory.reservedStocks.store');
            Route::post('/{id}/cancel', [ReservedStockController::class, 'cancel'])->name('inventory.reservedStocks.cancel');
        });
    });

    Route::get('inventory/warehouse-workers/{locationId}', function (string $locationId) {
        $users = User::where('warehouse_id', $locationId)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        $responder = new class
        {
            use ApiResponse;
        };

        return $responder->successResponse($users);
    })->name('inventory.warehouseWorkers')->middleware('role_or_permission:owner|view-user');

    Route::middleware('role_or_permission:owner|view-barang-keluar')->group(function () {
        Route::get('inventory/transfers/transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.transit');
        Route::get('inventory/transfers/all-transit', [InventoryTransactionController::class, 'transitList'])->name('inventory.transfers.allTransit');
        Route::get('inventory/transfers/in', [InventoryTransactionController::class, 'transfersIn'])->name('inventory.transfers.in');
        Route::get('inventory/transfers/out', [InventoryTransactionController::class, 'transfersOut'])->name('inventory.transfers.out');
        Route::get('inventory/transfers/drafts', [InventoryTransactionController::class, 'draftList'])->name('inventory.transfers.drafts');
        Route::get('inventory/transfers/approved', [InventoryTransactionController::class, 'approvedList'])->name('inventory.transfers.approved');
        Route::get('inventory/transfers/out-finished', [InventoryTransactionController::class, 'finishedList'])->name('inventory.transfers.outFinished');
        Route::get('inventory/transfers', [InventoryTransactionController::class, 'transfersList'])->name('inventory.transfers.index');
        Route::get('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferShow'])->name('inventory.transfers.show');
        Route::get('inventory/items/by-transfer/{id}', [InventoryTransactionController::class, 'transferShow'])->name('inventory.items.byTransfer');
    });
    Route::middleware('role_or_permission:owner|create-barang-keluar')->group(function () {
        Route::post('inventory/transfers', [InventoryTransactionController::class, 'transferOut'])->name('inventory.transferOut');
        Route::post('inventory/transfers/draft', [InventoryTransactionController::class, 'createDraft'])->name('inventory.transfers.createDraft');
        Route::post('inventory/transfers/{id}/submit', [InventoryTransactionController::class, 'submitDraft'])->name('inventory.transfers.submitDraft');
    });
    Route::middleware('role_or_permission:owner|edit-barang-keluar')->group(function () {
        Route::patch('inventory/transfers/{id}', [InventoryTransactionController::class, 'updateDraft'])->name('inventory.transfers.updateDraft');
        Route::post('inventory/transfers/{id}/items', [InventoryTransactionController::class, 'addDraftItem'])->name('inventory.transfers.addDraftItem');
        Route::patch('inventory/transfers/{id}/items/{itemId}', [InventoryTransactionController::class, 'updateDraftItem'])->name('inventory.transfers.updateDraftItem');
        Route::post('inventory/transfers/{id}/approve', [InventoryTransactionController::class, 'approveTransfer'])->name('inventory.transfers.approve');
        Route::post('inventory/transfers/{id}/cancel', [InventoryTransactionController::class, 'cancelTransfer'])->name('inventory.transfers.cancel');
        Route::post('inventory/transfers/{id}/ship', [InventoryTransactionController::class, 'shipTransfer'])->name('inventory.transfers.ship');
        Route::post('inventory/transfers/{id}/revert-to-draft', [InventoryTransactionController::class, 'revertToDraft'])->name('inventory.transfers.revertToDraft');
        Route::post('inventory/transfers/{id}/receive', [InventoryTransactionController::class, 'transferIn'])->name('inventory.transferIn');
        Route::post('inventory/transfer/mark-printed', [InventoryTransactionController::class, 'markTransferPrinted'])->name('inventory.transfer.markPrinted');
    });
    Route::middleware('role_or_permission:owner|delete-barang-keluar')->group(function () {
        Route::post('inventory/transfers/bulk/delete', [InventoryTransactionController::class, 'bulkDeleteTransfer'])->name('inventory.transfers.bulkDelete');
        Route::delete('inventory/transfers/{id}/items/{itemId}', [InventoryTransactionController::class, 'removeDraftItem'])->name('inventory.transfers.removeDraftItem');
        Route::delete('inventory/transfers/{id}', [InventoryTransactionController::class, 'transferDestroy'])->name('inventory.transfers.destroy');
    });
    Route::middleware('role_or_permission:owner|export-barang-keluar')->group(function () {
        Route::post('inventory/transfers/bulk/pdf', [InventoryTransactionController::class, 'bulkPdfTransfer'])->name('inventory.transfers.bulkPdf');
        Route::get('inventory/transfers/{id}/pdf', [InventoryTransactionController::class, 'transferPdf'])->name('inventory.transfers.pdf');
        Route::get('inventory/transfer/delivery', [InventoryTransactionController::class, 'transferDelivery'])->name('inventory.transfer.delivery');
    });

    Route::post('inventory/catalog/set-master', function (Request $request) {
        $product = Product::findOrFail($request->input('product_id'));
        $userId = $request->user()->name ?? $request->user()->email;
        $result = app(ProductLifecycleService::class)->approve($product, $userId);

        $responder = new class
        {
            use ApiResponse;
        };

        return $responder->successResponse($result, 'Product berhasil di-set sebagai master.');
    })->name('inventory.catalog.setMaster')->middleware('role_or_permission:owner|edit-produk-naik');

    Route::prefix('inventory/amount-adjustments')->group(function () {
        Route::middleware('role_or_permission:owner|view-revaluasi-stok')->group(function () {
            Route::get('/', [StockRevaluationController::class, 'index'])->name('inventory.amountAdjustments.index');
            Route::get('/{id}', [StockRevaluationController::class, 'show'])->name('inventory.amountAdjustments.show');
        });
        Route::middleware('role_or_permission:owner|create-revaluasi-stok')->group(function () {
            Route::post('/', [StockRevaluationController::class, 'store'])->name('inventory.amountAdjustments.store');
        });
        Route::middleware('role_or_permission:owner|edit-revaluasi-stok')->group(function () {
            Route::post('/{id}/cancel', [StockRevaluationController::class, 'cancel'])->name('inventory.amountAdjustments.cancel');
        });
    });

    Route::prefix('inventory/revaluations')->group(function () {
        Route::middleware('role_or_permission:owner|view-revaluasi-stok')->group(function () {
            Route::get('/', [StockRevaluationController::class, 'index'])->name('inventory.revaluations.index');
            Route::get('/{id}', [StockRevaluationController::class, 'show'])->name('inventory.revaluations.show');
        });
        Route::middleware('role_or_permission:owner|create-revaluasi-stok')->group(function () {
            Route::post('/', [StockRevaluationController::class, 'store'])->name('inventory.revaluations.store');
        });
        Route::middleware('role_or_permission:owner|edit-revaluasi-stok')->group(function () {
            Route::post('/{id}/approve', [StockRevaluationController::class, 'approve'])->name('inventory.revaluations.approve');
            Route::post('/{id}/cancel', [StockRevaluationController::class, 'cancel'])->name('inventory.revaluations.cancel');
        });
    });

    Route::prefix('inventory/stock-opname')->group(function () {
        Route::middleware('role_or_permission:owner|view-stok-opname')->group(function () {
            Route::get('/bins', [StockOpnameController::class, 'bins'])->name('inventory.stockOpname.bins');
            Route::get('/floors', [StockOpnameController::class, 'floors'])->name('inventory.stockOpname.floors');
            Route::get('/rows', [StockOpnameController::class, 'rows'])->name('inventory.stockOpname.rows');
            Route::get('/columns', [StockOpnameController::class, 'columns'])->name('inventory.stockOpname.columns');
            Route::get('/', [StockOpnameController::class, 'index'])->name('inventory.stockOpname.index');
            Route::get('/{id}', [StockOpnameController::class, 'show'])->name('inventory.stockOpname.show');
            Route::get('/{id}/items', [StockOpnameController::class, 'items'])->name('inventory.stockOpname.items');
            Route::get('/{id}/items/filtered', [StockOpnameController::class, 'filteredItems'])->name('inventory.stockOpname.filteredItems');
        });
        Route::middleware('role_or_permission:owner|create-stok-opname')->group(function () {
            Route::post('/', [StockOpnameController::class, 'store'])->name('inventory.stockOpname.store');
        });
        Route::middleware('role_or_permission:owner|edit-stok-opname')->group(function () {
            Route::post('/{id}/start', [StockOpnameController::class, 'start'])->name('inventory.stockOpname.start');
            Route::post('/{id}/items/{itemId}/count', [StockOpnameController::class, 'countItem'])->name('inventory.stockOpname.countItem');
            Route::post('/{id}/finalize', [StockOpnameController::class, 'finalize'])->name('inventory.stockOpname.finalize');
            Route::post('/{id}/cancel', [StockOpnameController::class, 'cancel'])->name('inventory.stockOpname.cancel');
            Route::post('/{id}/mark-printed', [StockOpnameController::class, 'markPrinted'])->name('inventory.stockOpname.markPrinted');
            Route::delete('/{id}', [StockOpnameController::class, 'destroy'])->name('inventory.stockOpname.destroy');
        });
    });

    Route::prefix('putaway')->group(function () {
        Route::get('/counts', [PutawayController::class, 'counts'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.counts');
        Route::get('/', [PutawayController::class, 'index'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.index');
        Route::post('/', [PutawayController::class, 'store'])->middleware('role_or_permission:owner|create-penempatan')->name('putaway.store');
        Route::get('/not-started', [PutawayController::class, 'notStarted'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.notStarted');
        Route::get('/in-progress', [PutawayController::class, 'inProgress'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.inProgress');
        Route::get('/completed', [PutawayController::class, 'completed'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.completed');
        Route::get('/bins', [PutawayController::class, 'listBins'])->name('putaway.listBins')->middleware('role_or_permission:owner|view-penempatan');
        Route::get('/bins/lookup', [PutawayController::class, 'lookupBin'])->name('putaway.lookupBin')->middleware('role_or_permission:owner|view-penempatan');
        Route::post('/bulk/pdf', [PutawayController::class, 'bulkPdf'])->middleware('role_or_permission:owner|export-penempatan')->name('putaway.bulkPdf');
        Route::delete('/bulk', [PutawayController::class, 'bulkDestroy'])->middleware('role_or_permission:owner|delete-penempatan')->name('putaway.bulkDestroy');
        Route::get('/{id}/pdf', [PutawayController::class, 'pdf'])->middleware('role_or_permission:owner|export-penempatan')->name('putaway.pdf');
        Route::get('/{id}/history', [PutawayController::class, 'history'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.history');
        Route::get('/{id}', [PutawayController::class, 'show'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.show');
        Route::get('/{id}/items', [PutawayController::class, 'items'])->middleware('role_or_permission:owner|view-penempatan')->name('putaway.items');
        Route::post('/assign-staff', [PutawayController::class, 'assignStaff'])->middleware('role_or_permission:owner|edit-penempatan')->name('putaway.assignStaff');

        Route::delete('/{id}/assignment', [PutawayController::class, 'unassign'])->middleware('role_or_permission:owner|edit-penempatan')->name('putaway.unassign');
        Route::post('/{id}/assignment/reset', [PutawayController::class, 'resetAssignment'])->middleware('role_or_permission:owner|delete-penempatan')->name('putaway.resetAssignment');
        Route::post('/{id}/start', [PutawayController::class, 'start'])->name('putaway.start')->middleware('role_or_permission:owner|edit-penempatan');
        Route::post('/{id}/items/{itemId}/process', [PutawayController::class, 'processItem'])->name('putaway.processItem')->middleware('role_or_permission:owner|edit-penempatan');
        Route::patch('/{id}/items/{itemId}/notes', [PutawayController::class, 'updateItemNotes'])->name('putaway.updateItemNotes')->middleware('role_or_permission:owner|edit-penempatan');
        Route::delete('/{id}/placements', [PutawayController::class, 'deletePlacements'])->middleware('role_or_permission:owner|delete-penempatan')->name('putaway.deletePlacements');
        Route::delete('/{id}/items/{itemId}/placements/{placementId}', [PutawayController::class, 'deletePlacement'])->middleware('role_or_permission:owner|delete-penempatan')->name('putaway.deletePlacement');
        Route::post('/{id}/complete', [PutawayController::class, 'complete'])->middleware('role_or_permission:owner|edit-penempatan')->name('putaway.complete');
        Route::delete('/{id}', [PutawayController::class, 'destroy'])->middleware('role_or_permission:owner|delete-penempatan')->name('putaway.destroy');
    });

    Route::prefix('inventory/stock-replenishment')->group(function () {
        Route::get('/', [StockReplenishmentController::class, 'index'])->middleware('role_or_permission:owner|view-permintaan-restock')->name('inventory.stockReplenishment.index');
        Route::get('/pending-count', [StockReplenishmentController::class, 'pendingCount'])->name('inventory.stockReplenishment.pendingCount')->middleware('role_or_permission:owner|view-permintaan-restock');
        Route::post('/', [StockReplenishmentController::class, 'store'])->middleware('role_or_permission:owner|create-permintaan-restock')->name('inventory.stockReplenishment.store');
        Route::post('/queue', [StockReplenishmentController::class, 'queueFromMonitor'])->middleware('role_or_permission:owner|create-permintaan-restock')->name('inventory.stockReplenishment.queue');
        Route::get('/{id}', [StockReplenishmentController::class, 'show'])->middleware('role_or_permission:owner|view-permintaan-restock')->name('inventory.stockReplenishment.show');
        Route::post('/{id}/accept', [StockReplenishmentController::class, 'accept'])->middleware('role_or_permission:owner|edit-permintaan-restock')->name('inventory.stockReplenishment.accept');
        Route::post('/{id}/reject', [StockReplenishmentController::class, 'reject'])->middleware('role_or_permission:owner|edit-permintaan-restock')->name('inventory.stockReplenishment.reject');
        Route::post('/{id}/items', [StockReplenishmentController::class, 'addItem'])->middleware('role_or_permission:owner|edit-permintaan-restock')->name('inventory.stockReplenishment.items.add');
        Route::patch('/{id}/items/{itemId}', [StockReplenishmentController::class, 'updateItem'])->middleware('role_or_permission:owner|edit-permintaan-restock')->name('inventory.stockReplenishment.items.update');
        Route::delete('/{id}/items/{itemId}', [StockReplenishmentController::class, 'removeItem'])->middleware('role_or_permission:owner|delete-permintaan-restock')->name('inventory.stockReplenishment.items.remove');
    });
});
