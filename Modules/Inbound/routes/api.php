<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\InboundController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('inbounds/received-items', [InboundController::class, 'receivedItems'])->name('inbounds.receivedItems');
    Route::post('inbounds/auto-putaway', [InboundController::class, 'autoPutaway'])->name('inbounds.autoPutaway');
    
    Route::get('inbounds', [InboundController::class, 'index'])->name('inbounds.index');
    Route::post('inbounds', [InboundController::class, 'store'])->name('inbounds.store');
    Route::get('inbounds/{id}', [InboundController::class, 'show'])->name('inbounds.show');
    Route::post('inbounds/{id}/receive', [InboundController::class, 'receive'])->name('inbounds.receive');
});
