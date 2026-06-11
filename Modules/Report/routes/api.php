<?php

use Illuminate\Support\Facades\Route;
use Modules\Report\Http\Controllers\ReportController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('reports/putaway', [ReportController::class, 'putaway'])->name('reports.putaway');
    Route::get('reports/receive', [ReportController::class, 'receive'])->name('reports.receive');
    Route::get('reports/adjustment', [ReportController::class, 'adjustment'])->name('reports.adjustment');
    Route::get('reports/stock-opname', [ReportController::class, 'stockOpname'])->name('reports.stock-opname');
    Route::get('reports/purchaseorder', [ReportController::class, 'purchaseOrder'])->name('reports.purchaseorder');
    Route::get('reports/invoice', [ReportController::class, 'invoice'])->name('reports.invoice');
    Route::get('reports/consign', [ReportController::class, 'consign'])->name('reports.consign');
    Route::get('reports/item-receive-notplace', [ReportController::class, 'itemReceiveNotPlace'])->name('reports.item-receive-notplace');
    Route::get('reports/wms/pick-list', [ReportController::class, 'pickList'])->name('reports.wms.pick-list');
    Route::get('reports/wms/shipping-manifest', [ReportController::class, 'shippingManifest'])->name('reports.wms.shipping-manifest');
    Route::get('reports/shipping-label', [ReportController::class, 'shippingLabel'])->name('reports.shipping-label');
    Route::get('reports/lable/print', [ReportController::class, 'labelPrint'])->name('reports.lable.print');

    Route::get('lazada/get-document', [ReportController::class, 'lazadaGetDocument'])->name('lazada.get-document');
});
