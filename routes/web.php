<?php

use App\Http\Controllers\Dev\TrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('dev/tracking')->middleware('dev.only')->name('dev.tracking.')->group(function () {
    Route::get('/', [TrackingController::class, 'index'])->name('index');
    Route::get('/data', [TrackingController::class, 'data'])->name('data');
    Route::get('/export', [TrackingController::class, 'export'])->name('export');
    Route::patch('/items/{item}', [TrackingController::class, 'update'])->name('update');
});
