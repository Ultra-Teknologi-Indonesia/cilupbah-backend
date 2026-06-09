<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\SalesReturnController;
use Modules\Sales\Http\Controllers\SalesOrderController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('sales/returns', [SalesReturnController::class, 'index'])->name('sales.returns.index');
    Route::get('sales/returns/unprocessed', [SalesReturnController::class, 'unprocessed'])->name('sales.returns.unprocessed');
    Route::get('sales/returns/{id}', [SalesReturnController::class, 'show'])->name('sales.returns.show');
    Route::post('sales/returns', [SalesReturnController::class, 'store'])->name('sales.returns.store');
    Route::post('sales/returns/{id}/accept', [SalesReturnController::class, 'accept'])->name('sales.returns.accept');
    Route::post('sales/returns/{id}/reject', [SalesReturnController::class, 'reject'])->name('sales.returns.reject');
    Route::post('sales/returns/{id}/complete', [SalesReturnController::class, 'complete'])->name('sales.returns.complete');

    // Sales Orders (didaftarkan setelah route literal `sales/returns*` agar tidak tertangkap oleh `sales/{id}`)
    Route::get('sales', [SalesOrderController::class, 'index'])->name('sales.index');
    Route::post('sales', [SalesOrderController::class, 'store'])->name('sales.store');
    Route::get('sales/{id}', [SalesOrderController::class, 'show'])->name('sales.show');
    Route::match(['put', 'patch'], 'sales/{id}', [SalesOrderController::class, 'update'])->name('sales.update');
    Route::delete('sales/{id}', [SalesOrderController::class, 'destroy'])->name('sales.destroy');
});
