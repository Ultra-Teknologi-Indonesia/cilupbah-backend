<?php

use Illuminate\Support\Facades\Route;
use Modules\Sales\Http\Controllers\SalesReturnController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('sales/returns', [SalesReturnController::class, 'index'])->name('sales.returns.index');
    Route::get('sales/returns/unprocessed', [SalesReturnController::class, 'unprocessed'])->name('sales.returns.unprocessed');
    Route::get('sales/returns/{id}', [SalesReturnController::class, 'show'])->name('sales.returns.show');
    Route::post('sales/returns', [SalesReturnController::class, 'store'])->name('sales.returns.store');
    Route::post('sales/returns/{id}/accept', [SalesReturnController::class, 'accept'])->name('sales.returns.accept');
    Route::post('sales/returns/{id}/reject', [SalesReturnController::class, 'reject'])->name('sales.returns.reject');
    Route::post('sales/returns/{id}/complete', [SalesReturnController::class, 'complete'])->name('sales.returns.complete');
});
