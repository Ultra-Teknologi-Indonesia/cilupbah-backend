<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\ChannelWarehouseController;
use Modules\Warehouse\Http\Controllers\CompanyProfileController;
use Modules\Warehouse\Http\Controllers\LocationBinController;
use Modules\Warehouse\Http\Controllers\LocationController;
use Modules\Warehouse\Http\Controllers\LocationZoneController;
use Modules\Warehouse\Http\Controllers\WarehouseSettingController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::middleware('role_or_permission:owner|view-pengaturan-sistem')->group(function () {
        Route::get('systemsetting/warehouse-layout', [WarehouseSettingController::class, 'index'])->name('warehouse.setting.index');
        Route::get('systemsetting/company', [CompanyProfileController::class, 'index'])->name('warehouse.company.index');
    });
    Route::middleware('role_or_permission:owner|edit-pengaturan-sistem')->group(function () {
        Route::post('systemsetting/warehouse-layout', [WarehouseSettingController::class, 'store'])->name('warehouse.setting.store');
        Route::post('systemsetting/company', [CompanyProfileController::class, 'store'])->name('warehouse.company.store');
    });

    Route::get('locations/store', [ChannelWarehouseController::class, 'index'])->name('warehouse.location.store-mapping')->middleware('role_or_permission:owner|view-manajemen-rak');

    Route::get('locations/pos', [LocationController::class, 'pos'])->name('warehouse.location.pos')->middleware('role_or_permission:owner|view-manajemen-rak');

    Route::middleware('role_or_permission:owner|view-manajemen-rak')->group(function () {
        Route::apiResource('locations', LocationController::class)->only(['index', 'show'])->names('warehouse.location')->whereUuid('location');
    });
    Route::middleware('role_or_permission:owner|create-manajemen-rak')->group(function () {
        Route::apiResource('locations', LocationController::class)->only(['store'])->names('warehouse.location')->whereUuid('location');
    });
    Route::middleware('role_or_permission:owner|edit-manajemen-rak')->group(function () {
        Route::apiResource('locations', LocationController::class)->only(['update'])->names('warehouse.location')->whereUuid('location');
    });
    Route::middleware('role_or_permission:owner|delete-manajemen-rak')->group(function () {
        Route::apiResource('locations', LocationController::class)->only(['destroy'])->names('warehouse.location')->whereUuid('location');
    });

    Route::middleware('role_or_permission:owner|view-manajemen-rak')->group(function () {
        Route::get('locations/{locationId}/zones', [LocationZoneController::class, 'index'])->whereUuid('locationId')->name('warehouse.zones.index');
    });
    Route::middleware('role_or_permission:owner|create-manajemen-rak')->group(function () {
        Route::post('locations/{locationId}/zones', [LocationZoneController::class, 'store'])->whereUuid('locationId')->name('warehouse.zones.store');
    });
    Route::middleware('role_or_permission:owner|edit-manajemen-rak')->group(function () {
        Route::put('locations/{locationId}/zones/{zoneId}', [LocationZoneController::class, 'update'])->whereUuid(['locationId', 'zoneId'])->name('warehouse.zones.update');
    });
    Route::middleware('role_or_permission:owner|delete-manajemen-rak')->group(function () {
        Route::delete('locations/{locationId}/zones/{zoneId}', [LocationZoneController::class, 'destroy'])->whereUuid(['locationId', 'zoneId'])->name('warehouse.zones.destroy');
    });

    Route::middleware('role_or_permission:owner|view-manajemen-rak')->group(function () {
        Route::get('locations/{locationId}/bins', [LocationBinController::class, 'index'])->whereUuid('locationId')->name('warehouse.bins.index');
        Route::get('locations/{locationId}/bins/print-qr', [LocationBinController::class, 'printQr'])->whereUuid('locationId')->name('warehouse.bins.print-qr');
    });
    Route::middleware('role_or_permission:owner|edit-manajemen-rak')->group(function () {
        Route::post('locations/{locationId}/bins/print-qr-job', [LocationBinController::class, 'printQrJob'])->whereUuid('locationId')->name('warehouse.bins.print-qr-job');
    });

    Route::middleware('role_or_permission:owner|view-manajemen-rak')->group(function () {
        Route::get('qr-jobs/{id}', [\Modules\Warehouse\Http\Controllers\QrPrintJobController::class, 'show'])->whereUuid('id')->name('warehouse.qr-jobs.show');
        Route::get('qr-jobs/{id}/download', [\Modules\Warehouse\Http\Controllers\QrPrintJobController::class, 'download'])->whereUuid('id')->name('warehouse.qr-jobs.download');
    });

    Route::middleware('role_or_permission:owner|edit-manajemen-rak')->group(function () {
        Route::post('locations/{locationId}/bins/preview', [LocationBinController::class, 'preview'])->whereUuid('locationId')->name('warehouse.bins.preview');
        Route::post('locations/{locationId}/bins/generate', [LocationBinController::class, 'generate'])->whereUuid('locationId')->name('warehouse.bins.generate');
        Route::put('locations/{locationId}/bins/bulk', [LocationBinController::class, 'bulkUpdate'])->whereUuid('locationId')->name('warehouse.bins.bulk-update');
        Route::post('locations/{locationId}/bins/uniform-apply', [LocationBinController::class, 'uniformApply'])->whereUuid('locationId')->name('warehouse.bins.uniform-apply');
    });

    Route::get('locations/{locationId}/default-bin', [LocationBinController::class, 'defaultBin'])->whereUuid('locationId')->name('warehouse.bins.default')->middleware('role_or_permission:owner|view-manajemen-rak');

    Route::middleware('role_or_permission:owner|create-manajemen-rak')->group(function () {
        Route::post('bins', [LocationBinController::class, 'store'])->name('warehouse.bins.store');
    });
    Route::middleware('role_or_permission:owner|delete-manajemen-rak')->group(function () {
        Route::delete('bins/{id}', [LocationBinController::class, 'destroy'])->whereUuid('id')->name('warehouse.bins.destroy');
    });

    Route::middleware('role_or_permission:owner|view-integrasi-channel')->group(function () {
        Route::get('channel-warehouses', [ChannelWarehouseController::class, 'index'])->name('warehouse.channel.index');
    });
    Route::middleware('role_or_permission:owner|edit-integrasi-channel')->group(function () {
        Route::post('channel-warehouses', [ChannelWarehouseController::class, 'store'])->name('warehouse.channel.store');
        Route::delete('channel-warehouses/{id}', [ChannelWarehouseController::class, 'destroy'])->whereNumber('id')->name('warehouse.channel.destroy');
    });
});
