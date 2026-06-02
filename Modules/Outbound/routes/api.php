<?php

use Illuminate\Support\Facades\Route;
use Modules\Outbound\Http\Controllers\OutboundController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('outbounds', OutboundController::class)->names('outbound');
});
