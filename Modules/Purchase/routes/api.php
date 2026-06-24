<?php

use Illuminate\Support\Facades\Route;
use Modules\Purchase\Http\Controllers\PurchaseOrderController;
use Modules\Purchase\Http\Controllers\PurchaseBillController;
use Modules\Purchase\Http\Controllers\PurchasePaymentController;
use Modules\Purchase\Http\Controllers\PurchaseReturnController;
use Modules\Purchase\Http\Controllers\PurchaseReturnSettlementController;
use Modules\Purchase\Http\Controllers\PurchaseSerialNumberController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('purchase/serial-number/wms/{bill_detail_id}', [PurchaseSerialNumberController::class, 'wmsByBillDetail'])->name('purchase.serial-number.wms');
    Route::post('purchase/serial-number/mark-printed', [PurchaseSerialNumberController::class, 'markPrinted'])->name('purchase.serial-number.mark-printed');

    Route::get('purchase/return-settlements/bills', [PurchaseReturnSettlementController::class, 'billIndex'])->name('purchase.return-settlements.bills.index');
    Route::post('purchase/return-settlements/bills', [PurchaseReturnSettlementController::class, 'billStore'])->name('purchase.return-settlements.bills.store');
    Route::get('purchase/return-settlements/bills/{id}', [PurchaseReturnSettlementController::class, 'billShow'])->name('purchase.return-settlements.bills.show');
    Route::get('purchase/return-settlements/refunds', [PurchaseReturnSettlementController::class, 'refundIndex'])->name('purchase.return-settlements.refunds.index');
    Route::post('purchase/return-settlements/refunds', [PurchaseReturnSettlementController::class, 'refundStore'])->name('purchase.return-settlements.refunds.store');
    Route::get('purchase/return-settlements/refunds/{id}', [PurchaseReturnSettlementController::class, 'refundShow'])->name('purchase.return-settlements.refunds.show');
    Route::get('purchase/return-settlements', [PurchaseReturnSettlementController::class, 'index'])->name('purchase.return-settlements.index');
    Route::delete('purchase/return-settlements', [PurchaseReturnSettlementController::class, 'destroy'])->name('purchase.return-settlements.destroy');

    Route::get('purchase/purchase-returns/unpaid', [PurchaseReturnController::class, 'unpaid'])->name('purchase.returns.unpaid');
    Route::get('purchase/purchase-returns', [PurchaseReturnController::class, 'index'])->name('purchase.returns.index');
    Route::post('purchase/purchase-returns', [PurchaseReturnController::class, 'store'])->name('purchase.returns.store');
    Route::get('purchase/purchase-returns/{id}', [PurchaseReturnController::class, 'show'])->name('purchase.returns.show');
    Route::delete('purchase', [PurchaseReturnController::class, 'destroy'])->name('purchase.returns.destroy');

    Route::get('purchase/payments', [PurchasePaymentController::class, 'index'])->name('purchase.payments.index');
    Route::post('purchase/payments', [PurchasePaymentController::class, 'store'])->name('purchase.payments.store');
    Route::get('purchase/payments/{id}', [PurchasePaymentController::class, 'show'])->name('purchase.payments.show');
    Route::delete('purchase/payments', [PurchasePaymentController::class, 'destroy'])->name('purchase.payments.destroy');

    Route::get('purchase/bills/unpaid', [PurchaseBillController::class, 'unpaid'])->name('purchase.bills.unpaid');
    Route::get('purchase/bills/overdue', [PurchaseBillController::class, 'overdue'])->name('purchase.bills.overdue');
    Route::get('purchase/bills/for-return', [PurchaseBillController::class, 'forReturn'])->name('purchase.bills.for-return');
    Route::get('purchase/bills', [PurchaseBillController::class, 'index'])->name('purchase.bills.index');
    Route::post('purchase/bills', [PurchaseBillController::class, 'store'])->name('purchase.bills.store');
    Route::get('purchase/bills/{id}', [PurchaseBillController::class, 'show'])->name('purchase.bills.show');
    Route::put('purchase/bills/{id}', [PurchaseBillController::class, 'update'])->name('purchase.bills.update');
    Route::delete('purchase/bills', [PurchaseBillController::class, 'destroy'])->name('purchase.bills.destroy');

    Route::get('purchase/orders/receivable', [PurchaseOrderController::class, 'receivable'])->name('purchase.orders.receivable');
    Route::get('purchase/orders/progress', [PurchaseOrderController::class, 'receivable'])->name('purchase.orders.progress');
    Route::get('purchase/orders', [PurchaseOrderController::class, 'index'])->name('purchase.orders.index');
    Route::post('purchase/orders', [PurchaseOrderController::class, 'store'])->name('purchase.orders.store');
    Route::get('purchase/orders/{id}', [PurchaseOrderController::class, 'show'])->name('purchase.orders.show');
    Route::put('purchase/orders/{id}', [PurchaseOrderController::class, 'update'])->name('purchase.orders.update');
    Route::post('purchase/orders/{id}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase.orders.approve');
    Route::post('purchase/orders/{id}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase.orders.receive');
    Route::post('purchase/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase.orders.cancel');
    Route::delete('purchase/orders/{id}', [PurchaseOrderController::class, 'destroy'])->name('purchase.orders.destroy');
});
