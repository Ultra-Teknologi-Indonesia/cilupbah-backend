<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\LocationController;
use Modules\Warehouse\Http\Controllers\LocationZoneController;
use Modules\Warehouse\Http\Controllers\LocationBinController;
use Modules\Warehouse\Http\Controllers\ChannelWarehouseController;
use Modules\Warehouse\Http\Controllers\WarehouseSettingController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('systemsetting/warehouse-layout', [WarehouseSettingController::class, 'index'])->name('warehouse.setting.index');
    Route::post('systemsetting/warehouse-layout', [WarehouseSettingController::class, 'store'])->name('warehouse.setting.store');

    Route::get('locations/store', [ChannelWarehouseController::class, 'index'])->name('warehouse.location.store-mapping');

    Route::get('locations/pos', [LocationController::class, 'pos'])->name('warehouse.location.pos');

    Route::apiResource('locations', LocationController::class)->names('warehouse.location')->whereUuid('location');

    Route::get('locations/{locationId}/zones', [LocationZoneController::class, 'index'])->whereUuid('locationId')->name('warehouse.zones.index');

    Route::get('locations/{locationId}/bins', [LocationBinController::class, 'index'])->whereUuid('locationId')->name('warehouse.bins.index');
    Route::post('locations/{locationId}/bins/preview', [LocationBinController::class, 'preview'])->whereUuid('locationId')->name('warehouse.bins.preview');
    Route::post('locations/{locationId}/bins/generate', [LocationBinController::class, 'generate'])->whereUuid('locationId')->name('warehouse.bins.generate');
    Route::put('locations/{locationId}/bins/bulk', [LocationBinController::class, 'bulkUpdate'])->whereUuid('locationId')->name('warehouse.bins.bulk-update');
    Route::get('locations/{locationId}/default-bin', [LocationBinController::class, 'defaultBin'])->whereUuid('locationId')->name('warehouse.bins.default');

    Route::post('bins', [LocationBinController::class, 'store'])->name('warehouse.bins.store');
    Route::delete('bins/{id}', [LocationBinController::class, 'destroy'])->whereUuid('id')->name('warehouse.bins.destroy');

    Route::get('channel-warehouses', [ChannelWarehouseController::class, 'index'])->name('warehouse.channel.index');
    Route::post('channel-warehouses', [ChannelWarehouseController::class, 'store'])->name('warehouse.channel.store');

    Route::delete('channel-warehouses/{id}', [ChannelWarehouseController::class, 'destroy'])->whereNumber('id')->name('warehouse.channel.destroy');
});
