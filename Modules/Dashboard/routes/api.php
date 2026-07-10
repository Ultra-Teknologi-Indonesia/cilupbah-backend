<?php

use Illuminate\Support\Facades\Route;
use Modules\Dashboard\Http\Controllers\DashboardController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role_or_permission:owner|view-dashboard')->group(function () {
        Route::get('dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
        Route::get('dashboard/queues/{queue}', [DashboardController::class, 'queue'])->name('dashboard.queue');
    });
});
