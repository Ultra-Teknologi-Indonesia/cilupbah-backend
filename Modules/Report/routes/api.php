<?php

use Illuminate\Support\Facades\Route;
use Modules\Report\Http\Controllers\ReportController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role_or_permission:owner|view-laporan-persediaan')->group(function () {
        Route::get('reports/putaway', [ReportController::class, 'putaway'])->name('reports.putaway');
        Route::get('reports/receive', [ReportController::class, 'receive'])->name('reports.receive');
        Route::get('reports/adjustment', [ReportController::class, 'adjustment'])->name('reports.adjustment');
        Route::get('reports/stock-opname', [ReportController::class, 'stockOpname'])->name('reports.stock-opname');
    });
    Route::middleware('role_or_permission:owner|view-laporan-pembelian')->group(function () {
        Route::get('reports/purchaseorder', [ReportController::class, 'purchaseOrder'])->name('reports.purchaseorder');
        Route::get('reports/invoice', [ReportController::class, 'invoice'])->name('reports.invoice');
        Route::get('reports/consign', [ReportController::class, 'consign'])->name('reports.consign');
    });
    Route::middleware('role_or_permission:owner|view-laporan-persediaan')->group(function () {
        Route::get('reports/item-receive-notplace', [ReportController::class, 'itemReceiveNotPlace'])->name('reports.item-receive-notplace');
    });
    Route::middleware('role_or_permission:owner|view-laporan-gudang')->group(function () {
        Route::get('reports/wms/pick-list', [ReportController::class, 'pickList'])->name('reports.wms.pick-list');
        Route::get('reports/wms/shipping-manifest', [ReportController::class, 'shippingManifest'])->name('reports.wms.shipping-manifest');
        Route::get('reports/wms/transfer/export', [ReportController::class, 'transferExport'])->name('reports.wms.transfer.export');
        Route::get('reports/wms/pick-list/export', [ReportController::class, 'pickListExport'])->name('reports.wms.pick-list.export');
        Route::get('reports/wms/pick-list/lookup', [ReportController::class, 'pickListLookup'])->name('reports.wms.pick-list.lookup');
        Route::post('reports/wms/pick-list/pdf', [ReportController::class, 'pickListDetailPdf'])->name('reports.wms.pick-list.pdf');
        Route::get('reports/wms/shipment/export', [ReportController::class, 'shipmentListExport'])->name('reports.wms.shipment.export');
        Route::get('reports/wms/shipment/options', [ReportController::class, 'shipmentFilterOptions'])->name('reports.wms.shipment.options');
        Route::post('reports/wms/order-performance/pdf', [ReportController::class, 'orderPerformancePdf'])->name('reports.wms.order-performance.pdf');
        Route::post('reports/wms/putaway-performance/pdf', [ReportController::class, 'putawayPerformancePdf'])->name('reports.wms.putaway-performance.pdf');
    });
    Route::middleware('role_or_permission:owner|view-laporan-hpp')->group(function () {
        Route::get('reports/hpp', [ReportController::class, 'hpp'])->name('reports.hpp');
    });
    Route::middleware('role_or_permission:owner|export-laporan-persediaan')->group(function () {
        Route::post('reports/barcode/pdf', [ReportController::class, 'barcodePdf'])->name('reports.barcode.pdf');
        Route::post('reports/penyesuaian-stok/pdf', [ReportController::class, 'penyesuaianStokPdf'])->name('reports.penyesuaian-stok.pdf');
    });

    Route::middleware('role_or_permission:owner|view-laporan-stok-minus')->group(function () {
        Route::get('reports/negative-stock', [ReportController::class, 'negativeStock'])->name('reports.negative-stock');
    });
    Route::middleware('role_or_permission:owner|export-laporan-stok-minus')->group(function () {
        Route::get('reports/negative-stock/export', [ReportController::class, 'negativeStockExport'])->name('reports.negative-stock.export');
    });

    Route::get('lazada/get-document', [ReportController::class, 'lazadaGetDocument'])->name('lazada.get-document')->middleware('role_or_permission:owner|export-pengiriman');
});
