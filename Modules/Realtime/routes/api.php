<?php

use Illuminate\Support\Facades\Route;
use Modules\Realtime\Http\Controllers\EventStreamController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('realtime/stream', [EventStreamController::class, 'stream'])
        ->name('realtime.stream');
});
