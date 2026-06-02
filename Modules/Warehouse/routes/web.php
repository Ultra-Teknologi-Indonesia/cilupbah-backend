<?php

use Illuminate\Support\Facades\Route;
use Modules\Warehouse\Http\Controllers\WarehouseController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('warehouses', WarehouseController::class)->names('warehouse');
});
