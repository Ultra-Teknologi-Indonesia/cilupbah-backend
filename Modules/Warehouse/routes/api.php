<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\LocationController;
use Modules\Warehouse\Http\Controllers\LocationZoneController;
use Modules\Warehouse\Http\Controllers\LocationBinController;
use Modules\Warehouse\Http\Controllers\ChannelWarehouseController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Jubelio: pemetaan lokasi ke toko (alias dari channel-warehouses).
    // Harus didefinisikan sebelum apiResource agar tidak tertangkap locations/{location}.
    Route::get('locations/store', [ChannelWarehouseController::class, 'index'])->name('warehouse.location.store-mapping');

    // Jubelio getLocationsPos: lokasi yang punya outlet POS. Statik, harus sebelum apiResource.
    Route::get('locations/pos', [LocationController::class, 'pos'])->name('warehouse.location.pos');

    // locations.id bertipe UUID; batasi param agar id non-UUID -> 404 (bukan 500 cast Postgres).
    Route::apiResource('locations', LocationController::class)->names('warehouse.location')->whereUuid('location');

    Route::get('locations/{locationId}/zones', [LocationZoneController::class, 'index'])->whereUuid('locationId')->name('warehouse.zones.index');

    Route::get('locations/{locationId}/bins', [LocationBinController::class, 'index'])->whereUuid('locationId')->name('warehouse.bins.index');
    Route::post('locations/{locationId}/bins/preview', [LocationBinController::class, 'preview'])->whereUuid('locationId')->name('warehouse.bins.preview');
    Route::post('locations/{locationId}/bins/generate', [LocationBinController::class, 'generate'])->whereUuid('locationId')->name('warehouse.bins.generate');
    Route::get('locations/{locationId}/default-bin', [LocationBinController::class, 'defaultBin'])->whereUuid('locationId')->name('warehouse.bins.default');

    // Bin CRUD (path /bins)
    Route::post('bins', [LocationBinController::class, 'store'])->name('warehouse.bins.store');
    Route::delete('bins/{id}', [LocationBinController::class, 'destroy'])->whereUuid('id')->name('warehouse.bins.destroy');

    Route::get('channel-warehouses', [ChannelWarehouseController::class, 'index'])->name('warehouse.channel.index');
    Route::post('channel-warehouses', [ChannelWarehouseController::class, 'store'])->name('warehouse.channel.store');
    // channel_warehouses.id bertipe bigint; batasi numerik agar id non-numerik -> 404 (bukan 500 cast).
    Route::delete('channel-warehouses/{id}', [ChannelWarehouseController::class, 'destroy'])->whereNumber('id')->name('warehouse.channel.destroy');
});
