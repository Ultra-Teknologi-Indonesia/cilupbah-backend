<?php

use Illuminate\Support\Facades\Route;
use Modules\Warranty\Http\Controllers\WarrantyController;

Route::prefix('v1')->group(function () {
    Route::apiResource('warranties', WarrantyController::class);
});
