<?php

use Illuminate\Support\Facades\Route;
use Modules\Region\Http\Controllers\RegionController;

Route::prefix('v1/regions')->name('api.v1.regions.')->group(function () {
    Route::get('/provinces', [RegionController::class, 'provinces'])->name('provinces');
    Route::get('/cities/{province_id}', [RegionController::class, 'cities'])->name('cities');
    Route::get('/districts/{city_id}', [RegionController::class, 'districts'])->name('districts');
    Route::get('/villages/{district_id}', [RegionController::class, 'villages'])->name('villages');
});
