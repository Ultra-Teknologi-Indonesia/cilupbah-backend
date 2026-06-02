<?php

use Illuminate\Support\Facades\Route;
use Modules\Webhook\Http\Controllers\WebhookController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('webhooks', WebhookController::class)->names('webhook');
});
