<?php

use App\Http\Controllers\Dev\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Kubernetes probes must not execute the application shell or any dashboard
// query. Keep this endpoint intentionally cheap so a busy API remains Ready.
Route::get('/healthz', static fn () => response()->json(['status' => 'ok']));

Route::prefix('dev/tracking')->middleware('dev.only')->name('dev.tracking.')->group(function () {
    Route::get('/', [TrackingController::class, 'index'])->name('index');
    Route::get('/data', [TrackingController::class, 'data'])->name('data');
    Route::get('/export', [TrackingController::class, 'export'])->name('export');
    Route::patch('/items/{item}', [TrackingController::class, 'update'])->name('update');
});
