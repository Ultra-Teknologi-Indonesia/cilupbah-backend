<?php

use Illuminate\Support\Facades\Route;
use Modules\Tax\Http\Controllers\TaxController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role_or_permission:owner|view-pajak')->group(function () {
        Route::get('taxes', [TaxController::class, 'index'])->name('tax.index');
        Route::get('taxes/{tax}', [TaxController::class, 'show'])->name('tax.show');
    });

    Route::middleware('role_or_permission:owner|edit-pajak')->group(function () {
        Route::post('taxes', [TaxController::class, 'store'])->name('tax.store');
        Route::match(['put', 'patch'], 'taxes/{tax}', [TaxController::class, 'update'])->name('tax.update');
        Route::delete('taxes/{tax}', [TaxController::class, 'destroy'])->name('tax.destroy');
    });
});
