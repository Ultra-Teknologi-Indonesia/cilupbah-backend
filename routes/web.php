<?php

use App\Http\Controllers\Dev\TrackingController;
use App\Http\Controllers\Operations\StockCutoverConsoleController;
use App\Http\Controllers\Operations\OrderCutoverConsoleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/healthz', static fn () => response()->json(['status' => 'ok']));

Route::prefix('/_ops/stock-cutover/{token}')
    ->middleware(['stock-cutover-console'])
    ->where(['token' => '[A-Za-z0-9_-]{48,128}'])
    ->name('operations.stock-cutover.')
    ->group(function (): void {
        Route::get('/', [StockCutoverConsoleController::class, 'index'])
            ->middleware('throttle:stock_cutover_page')
            ->name('index');
        Route::post('/preview', [StockCutoverConsoleController::class, 'preview'])
            ->middleware('throttle:stock_cutover_preview')
            ->name('preview');
        Route::get('/jobs/{job}', [StockCutoverConsoleController::class, 'status'])
            ->middleware('throttle:stock_cutover_status')
            ->name('status');
        Route::post('/jobs/{job}/apply', [StockCutoverConsoleController::class, 'apply'])
            ->middleware('throttle:stock_cutover_apply')
            ->name('apply');
        Route::get('/jobs/{job}/report', [StockCutoverConsoleController::class, 'download'])
            ->middleware('throttle:stock_cutover_report')
            ->name('report');
        Route::get('/jobs/{job}/report/{location}', [StockCutoverConsoleController::class, 'downloadCsv'])
            ->middleware('throttle:stock_cutover_report')
            ->name('report.csv');
    });

Route::prefix('/_ops/order-cutover/{token}')
    ->middleware(['stock-cutover-console'])
    ->where(['token' => '[A-Za-z0-9_-]{48,128}'])
    ->name('operations.order-cutover.')
    ->group(function (): void {
        Route::get('/', [OrderCutoverConsoleController::class, 'index'])
            ->middleware('throttle:order_cutover_page')
            ->name('index');
        Route::post('/preview', [OrderCutoverConsoleController::class, 'preview'])
            ->middleware('throttle:order_cutover_preview')
            ->name('preview');
        Route::post('/intake/preview', [OrderCutoverConsoleController::class, 'intakePreview'])
            ->middleware('throttle:order_cutover_preview')
            ->name('intake.preview');
        Route::get('/jobs/{job}', [OrderCutoverConsoleController::class, 'status'])
            ->middleware('throttle:order_cutover_status')
            ->name('status');
        Route::post('/lookup', [OrderCutoverConsoleController::class, 'lookupOrder'])
            ->middleware('throttle:order_cutover_status')
            ->name('lookup');
        Route::post('/lookup/include', [OrderCutoverConsoleController::class, 'includeOrder'])
            ->middleware('throttle:order_cutover_apply')
            ->name('lookup.include');
        Route::post('/lookup/delete', [OrderCutoverConsoleController::class, 'deleteOrder'])
            ->middleware('throttle:order_cutover_apply')
            ->name('lookup.delete');
        Route::post('/jobs/{job}/apply', [OrderCutoverConsoleController::class, 'apply'])
            ->middleware('throttle:order_cutover_apply')
            ->name('apply');
        Route::post('/jobs/{job}/intake-apply', [OrderCutoverConsoleController::class, 'intakeApply'])
            ->middleware('throttle:order_cutover_apply')
            ->name('intake.apply');
        Route::get('/jobs/{job}/report', [OrderCutoverConsoleController::class, 'download'])
            ->middleware('throttle:order_cutover_report')
            ->name('report');
    });

Route::prefix('dev/tracking')->middleware('dev.only')->name('dev.tracking.')->group(function () {
    Route::get('/', [TrackingController::class, 'index'])->name('index');
    Route::get('/data', [TrackingController::class, 'data'])->name('data');
    Route::get('/export', [TrackingController::class, 'export'])->name('export');
    Route::patch('/items/{item}', [TrackingController::class, 'update'])->name('update');
});
