<?php

use App\Http\Controllers\Dev\TrackingController;
use App\Http\Controllers\Operations\StockCutoverConsoleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/healthz', static fn () => response()->json(['status' => 'ok']));

Route::prefix('/_ops/stock-cutover/{token}')
    ->middleware(['stock-cutover-console', 'throttle:10,1'])
    ->where(['token' => '[A-Za-z0-9_-]{48,128}'])
    ->name('operations.stock-cutover.')
    ->group(function (): void {
        Route::get('/', [StockCutoverConsoleController::class, 'index'])->name('index');
        Route::post('/preview', [StockCutoverConsoleController::class, 'preview'])->name('preview');
        Route::get('/jobs/{job}', [StockCutoverConsoleController::class, 'status'])->name('status');
        Route::post('/jobs/{job}/apply', [StockCutoverConsoleController::class, 'apply'])->name('apply');
        Route::get('/jobs/{job}/report', [StockCutoverConsoleController::class, 'download'])->name('report');
        Route::get('/jobs/{job}/report/{location}', [StockCutoverConsoleController::class, 'downloadCsv'])->name('report.csv');
    });

Route::prefix('dev/tracking')->middleware('dev.only')->name('dev.tracking.')->group(function () {
    Route::get('/', [TrackingController::class, 'index'])->name('index');
    Route::get('/data', [TrackingController::class, 'data'])->name('data');
    Route::get('/export', [TrackingController::class, 'export'])->name('export');
    Route::patch('/items/{item}', [TrackingController::class, 'update'])->name('update');
});
