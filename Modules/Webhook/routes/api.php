<?php

use Illuminate\Support\Facades\Route;
use Modules\Webhook\Http\Controllers\WebhookController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // CRUD webhook subscription
    Route::apiResource('webhooks', WebhookController::class)
        ->whereUuid('webhook')
        ->names('webhook');

    // Jubelio: registrasi webhook lewat System Setting (alias ke store/index)
    Route::post('systemsetting/webhook', [WebhookController::class, 'store'])->name('webhook.systemsetting.store');
    Route::get('systemsetting/webhook', [WebhookController::class, 'index'])->name('webhook.systemsetting.index');
});
