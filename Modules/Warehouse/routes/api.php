<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\LocationController;
use Modules\Warehouse\Http\Controllers\LocationZoneController;
use Modules\Warehouse\Http\Controllers\LocationBinController;
use Modules\Warehouse\Http\Controllers\ChannelWarehouseController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Jubelio: pemetaan lokasi ke toko (alias dari channel-warehouses).
    // Harus didefinisikan sebelum apiResource agar tidak tertangkap locations/{location}.
    Route::get('locations/store', [ChannelWarehouseController::class, 'index'])->name('warehouse.location.store');

    Route::apiResource('locations', LocationController::class)->names('warehouse.location');

    Route::get('locations/{locationId}/zones', [LocationZoneController::class, 'index'])->name('warehouse.zones.index');

    Route::get('locations/{locationId}/bins', [LocationBinController::class, 'index'])->name('warehouse.bins.index');
    Route::post('locations/{locationId}/bins/preview', [LocationBinController::class, 'preview'])->name('warehouse.bins.preview');
    Route::get('locations/{locationId}/default-bin', [LocationBinController::class, 'defaultBin'])->name('warehouse.bins.default');

    Route::get('channel-warehouses', [ChannelWarehouseController::class, 'index'])->name('warehouse.channel.index');
    Route::post('channel-warehouses', [ChannelWarehouseController::class, 'store'])->name('warehouse.channel.store');
    Route::delete('channel-warehouses/{id}', [ChannelWarehouseController::class, 'destroy'])->name('warehouse.channel.destroy');
});
