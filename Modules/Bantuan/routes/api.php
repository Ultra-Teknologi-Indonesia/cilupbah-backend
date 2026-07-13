<?php

use Illuminate\Support\Facades\Route;
use Modules\Bantuan\Http\Controllers\ApiDocsController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::get('bantuan/api-docs', [ApiDocsController::class, 'index'])
        ->name('bantuan.api-docs.index');
    Route::get('bantuan/api-docs/{slug}', [ApiDocsController::class, 'module'])
        ->where('slug', '[a-z0-9_-]+')
        ->name('bantuan.api-docs.module');
});
