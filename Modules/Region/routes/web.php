<?php

use Illuminate\Support\Facades\Route;
use Modules\Region\Http\Controllers\RegionController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('regions', RegionController::class)->names('region');
});
