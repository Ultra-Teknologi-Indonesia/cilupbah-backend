<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\InternalStoreController;
use Modules\Sales\Http\Controllers\SalesOrderManualController;
use Modules\Sales\Http\Controllers\SalesReturnController;
use Modules\Sales\Http\Controllers\SalesOrderActivityController;
use Modules\Sales\Http\Controllers\SalesOrderController;
use Modules\Sales\Http\Controllers\SalesOrderImportController;
use Modules\Sales\Http\Controllers\SalesInvoiceController;
use Modules\Sales\Http\Controllers\SalesPaymentController;
use Modules\Sales\Http\Controllers\SalesSettlementController;
use Modules\Sales\Http\Controllers\SalesReturnSettlementController;
use Modules\Outbound\Http\Controllers\PacklistController;
use Modules\Outbound\Http\Controllers\PicklistController;
use Modules\Outbound\Http\Controllers\ShipmentController;
use Modules\Outbound\Http\Controllers\OutboundFulfillmentController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::middleware('role_or_permission:owner|view-toko-internal')->group(function () {
        Route::get('sales/internal-stores/all', [InternalStoreController::class, 'all'])->name('sales.internal-stores.all');
    });
    Route::middleware('role_or_permission:owner|edit-toko-internal')->group(function () {
        Route::post('sales/internal-stores/{id}/logo', [InternalStoreController::class, 'uploadLogo'])->whereUuid('id')->name('sales.internal-stores.logo.upload');
    });
    Route::middleware('role_or_permission:owner|delete-toko-internal')->group(function () {
        Route::delete('sales/internal-stores/{id}/logo', [InternalStoreController::class, 'deleteLogo'])->whereUuid('id')->name('sales.internal-stores.logo.delete');
    });
    Route::middleware('role_or_permission:owner|view-toko-internal')->group(function () {
        Route::get('sales/internal-stores', [InternalStoreController::class, 'index'])->name('sales.internal-stores.index');
    });
    Route::middleware('role_or_permission:owner|create-toko-internal')->group(function () {
        Route::post('sales/internal-stores', [InternalStoreController::class, 'store'])->name('sales.internal-stores.store');
    });
    Route::middleware('role_or_permission:owner|view-toko-internal')->group(function () {
        Route::get('sales/internal-stores/{internal_store}', [InternalStoreController::class, 'show'])->whereUuid('internal_store')->name('sales.internal-stores.show');
    });
    Route::middleware('role_or_permission:owner|edit-toko-internal')->group(function () {
        Route::match(['put', 'patch'], 'sales/internal-stores/{internal_store}', [InternalStoreController::class, 'update'])->whereUuid('internal_store')->name('sales.internal-stores.update');
    });
    Route::middleware('role_or_permission:owner|delete-toko-internal')->group(function () {
        Route::delete('sales/internal-stores/{internal_store}', [InternalStoreController::class, 'destroy'])->whereUuid('internal_store')->name('sales.internal-stores.destroy');
    });

    Route::middleware('role_or_permission:owner|create-pesanan')->group(function () {
        Route::post('sales/manual', [SalesOrderManualController::class, 'store'])->name('sales.manual.store');
    });
    Route::get('sales/manual/lookup-sku', [SalesOrderManualController::class, 'lookupSku'])->name('sales.manual.lookup-sku');

    Route::middleware('role_or_permission:owner|view-pengaturan-sistem')->group(function () {
        Route::get('systemsetting/sales-return-setting', [\Modules\Sales\Http\Controllers\SalesReturnSettingController::class, 'index'])->name('sales.returnSetting.index');
    });
    Route::middleware('role_or_permission:owner|edit-pengaturan-sistem')->group(function () {
        Route::post('systemsetting/sales-return-setting', [\Modules\Sales\Http\Controllers\SalesReturnSettingController::class, 'store'])->name('sales.returnSetting.store');
    });

    Route::middleware('role_or_permission:owner|view-retur-penjualan')->group(function () {
        Route::get('sales/returns/items/rejected', [SalesReturnController::class, 'rejectedItems'])->name('sales.returns.items.rejected');
        Route::get('sales/returns/items/resolved', [SalesReturnController::class, 'resolvedItems'])->name('sales.returns.items.resolved');
        Route::get('sales/returns/items', [SalesReturnController::class, 'allItems'])->name('sales.returns.items.index');

        Route::get('sales/sales-returns/unpaid', [SalesReturnController::class, 'unpaid'])->name('sales.returns.unpaid');
        Route::get('sales/returns/unprocessed', [SalesReturnController::class, 'unprocessed'])->name('sales.returns.unprocessed');
    });
    Route::middleware('role_or_permission:owner|export-retur-penjualan')->group(function () {
        Route::get('sales/returns/channel-online/export', [SalesReturnController::class, 'exportChannelOnline'])->name('sales.returns.channel-online.export');
        Route::get('sales/returns/report/export', [SalesReturnController::class, 'reportExport'])->name('sales.returns.report.export');
    });
    Route::middleware('role_or_permission:owner|view-retur-penjualan')->group(function () {
        Route::get('sales/returns/report', [SalesReturnController::class, 'report'])->name('sales.returns.report');
        Route::get('sales/returns', [SalesReturnController::class, 'index'])->name('sales.returns.index');
    });
    Route::middleware('role_or_permission:owner|create-retur-penjualan')->group(function () {
        Route::post('sales/returns', [SalesReturnController::class, 'store'])->name('sales.returns.store');
    });
    Route::middleware('role_or_permission:owner|view-retur-penjualan')->group(function () {
        Route::get('sales/returns/{id}', [SalesReturnController::class, 'show'])->whereUuid('id')->name('sales.returns.show');
    });
    Route::middleware('role_or_permission:owner|edit-retur-penjualan')->group(function () {
        Route::post('sales/returns/{id}/accept', [SalesReturnController::class, 'accept'])->whereUuid('id')->name('sales.returns.accept');
        Route::post('sales/returns/{id}/reject', [SalesReturnController::class, 'reject'])->whereUuid('id')->name('sales.returns.reject');
        Route::post('sales/returns/{id}/complete', [SalesReturnController::class, 'complete'])->whereUuid('id')->name('sales.returns.complete');
        Route::post('sales/returns/{id}/sync-tracking', [SalesReturnController::class, 'syncTracking'])->whereUuid('id')->name('sales.returns.sync-tracking');
        Route::post('sales/returns/{id}/sync-detail', [SalesReturnController::class, 'syncDetail'])->whereUuid('id')->name('sales.returns.sync-detail');
    });
    Route::middleware('role_or_permission:owner|view-retur-penjualan')->group(function () {
        Route::get('sales/returns/{id}/appeals', [SalesReturnController::class, 'appeals'])->whereUuid('id')->name('sales.returns.appeals');
    });
    Route::middleware('role_or_permission:owner|edit-retur-penjualan')->group(function () {
        Route::post('sales/returns/{id}/channel-accept', [SalesReturnController::class, 'channelAccept'])->whereUuid('id')->name('sales.returns.channel-accept');
        Route::post('sales/returns/{id}/channel-reject', [SalesReturnController::class, 'channelReject'])->whereUuid('id')->name('sales.returns.channel-reject');
    });
    Route::middleware('role_or_permission:owner|view-retur-penjualan')->group(function () {
        Route::get('sales/returns/{id}/channel-reject-reasons', [SalesReturnController::class, 'channelRejectReasons'])->whereUuid('id')->name('sales.returns.channel-reject-reasons');
    });

    Route::middleware('role_or_permission:owner|view-faktur-penjualan')->group(function () {
        Route::get('sales/invoices/unpaid', [SalesInvoiceController::class, 'unpaid'])->name('sales.invoices.unpaid');
        Route::get('sales/invoices/overdue', [SalesInvoiceController::class, 'overdue'])->name('sales.invoices.overdue');
        Route::get('sales/invoices/summary', [SalesInvoiceController::class, 'summary'])->name('sales.invoices.summary');
        Route::get('sales/invoices/for-return-wms/{contact_id}', [SalesInvoiceController::class, 'forReturnWms'])->whereUuid('contact_id')->name('sales.invoices.for-return-wms');
        Route::get('sales/invoices', [SalesInvoiceController::class, 'index'])->name('sales.invoices.index');
    });
    Route::middleware('role_or_permission:owner|create-faktur-penjualan')->group(function () {
        Route::post('sales/invoices', [SalesInvoiceController::class, 'store'])->name('sales.invoices.store');
    });
    Route::middleware('role_or_permission:owner|view-faktur-penjualan')->group(function () {
        Route::get('sales/invoices/{id}', [SalesInvoiceController::class, 'show'])->whereUuid('id')->name('sales.invoices.show');
    });

    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/payments', [SalesPaymentController::class, 'index'])->name('sales.payments.index');
    });
    Route::middleware('role_or_permission:owner|create-pembayaran-penjualan')->group(function () {
        Route::post('sales/payments', [SalesPaymentController::class, 'store'])->name('sales.payments.store');
    });
    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/payments/{id}', [SalesPaymentController::class, 'show'])->whereUuid('id')->name('sales.payments.show');
    });
    Route::middleware('role_or_permission:owner|delete-pembayaran-penjualan')->group(function () {
        Route::delete('sales/payments', [SalesPaymentController::class, 'destroy'])->name('sales.payments.destroy');
    });

    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/settlements', [SalesSettlementController::class, 'index'])->name('sales.settlements.index');
        Route::get('sales/settlements/{id}', [SalesSettlementController::class, 'show'])->whereUuid('id')->name('sales.settlements.show');
    });

    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/return-settlements/invoices', [SalesReturnSettlementController::class, 'invoiceIndex'])->name('sales.return-settlements.invoices.index');
    });
    Route::middleware('role_or_permission:owner|create-pembayaran-penjualan')->group(function () {
        Route::post('sales/return-settlements/invoices', [SalesReturnSettlementController::class, 'invoiceStore'])->name('sales.return-settlements.invoices.store');
    });
    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/return-settlements/invoices/{id}', [SalesReturnSettlementController::class, 'invoiceShow'])->whereUuid('id')->name('sales.return-settlements.invoices.show');
    });
    Route::middleware('role_or_permission:owner|delete-pembayaran-penjualan')->group(function () {
        Route::delete('sales/return-settlements/invoices/{id}', [SalesReturnSettlementController::class, 'invoiceDestroy'])->whereUuid('id')->name('sales.return-settlements.invoices.destroy');
    });
    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/return-settlements/refunds', [SalesReturnSettlementController::class, 'refundIndex'])->name('sales.return-settlements.refunds.index');
    });
    Route::middleware('role_or_permission:owner|create-pembayaran-penjualan')->group(function () {
        Route::post('sales/return-settlements/refunds', [SalesReturnSettlementController::class, 'refundStore'])->name('sales.return-settlements.refunds.store');
    });
    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/return-settlements/refunds/{id}', [SalesReturnSettlementController::class, 'refundShow'])->whereUuid('id')->name('sales.return-settlements.refunds.show');
    });
    Route::middleware('role_or_permission:owner|delete-pembayaran-penjualan')->group(function () {
        Route::delete('sales/return-settlements/refunds/{id}', [SalesReturnSettlementController::class, 'refundDestroy'])->whereUuid('id')->name('sales.return-settlements.refunds.destroy');
    });
    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/return-settlements', [SalesReturnSettlementController::class, 'index'])->name('sales.return-settlements.index');
    });
    Route::middleware('role_or_permission:owner|create-pembayaran-penjualan')->group(function () {
        Route::post('sales/return-settlements', [SalesReturnSettlementController::class, 'store'])->name('sales.return-settlements.store');
    });
    Route::middleware('role_or_permission:owner|view-pembayaran-penjualan')->group(function () {
        Route::get('sales/return-settlements/{id}', [SalesReturnSettlementController::class, 'show'])->whereUuid('id')->name('sales.return-settlements.show');
    });
    Route::middleware('role_or_permission:owner|edit-pembayaran-penjualan')->group(function () {
        Route::post('sales/return-settlements/{id}/confirm', [SalesReturnSettlementController::class, 'confirm'])->whereUuid('id')->name('sales.return-settlements.confirm');
        Route::post('sales/return-settlements/{id}/complete', [SalesReturnSettlementController::class, 'complete'])->whereUuid('id')->name('sales.return-settlements.complete');
    });
    Route::middleware('role_or_permission:owner|delete-pembayaran-penjualan')->group(function () {
        Route::delete('sales/return-settlements/{id}', [SalesReturnSettlementController::class, 'destroy'])->whereUuid('id')->name('sales.return-settlements.destroy');
    });

    Route::get('sales/packlists/shipped', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('shipped', $request))->name('sales.packlists.shipped');
    Route::middleware('role_or_permission:owner|create-faktur-penjualan')->group(function () {
        Route::post('sales/packlists/create-invoice', [SalesInvoiceController::class, 'createFromOrder'])->name('sales.packlists.create-invoice');
        Route::post('sales/packlists/create-invoice-payment', [SalesInvoiceController::class, 'createFromOrderWithPayment'])->name('sales.packlists.create-invoice-payment');
    });
    Route::get('sales/packlists', fn (Request $request) => app(PacklistController::class)->index($request))->name('sales.packlists.index');
    Route::get('sales/packlists/{id}', fn (Request $request, string $id) => app(PacklistController::class)->show($id))->whereUuid('id')->name('sales.packlists.show');

    Route::post('sales/picklists/items-to-pick', fn (Request $request) => app(PicklistController::class)->items($request->input('picklist_id'), $request))->name('sales.picklists.items-to-pick');
    Route::delete('sales/picklists/to-ship', fn (Request $request) => app(PicklistController::class)->destroy($request->input('id')))->name('sales.picklists.to-ship.destroy');
    Route::get('sales/picklists/{picklist_id}', fn (Request $request, string $picklist_id) => app(PicklistController::class)->items($picklist_id, $request))->whereUuid('picklist_id')->name('sales.picklists.show');

    Route::post('sales/shipments/orders', fn (Request $request) => app(ShipmentController::class)->addOrders($request->input('shipment_id'), $request))->name('sales.shipments.orders');
    Route::post('sales/shipments', fn (Request $request) => app(ShipmentController::class)->handOver($request->input('shipment_id')))->name('sales.shipments.handover');
    Route::get('sales/shipments/{shipment_header_id}', fn (Request $request, string $shipment_header_id) => app(ShipmentController::class)->show($shipment_header_id))->whereUuid('shipment_header_id')->name('sales.shipments.show');

    Route::middleware('role_or_permission:owner|export-pesanan')->group(function () {
        Route::get('sales/orders/export', [SalesOrderController::class, 'export'])->name('sales.orders.export');
    });

    Route::middleware('role_or_permission:owner|import-pesanan')->group(function () {
        Route::get('sales/orders/import/template', [SalesOrderImportController::class, 'downloadTemplate'])->name('sales.orders.import.template');
        Route::post('sales/orders/import', [SalesOrderImportController::class, 'import'])->name('sales.orders.import');
        Route::get('sales/orders/import/batches', [SalesOrderImportController::class, 'batches'])->name('sales.orders.import.batches');
        Route::get('sales/orders/import/batches/{batch}', [SalesOrderImportController::class, 'show'])->whereUuid('batch')->name('sales.orders.import.batches.show');
        Route::get('sales/orders/import/batches/{batch}/errors', [SalesOrderImportController::class, 'errors'])->whereUuid('batch')->name('sales.orders.import.batches.errors');
        Route::get('sales/orders/import/batches/{batch}/errors/download', [SalesOrderImportController::class, 'downloadErrors'])->whereUuid('batch')->name('sales.orders.import.batches.errors.download');
    });

    Route::middleware('role_or_permission:owner|view-pesanan')->group(function () {
        Route::get('sales/orders/cancel', [SalesOrderController::class, 'cancelled'])->name('sales.orders.cancelled');
    });
    Route::middleware('role_or_permission:owner|export-pesanan')->group(function () {
        Route::get('sales/orders/cancelled/export', [SalesOrderController::class, 'exportCancelled'])->name('sales.orders.cancelled.export');
    });
    Route::middleware('role_or_permission:owner|view-pesanan')->group(function () {
        Route::get('sales/orders/completed', [SalesOrderController::class, 'completed'])->name('sales.orders.completed');
        Route::get('sales/orders/failed', [SalesOrderController::class, 'failed'])->name('sales.orders.failed');
        Route::get('sales/orders/returned-list', [SalesOrderController::class, 'returnedList'])->name('sales.orders.returned-list');
    });
    Route::middleware('role_or_permission:owner|delete-pesanan')->group(function () {
        Route::post('sales/orders/delete-canceled', [SalesOrderController::class, 'deleteCanceled'])->name('sales.orders.delete-canceled');
    });
    Route::middleware('role_or_permission:owner|edit-pesanan')->group(function () {
        Route::post('sales/orders/move-to-ready', [SalesOrderController::class, 'moveToReadyToProcess'])->name('sales.orders.move-to-ready');
        Route::post('sales/orders/mark-as-complete', [SalesOrderController::class, 'markAsComplete'])->name('sales.orders.mark-as-complete');
        Route::post('sales/orders/save-airwaybill', [SalesOrderController::class, 'saveAirwaybill'])->name('sales.orders.save-airwaybill');
        Route::post('sales/orders/save-received-date', [SalesOrderController::class, 'saveReceivedDate'])->name('sales.orders.save-received-date');
        Route::post('sales/orders/set-as-paid', [SalesOrderController::class, 'setAsPaid'])->name('sales.orders.set-as-paid');
        Route::post('sales/orders/{id}/accept-cancel', [SalesOrderController::class, 'acceptCancelRequest'])->whereUuid('id')->name('sales.orders.accept-cancel');
        Route::post('sales/orders/{id}/reject-cancel', [SalesOrderController::class, 'rejectCancelRequest'])->whereUuid('id')->name('sales.orders.reject-cancel');
        Route::post('sales/orders/bulk-mark-contacted', [SalesOrderController::class, 'bulkMarkContacted'])->name('sales.orders.bulk-mark-contacted');
        Route::post('sales/orders/{id}/mark-contacted', [SalesOrderController::class, 'markContacted'])->whereUuid('id')->name('sales.orders.mark-contacted');
        Route::post('sales/orders/{id}/customer-decision', [SalesOrderController::class, 'setCustomerDecision'])->whereUuid('id')->name('sales.orders.customer-decision');
        Route::patch('sales/orders/{id}/items/{itemId}', [SalesOrderController::class, 'updateItem'])->whereUuid('id')->whereUuid('itemId')->name('sales.orders.items.update');
        Route::delete('sales/orders/{id}/items/{itemId}', [SalesOrderController::class, 'deleteItem'])->whereUuid('id')->whereUuid('itemId')->name('sales.orders.items.destroy');
        Route::post('sales/request-awb-order', [SalesOrderController::class, 'requestAwb'])->name('sales.request-awb-order');
    });
    Route::middleware('role_or_permission:owner|view-pesanan')->group(function () {
        Route::get('sales/unfullfilled', [SalesOrderController::class, 'unfulfilled'])->name('sales.unfulfilled');
        Route::get('sales/counts', [SalesOrderController::class, 'counts'])->name('sales.counts');
    });

    Route::middleware('role_or_permission:owner|view-pesanan')->group(function () {
        Route::get('sales/{id}/activities', [SalesOrderActivityController::class, 'index'])->whereUuid('id')->name('sales.orders.activities');
        Route::get('sales/{id}/invoice', [SalesOrderController::class, 'invoice'])->whereUuid('id')->name('sales.orders.invoice');
        Route::post('sales/invoices/bulk-pdf', [\Modules\Sales\Http\Controllers\BulkInvoiceController::class, 'bulkPdf'])->name('sales.invoices.bulk-pdf');
        Route::get('sales/{id}/breakdown', [SalesOrderController::class, 'breakdown'])->whereUuid('id')->name('sales.orders.breakdown');
        Route::get('sales/{id}/shipping-label', [SalesOrderController::class, 'getShippingLabel'])->whereUuid('id')->name('sales.orders.shipping-label');
    });
    Route::middleware('role_or_permission:owner|edit-pesanan')->group(function () {
        Route::post('sales/{id}/shipping-label/retry', [SalesOrderController::class, 'retryShippingLabel'])->whereUuid('id')->name('sales.orders.shipping-label.retry');

        Route::post('sales/shipping-labels/bulk', [\Modules\Sales\Http\Controllers\BulkShippingLabelController::class, 'store'])
            ->name('sales.shipping-labels.bulk.store');
        Route::get('sales/shipping-labels/bulk/{batch}', [\Modules\Sales\Http\Controllers\BulkShippingLabelController::class, 'show'])
            ->whereUuid('batch')
            ->name('sales.shipping-labels.bulk.show');
        Route::get('sales/shipping-labels/bulk/{batch}/pdf', [\Modules\Sales\Http\Controllers\BulkShippingLabelController::class, 'downloadPdf'])
            ->whereUuid('batch')
            ->name('sales.shipping-labels.bulk.pdf');
        Route::post('sales/shipping-labels/bulk/{batch}/retry-failed', [\Modules\Sales\Http\Controllers\BulkShippingLabelController::class, 'retryFailed'])
            ->whereUuid('batch')
            ->name('sales.shipping-labels.bulk.retry-failed');
        Route::post('sales/{id}/print-with-driver-call', [SalesOrderController::class, 'printWithDriverCall'])->whereUuid('id')->name('sales.orders.print-with-driver-call');
        Route::post('sales/{id}/driver-call/retry', [SalesOrderController::class, 'retryDriverCall'])->whereUuid('id')->name('sales.orders.driver-call.retry');
        Route::put('sales/{id}/relocate', [SalesOrderController::class, 'relocate'])->whereUuid('id')->name('sales.orders.relocate');

        Route::match(['put', 'patch'], 'sales/{id}/courier-pickup', [SalesOrderController::class, 'saveCourierPickup'])->whereUuid('id')->name('sales.orders.courier-pickup.save');
        Route::post('sales/{id}/courier-pickup/photo', [SalesOrderController::class, 'uploadCourierIdPhoto'])->whereUuid('id')->name('sales.orders.courier-pickup.photo.upload');
        Route::delete('sales/{id}/courier-pickup/photo', [SalesOrderController::class, 'deleteCourierIdPhoto'])->whereUuid('id')->name('sales.orders.courier-pickup.photo.delete');

        Route::post('sales/{id}/items/{itemId}/download', [SalesOrderController::class, 'downloadOrderItem'])
            ->whereUuid('id')->whereUuid('itemId')->name('sales.orders.items.download');
    });

    Route::middleware('role_or_permission:owner|view-pesanan')->group(function () {
        Route::get('sales', [SalesOrderController::class, 'index'])->name('sales.index');
    });
    Route::middleware('role_or_permission:owner|create-pesanan')->group(function () {
        Route::post('sales', [SalesOrderController::class, 'store'])->name('sales.store');
    });
    Route::middleware('role_or_permission:owner|view-pesanan')->group(function () {
        Route::get('sales/{id}', [SalesOrderController::class, 'show'])->whereUuid('id')->name('sales.show');
    });
    Route::middleware('role_or_permission:owner|edit-pesanan')->group(function () {
        Route::match(['put', 'patch'], 'sales/{id}', [SalesOrderController::class, 'update'])->whereUuid('id')->name('sales.update');
    });
    Route::middleware('role_or_permission:owner|delete-pesanan')->group(function () {
        Route::delete('sales/{id}', [SalesOrderController::class, 'destroy'])->whereUuid('id')->name('sales.destroy');
    });
});
