<?php

use Illuminate\Support\Facades\Route;
use Modules\Webhook\Http\Controllers\WebhookController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('webhooks', WebhookController::class)->names('webhook');
});
