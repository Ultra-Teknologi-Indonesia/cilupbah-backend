<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchase\Http\Controllers\PurchaseOrderController;
use Modules\Purchase\Http\Controllers\PurchaseOrderActivityController;
use Modules\Purchase\Http\Controllers\PurchaseBillController;
use Modules\Purchase\Http\Controllers\PurchasePaymentController;
use Modules\Purchase\Http\Controllers\PurchaseSerialNumberController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('purchase/serial-number/wms/{bill_detail_id}', [PurchaseSerialNumberController::class, 'wmsByBillDetail'])->name('purchase.serial-number.wms');
    Route::middleware('role_or_permission:owner|edit-transaksi-pembelian')->group(function () {
        Route::post('purchase/serial-number/mark-printed', [PurchaseSerialNumberController::class, 'markPrinted'])->name('purchase.serial-number.mark-printed');
    });


    Route::middleware('role_or_permission:owner|view-pembayaran-pembelian')->group(function () {
        Route::get('purchase/payments', [PurchasePaymentController::class, 'index'])->name('purchase.payments.index');
        Route::get('purchase/payments/{id}', [PurchasePaymentController::class, 'show'])->name('purchase.payments.show');
    });
    Route::middleware('role_or_permission:owner|create-pembayaran-pembelian')->group(function () {
        Route::post('purchase/payments', [PurchasePaymentController::class, 'store'])->name('purchase.payments.store');
    });
    Route::middleware('role_or_permission:owner|delete-pembayaran-pembelian')->group(function () {
        Route::delete('purchase/payments', [PurchasePaymentController::class, 'destroy'])->name('purchase.payments.destroy');
    });

    Route::middleware('role_or_permission:owner|view-pembayaran-pembelian')->group(function () {
        Route::get('purchase/bills/unpaid', [PurchaseBillController::class, 'unpaid'])->name('purchase.bills.unpaid');
        Route::get('purchase/bills/overdue', [PurchaseBillController::class, 'overdue'])->name('purchase.bills.overdue');
    });

    Route::middleware('role_or_permission:owner|view-transaksi-pembelian')->group(function () {
        Route::get('purchase/orders/receivable', [PurchaseOrderController::class, 'receivable'])->name('purchase.orders.receivable');
        Route::get('purchase/orders/progress', [PurchaseOrderController::class, 'receivable'])->name('purchase.orders.progress');
        Route::get('purchase/orders', [PurchaseOrderController::class, 'index'])->name('purchase.orders.index');
        Route::get('purchase/orders/{id}', [PurchaseOrderController::class, 'show'])->name('purchase.orders.show');
        Route::get('purchase/orders/{id}/items', [PurchaseOrderController::class, 'items'])->name('purchase.orders.items');
        Route::get('purchase/orders/{id}/pdf', [PurchaseOrderController::class, 'downloadPdf'])->name('purchase.orders.pdf');
        Route::get('purchase/orders/{id}/activities', [PurchaseOrderActivityController::class, 'index'])->name('purchase.orders.activities');
    });
    Route::middleware('role_or_permission:owner|create-transaksi-pembelian')->group(function () {
        Route::post('purchase/orders', [PurchaseOrderController::class, 'store'])->name('purchase.orders.store');
    });
    Route::middleware('role_or_permission:owner|edit-transaksi-pembelian')->group(function () {
        Route::put('purchase/orders/{id}', [PurchaseOrderController::class, 'update'])->name('purchase.orders.update');
        Route::post('purchase/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase.orders.cancel');
    });
    Route::middleware('role_or_permission:owner|receive-transaksi-pembelian')->group(function () {
        Route::post('purchase/orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase.orders.receive');
    });
    Route::middleware('role_or_permission:owner|delete-transaksi-pembelian')->group(function () {
        Route::post('purchase/orders/bulk-delete', [PurchaseOrderController::class, 'bulkDelete'])->name('purchase.orders.bulk-delete');
        Route::delete('purchase/orders/{id}', [PurchaseOrderController::class, 'destroy'])->name('purchase.orders.destroy');
    });
});
