<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\SalesReturnController;
use Modules\Sales\Http\Controllers\SalesOrderController;
use Modules\Sales\Http\Controllers\SalesInvoiceController;
use Modules\Sales\Http\Controllers\SalesPaymentController;
use Modules\Sales\Http\Controllers\SalesSettlementController;
use Modules\Sales\Http\Controllers\SalesReturnSettlementController;
use Modules\Outbound\Http\Controllers\PacklistController;
use Modules\Outbound\Http\Controllers\PicklistController;
use Modules\Outbound\Http\Controllers\ShipmentController;
use Modules\Outbound\Http\Controllers\OutboundFulfillmentController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    // ─── Sales Return Items (literal routes FIRST) ───
    Route::get('sales/returns/items/rejected', [SalesReturnController::class, 'rejectedItems'])->name('sales.returns.items.rejected');
    Route::get('sales/returns/items/resolved', [SalesReturnController::class, 'resolvedItems'])->name('sales.returns.items.resolved');
    Route::get('sales/returns/items', [SalesReturnController::class, 'allItems'])->name('sales.returns.items.index');

    // ─── Sales Returns ───
    Route::get('sales/sales-returns/unpaid', [SalesReturnController::class, 'unpaid'])->name('sales.returns.unpaid');
    Route::get('sales/returns/unprocessed', [SalesReturnController::class, 'unprocessed'])->name('sales.returns.unprocessed');
    Route::get('sales/returns', [SalesReturnController::class, 'index'])->name('sales.returns.index');
    Route::post('sales/returns', [SalesReturnController::class, 'store'])->name('sales.returns.store');
    Route::get('sales/returns/{id}', [SalesReturnController::class, 'show'])->name('sales.returns.show');
    Route::post('sales/returns/{id}/accept', [SalesReturnController::class, 'accept'])->name('sales.returns.accept');
    Route::post('sales/returns/{id}/reject', [SalesReturnController::class, 'reject'])->name('sales.returns.reject');
    Route::post('sales/returns/{id}/complete', [SalesReturnController::class, 'complete'])->name('sales.returns.complete');

    // ─── Sales Invoices (literal routes FIRST) ───
    Route::get('sales/invoices/unpaid', [SalesInvoiceController::class, 'unpaid'])->name('sales.invoices.unpaid');
    Route::get('sales/invoices/overdue', [SalesInvoiceController::class, 'overdue'])->name('sales.invoices.overdue');
    Route::get('sales/invoices/summary', [SalesInvoiceController::class, 'summary'])->name('sales.invoices.summary');
    Route::get('sales/invoices/for-return-wms/{contact_id}', [SalesInvoiceController::class, 'forReturnWms'])->name('sales.invoices.for-return-wms');
    Route::get('sales/invoices', [SalesInvoiceController::class, 'index'])->name('sales.invoices.index');
    Route::post('sales/invoices', [SalesInvoiceController::class, 'store'])->name('sales.invoices.store');
    Route::get('sales/invoices/{id}', [SalesInvoiceController::class, 'show'])->name('sales.invoices.show');

    // ─── Sales Payments ───
    Route::get('sales/payments', [SalesPaymentController::class, 'index'])->name('sales.payments.index');
    Route::post('sales/payments', [SalesPaymentController::class, 'store'])->name('sales.payments.store');
    Route::get('sales/payments/{id}', [SalesPaymentController::class, 'show'])->name('sales.payments.show');
    Route::delete('sales/payments', [SalesPaymentController::class, 'destroy'])->name('sales.payments.destroy');

    // ─── Sales Settlements ───
    Route::get('sales/settlements', [SalesSettlementController::class, 'index'])->name('sales.settlements.index');
    Route::get('sales/settlements/{id}', [SalesSettlementController::class, 'show'])->name('sales.settlements.show');

    // ─── Sales Return Settlements (sub-resources first) ───
    Route::get('sales/return-settlements/invoices', [SalesReturnSettlementController::class, 'invoiceIndex'])->name('sales.return-settlements.invoices.index');
    Route::post('sales/return-settlements/invoices', [SalesReturnSettlementController::class, 'invoiceStore'])->name('sales.return-settlements.invoices.store');
    Route::get('sales/return-settlements/invoices/{id}', [SalesReturnSettlementController::class, 'invoiceShow'])->name('sales.return-settlements.invoices.show');
    Route::get('sales/return-settlements/refunds', [SalesReturnSettlementController::class, 'refundIndex'])->name('sales.return-settlements.refunds.index');
    Route::post('sales/return-settlements/refunds', [SalesReturnSettlementController::class, 'refundStore'])->name('sales.return-settlements.refunds.store');
    Route::get('sales/return-settlements/refunds/{id}', [SalesReturnSettlementController::class, 'refundShow'])->name('sales.return-settlements.refunds.show');
    Route::get('sales/return-settlements', [SalesReturnSettlementController::class, 'index'])->name('sales.return-settlements.index');
    Route::delete('sales/return-settlements', [SalesReturnSettlementController::class, 'destroy'])->name('sales.return-settlements.destroy');

    // ─── Packlist / Picklist / Shipment aliases (§9f — Outbound delegation) ───
    Route::get('sales/packlists/shipped', fn (Request $request) => app(OutboundFulfillmentController::class)->ordersByStage('shipped', $request))->name('sales.packlists.shipped');
    Route::post('sales/packlists/create-invoice', [SalesInvoiceController::class, 'createFromOrder'])->name('sales.packlists.create-invoice');
    Route::post('sales/packlists/create-invoice-payment', [SalesInvoiceController::class, 'createFromOrderWithPayment'])->name('sales.packlists.create-invoice-payment');
    Route::get('sales/packlists', fn (Request $request) => app(PacklistController::class)->index($request))->name('sales.packlists.index');
    Route::get('sales/packlists/{id}', fn (Request $request, string $id) => app(PacklistController::class)->show($id))->name('sales.packlists.show');

    Route::post('sales/picklists/items-to-pick', fn (Request $request) => app(PicklistController::class)->items($request->input('picklist_id'), $request))->name('sales.picklists.items-to-pick');
    Route::delete('sales/picklists/to-ship', fn (Request $request) => app(PicklistController::class)->destroy($request->input('id')))->name('sales.picklists.to-ship.destroy');
    Route::get('sales/picklists/{picklist_id}', fn (Request $request, string $picklist_id) => app(PicklistController::class)->items($picklist_id, $request))->name('sales.picklists.show');

    Route::post('sales/shipments/orders', fn (Request $request) => app(ShipmentController::class)->addOrders($request->input('shipment_id'), $request))->name('sales.shipments.orders');
    Route::post('sales/shipments', fn (Request $request) => app(ShipmentController::class)->handOver($request->input('shipment_id')))->name('sales.shipments.handover');
    Route::get('sales/shipments/{shipment_header_id}', fn (Request $request, string $shipment_header_id) => app(ShipmentController::class)->show($shipment_header_id))->name('sales.shipments.show');

    // ─── Sales Orders (literal action routes FIRST, then CRUD wildcard last) ───
    Route::get('sales/orders/cancel', [SalesOrderController::class, 'cancelled'])->name('sales.orders.cancelled');
    Route::get('sales/orders/completed', [SalesOrderController::class, 'completed'])->name('sales.orders.completed');
    Route::get('sales/orders/failed', [SalesOrderController::class, 'failed'])->name('sales.orders.failed');
    Route::get('sales/orders/returned-list', [SalesOrderController::class, 'returnedList'])->name('sales.orders.returned-list');
    Route::post('sales/orders/delete-canceled', [SalesOrderController::class, 'deleteCanceled'])->name('sales.orders.delete-canceled');
    Route::post('sales/orders/mark-as-complete', [SalesOrderController::class, 'markAsComplete'])->name('sales.orders.mark-as-complete');
    Route::post('sales/orders/save-airwaybill', [SalesOrderController::class, 'saveAirwaybill'])->name('sales.orders.save-airwaybill');
    Route::post('sales/orders/save-received-date', [SalesOrderController::class, 'saveReceivedDate'])->name('sales.orders.save-received-date');
    Route::post('sales/orders/set-as-paid', [SalesOrderController::class, 'setAsPaid'])->name('sales.orders.set-as-paid');
    Route::post('sales/request-awb-order', [SalesOrderController::class, 'requestAwb'])->name('sales.request-awb-order');
    Route::get('sales/unfullfilled', [SalesOrderController::class, 'unfulfilled'])->name('sales.unfulfilled');

    // Sales Orders CRUD (wildcard {id} routes — must be LAST)
    Route::get('sales', [SalesOrderController::class, 'index'])->name('sales.index');
    Route::post('sales', [SalesOrderController::class, 'store'])->name('sales.store');
    Route::get('sales/{id}', [SalesOrderController::class, 'show'])->name('sales.show');
    Route::match(['put', 'patch'], 'sales/{id}', [SalesOrderController::class, 'update'])->name('sales.update');
    Route::delete('sales/{id}', [SalesOrderController::class, 'destroy'])->name('sales.destroy');
});
