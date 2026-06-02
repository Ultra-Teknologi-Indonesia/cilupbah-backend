<?php

use Illuminate\Support\Facades\Route;
use Modules\Outbound\Http\Controllers\OutboundController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('outbounds', OutboundController::class)->names('outbound');
});
