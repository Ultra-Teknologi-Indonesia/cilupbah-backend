<?php

use Illuminate\Support\Facades\Route;
use Modules\Warranty\Http\Controllers\WarrantyController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('warranties', WarrantyController::class)->names('warranty');
});
