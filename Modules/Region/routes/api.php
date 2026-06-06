<?php

use Illuminate\Support\Facades\Route;
use Modules\Region\Http\Controllers\RegionController;

/*
 *--------------------------------------------------------------------------
 * API Routes
 *--------------------------------------------------------------------------
 *
 * Here is where you can register API routes for your application. These
 * routes are loaded by the RouteServiceProvider within a group which
 * is assigned the "api" middleware group. Enjoy building your API!
 *
*/

Route::prefix('v1/regions')->name('api.v1.regions.')->group(function () {
    Route::get('/provinces', [RegionController::class, 'provinces'])->name('provinces');
    Route::get('/cities/{province_id}', [RegionController::class, 'cities'])->name('cities');
    Route::get('/districts/{city_id}', [RegionController::class, 'districts'])->name('districts');
    Route::get('/villages/{district_id}', [RegionController::class, 'villages'])->name('villages');
});
