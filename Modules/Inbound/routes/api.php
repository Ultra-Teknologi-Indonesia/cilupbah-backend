<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\InboundController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('inbounds/received-items', [InboundController::class, 'receivedItems'])->name('inbounds.receivedItems');
    Route::get('inbounds/my-assignments', [InboundController::class, 'myAssignments'])->name('inbounds.myAssignments');
    Route::get('inbounds/scan/{qrCode}', [InboundController::class, 'scanQr'])->name('inbounds.scanQr');
    Route::post('inbounds/scan-putaway', [InboundController::class, 'scanPutaway'])->name('inbounds.scanPutaway');

    Route::get('inbounds', [InboundController::class, 'index'])->name('inbounds.index');
    Route::post('inbounds', [InboundController::class, 'store'])->name('inbounds.store');
    Route::get('inbounds/{id}', [InboundController::class, 'show'])->name('inbounds.show');
    Route::get('inbounds/{id}/items', [InboundController::class, 'items'])->name('inbounds.items');
    Route::get('inbounds/{id}/barcodes', [InboundController::class, 'downloadBarcodes'])->name('inbounds.downloadBarcodes');

    Route::post('inbounds/{id}/assign', [InboundController::class, 'assign'])->name('inbounds.assign');
    Route::get('inbounds/{id}/assignments', [InboundController::class, 'assignments'])->name('inbounds.assignments');
    Route::post('inbounds/assignments/{assignmentId}/start', [InboundController::class, 'startAssignment'])->name('inbounds.startAssignment');

    Route::post('inbounds/{id}/receive', [InboundController::class, 'receive'])->name('inbounds.receive');
    Route::delete('inbounds/{id}/received', [InboundController::class, 'correctReceivedLines'])->name('inbounds.correctReceivedBulk');
    Route::delete('inbounds/{id}/items/{itemId}/received', [InboundController::class, 'correctReceivedLine'])->name('inbounds.correctReceived');
    Route::post('inbounds/{id}/close-receiving', [InboundController::class, 'closeReceiving'])->name('inbounds.closeReceiving');
    Route::post('inbounds/{id}/putaway', [InboundController::class, 'putaway'])->name('inbounds.putaway');
    Route::post('inbounds/{id}/auto-putaway', [InboundController::class, 'autoPutaway'])->name('inbounds.autoPutaway');
    Route::get('inbounds/{id}/pending-putaway', [InboundController::class, 'pendingPutaway'])->name('inbounds.pendingPutaway');
    Route::post('inbounds/{id}/cancel', [InboundController::class, 'cancel'])->name('inbounds.cancel');
});
