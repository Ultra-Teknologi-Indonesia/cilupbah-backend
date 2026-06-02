<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\InboundController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('inbounds', InboundController::class)->names('inbound');
});
