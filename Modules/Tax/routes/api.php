<?php

use Illuminate\Support\Facades\Route;
use Modules\Tax\Http\Controllers\TaxController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('taxes', TaxController::class)->names('tax');
});
