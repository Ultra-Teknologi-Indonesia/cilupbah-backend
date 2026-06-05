<?php

use Illuminate\Support\Facades\Route;
use Modules\Order\Http\Controllers\OrderController;

Route::prefix('v1')->group(function () {
    Route::apiResource('orders', OrderController::class)->names('order');
});
