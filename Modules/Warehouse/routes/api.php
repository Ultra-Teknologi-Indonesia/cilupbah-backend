<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\LocationController;
use Modules\Warehouse\Http\Controllers\LocationBinController;
use Modules\Warehouse\Http\Controllers\ChannelWarehouseController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('locations', LocationController::class)->names('warehouse.location');

    Route::get('locations/{locationId}/bins', [LocationBinController::class, 'index'])->name('warehouse.bins.index');
    Route::get('locations/{locationId}/default-bin', [LocationBinController::class, 'defaultBin'])->name('warehouse.bins.default');
    Route::post('bins', [LocationBinController::class, 'store'])->name('warehouse.bins.store');
    Route::delete('bins/{id}', [LocationBinController::class, 'destroy'])->name('warehouse.bins.destroy');

    Route::get('channel-warehouses', [ChannelWarehouseController::class, 'index'])->name('warehouse.channel.index');
    Route::post('channel-warehouses', [ChannelWarehouseController::class, 'store'])->name('warehouse.channel.store');
    Route::delete('channel-warehouses/{id}', [ChannelWarehouseController::class, 'destroy'])->name('warehouse.channel.destroy');
});
