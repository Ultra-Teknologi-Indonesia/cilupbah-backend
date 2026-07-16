<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\InboundController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role_or_permission:owner|view-barang-masuk')->group(function () {
        Route::get('inbounds/received-items', [InboundController::class, 'receivedItems'])->name('inbounds.receivedItems');
        Route::get('inbounds/my-assignments', [InboundController::class, 'myAssignments'])->name('inbounds.myAssignments');
    });

    Route::get('inbounds/scan/{qrCode}', [InboundController::class, 'scanQr'])->name('inbounds.scanQr');
    Route::post('inbounds/scan-putaway', [InboundController::class, 'scanPutaway'])->name('inbounds.scanPutaway');

    Route::middleware('role_or_permission:owner|delete-barang-masuk')->group(function () {
        Route::post('inbounds/bulk-cancel', [InboundController::class, 'bulkCancel'])->name('inbounds.bulkCancel');
    });

    Route::middleware('role_or_permission:owner|view-barang-masuk')->group(function () {
        Route::get('inbounds/counts', [InboundController::class, 'counts'])->name('inbounds.counts');
        Route::get('inbounds', [InboundController::class, 'index'])->name('inbounds.index');
    });
    Route::middleware('role_or_permission:owner|create-barang-masuk')->group(function () {
        Route::post('inbounds', [InboundController::class, 'store'])->name('inbounds.store');
    });
    Route::middleware('role_or_permission:owner|view-barang-masuk')->group(function () {
        Route::get('inbounds/{id}', [InboundController::class, 'show'])->name('inbounds.show');
        Route::get('inbounds/{id}/items', [InboundController::class, 'items'])->name('inbounds.items');
        Route::get('inbounds/{id}/receipts', [InboundController::class, 'receipts'])->name('inbounds.receipts');
    });
    Route::middleware('role_or_permission:owner|export-barang-masuk')->group(function () {
        Route::get('inbounds/{id}/barcodes', [InboundController::class, 'downloadBarcodes'])->name('inbounds.downloadBarcodes');
        Route::get('inbounds/{id}/pdf', [InboundController::class, 'pdf'])->name('inbounds.pdf');
    });

    Route::middleware('role_or_permission:owner|edit-barang-masuk')->group(function () {
        Route::post('inbounds/{id}/assign', [InboundController::class, 'assign'])->name('inbounds.assign');
        // Channel lock: unassign (tombol A, TAHAN) + reset (tombol B, destruktif)
        Route::delete('inbounds/{id}/assignment', [InboundController::class, 'unassign'])->name('inbounds.unassign');
        Route::post('inbounds/{id}/assignment/reset', [InboundController::class, 'resetAssignment'])->name('inbounds.resetAssignment');
    });
    Route::middleware('role_or_permission:owner|view-barang-masuk')->group(function () {
        Route::get('inbounds/{id}/assignments', [InboundController::class, 'assignments'])->name('inbounds.assignments');
    });

    Route::post('inbounds/assignments/{assignmentId}/start', [InboundController::class, 'startAssignment'])->name('inbounds.startAssignment');

    Route::middleware('role_or_permission:owner|edit-barang-masuk')->group(function () {
        Route::post('inbounds/{id}/receive', [InboundController::class, 'receive'])->name('inbounds.receive');
        // Fase 2 multi-staff: mobile hanya bisa join + scan; finalize hanya admin via closeReceiving.
        Route::post('inbounds/{id}/join', [InboundController::class, 'joinSession'])->name('inbounds.join');
        Route::patch('inbounds/{id}/items/{itemId}/received-qty', [InboundController::class, 'setReceivedQty'])->name('inbounds.setReceivedQty');
    });

    // Admin withdraw participant (fase 2)
    Route::middleware('role_or_permission:owner|edit-barang-masuk')->group(function () {
        Route::post('inbounds/{id}/participants/{userId}/withdraw', [InboundController::class, 'withdrawParticipant'])->name('inbounds.participants.withdraw');
    });

    // F5: Terima Susulan dari PO
    Route::middleware('role_or_permission:owner|create-barang-masuk')->group(function () {
        Route::post('purchase-orders/{poId}/receive-additional', [InboundController::class, 'receiveAdditional'])->name('inbounds.receiveAdditional');
    });
    Route::middleware('role_or_permission:owner|delete-barang-masuk')->group(function () {
        Route::delete('inbounds/{id}/received', [InboundController::class, 'correctReceivedLines'])->name('inbounds.correctReceivedBulk');
        Route::delete('inbounds/{id}/items/{itemId}/received', [InboundController::class, 'correctReceivedLine'])->name('inbounds.correctReceived');
    });
    Route::middleware('role_or_permission:owner|edit-barang-masuk')->group(function () {
        Route::post('inbounds/{id}/close-receiving', [InboundController::class, 'closeReceiving'])->name('inbounds.closeReceiving');
        Route::post('inbounds/{id}/putaway', [InboundController::class, 'putaway'])->name('inbounds.putaway');
        Route::post('inbounds/{id}/auto-putaway', [InboundController::class, 'autoPutaway'])->name('inbounds.autoPutaway');
    });
    Route::middleware('role_or_permission:owner|view-barang-masuk')->group(function () {
        Route::get('inbounds/{id}/pending-putaway', [InboundController::class, 'pendingPutaway'])->name('inbounds.pendingPutaway');
    });
    Route::middleware('role_or_permission:owner|delete-barang-masuk')->group(function () {
        Route::post('inbounds/{id}/cancel', [InboundController::class, 'cancel'])->name('inbounds.cancel');
    });
});
