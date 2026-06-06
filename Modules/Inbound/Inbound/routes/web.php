<?php

use Illuminate\Support\Facades\Route;
use Modules\Inbound\Http\Controllers\InboundController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('inbounds', InboundController::class)->names('inbound');
});
