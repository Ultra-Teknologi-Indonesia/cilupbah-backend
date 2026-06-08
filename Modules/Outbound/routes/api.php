<?php

use Illuminate\Support\Facades\Route;
use Modules\Outbound\Http\Controllers\PicklistController;
use Modules\Outbound\Http\Controllers\PacklistController;
use Modules\Outbound\Http\Controllers\ShipmentController;
use Modules\Outbound\Http\Controllers\OutboundFulfillmentController;

Route::middleware(['auth:sanctum'])->prefix('v1/outbound')->group(function () {

    // Fulfillment Queue Views
    Route::get('orders/{stage}', [OutboundFulfillmentController::class, 'ordersByStage'])->name('outbound.orders.stage');

    // Picklists
    Route::get('picklists', [PicklistController::class, 'index'])->name('outbound.picklists.index');
    Route::post('picklists', [PicklistController::class, 'store'])->name('outbound.picklists.store');
    Route::get('picklists/{id}', [PicklistController::class, 'show'])->name('outbound.picklists.show');
    Route::get('picklists/{id}/items', [PicklistController::class, 'items'])->name('outbound.picklists.items');
    Route::post('picklists/{id}/assign-picker', [PicklistController::class, 'assignPicker'])->name('outbound.picklists.assign-picker');
    Route::post('picklists/{id}/start', [PicklistController::class, 'start'])->name('outbound.picklists.start');
    Route::post('picklists/{id}/items/{itemId}/pick', [PicklistController::class, 'pickItem'])->name('outbound.picklists.pick-item');
    Route::post('picklists/{id}/complete', [PicklistController::class, 'complete'])->name('outbound.picklists.complete');
    Route::post('picklists/{id}/cancel', [PicklistController::class, 'cancel'])->name('outbound.picklists.cancel');
    Route::delete('picklists/{id}', [PicklistController::class, 'destroy'])->name('outbound.picklists.destroy');

    // Packlists
    Route::get('packlists', [PacklistController::class, 'index'])->name('outbound.packlists.index');
    Route::post('packlists', [PacklistController::class, 'store'])->name('outbound.packlists.store');
    Route::get('packlists/{id}', [PacklistController::class, 'show'])->name('outbound.packlists.show');
    Route::get('packlists/{id}/items', [PacklistController::class, 'items'])->name('outbound.packlists.items');
    Route::post('packlists/{id}/assign-packer', [PacklistController::class, 'assignPacker'])->name('outbound.packlists.assign-packer');
    Route::post('packlists/{id}/start', [PacklistController::class, 'start'])->name('outbound.packlists.start');
    Route::post('packlists/{id}/items/{itemId}/pack', [PacklistController::class, 'packItem'])->name('outbound.packlists.pack-item');
    Route::post('packlists/{id}/verify-barcode', [PacklistController::class, 'verifyBarcode'])->name('outbound.packlists.verify-barcode');
    Route::post('packlists/{id}/complete', [PacklistController::class, 'complete'])->name('outbound.packlists.complete');
    Route::post('packlists/{id}/cancel', [PacklistController::class, 'cancel'])->name('outbound.packlists.cancel');
    Route::delete('packlists/{id}', [PacklistController::class, 'destroy'])->name('outbound.packlists.destroy');

    // Shipments
    Route::get('shipments', [ShipmentController::class, 'index'])->name('outbound.shipments.index');
    Route::post('shipments', [ShipmentController::class, 'store'])->name('outbound.shipments.store');
    Route::get('shipments/{id}', [ShipmentController::class, 'show'])->name('outbound.shipments.show');
    Route::post('shipments/{id}/add-orders', [ShipmentController::class, 'addOrders'])->name('outbound.shipments.add-orders');
    Route::post('shipments/{id}/remove-orders', [ShipmentController::class, 'removeOrders'])->name('outbound.shipments.remove-orders');
    Route::post('shipments/{id}/hand-over', [ShipmentController::class, 'handOver'])->name('outbound.shipments.hand-over');
    Route::post('shipments/{id}/cancel', [ShipmentController::class, 'cancel'])->name('outbound.shipments.cancel');
    Route::delete('shipments/{id}', [ShipmentController::class, 'destroy'])->name('outbound.shipments.destroy');
});
