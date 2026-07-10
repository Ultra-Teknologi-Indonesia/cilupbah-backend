<?php

use Illuminate\Support\Facades\Route;
use Modules\Warranty\Http\Controllers\WarrantyController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role_or_permission:owner|view-garansi')->group(function () {
        Route::get('warranties', [WarrantyController::class, 'index'])->name('warranties.index');
        Route::get('warranties/{warranty}', [WarrantyController::class, 'show'])->name('warranties.show');
    });

    Route::middleware('role_or_permission:owner|edit-garansi')->group(function () {
        Route::post('warranties', [WarrantyController::class, 'store'])->name('warranties.store');
        Route::match(['put', 'patch'], 'warranties/{warranty}', [WarrantyController::class, 'update'])->name('warranties.update');
        Route::delete('warranties/{warranty}', [WarrantyController::class, 'destroy'])->name('warranties.destroy');
    });
});
