<?php

use Illuminate\Support\Facades\Route;
use Modules\Webhook\Http\Controllers\WebhookController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::middleware('role_or_permission:owner|view-webhook')->group(function () {
        Route::get('webhooks', [WebhookController::class, 'index'])->name('webhook.index');
        Route::get('webhooks/{webhook}', [WebhookController::class, 'show'])->whereUuid('webhook')->name('webhook.show');
        Route::get('webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries'])
            ->whereUuid('webhook')->name('webhook.deliveries');
        Route::get('systemsetting/webhook', [WebhookController::class, 'index'])->name('webhook.systemsetting.index');
    });

    Route::middleware('role_or_permission:owner|edit-webhook')->group(function () {
        Route::post('webhooks', [WebhookController::class, 'store'])->name('webhook.store');
        Route::match(['put', 'patch'], 'webhooks/{webhook}', [WebhookController::class, 'update'])->whereUuid('webhook')->name('webhook.update');
        Route::delete('webhooks/{webhook}', [WebhookController::class, 'destroy'])->whereUuid('webhook')->name('webhook.destroy');
        Route::post('webhook-deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver'])
            ->whereUuid('delivery')->name('webhook.deliveries.redeliver');
        Route::post('systemsetting/webhook', [WebhookController::class, 'store'])->name('webhook.systemsetting.store');
    });
});
