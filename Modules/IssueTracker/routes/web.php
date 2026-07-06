<?php

use Illuminate\Support\Facades\Route;
use Modules\IssueTracker\Http\Controllers\IssueController;

Route::prefix('issues')->name('issues.')->group(function () {
    Route::get('/', [IssueController::class, 'index'])->name('index');
    Route::get('/create', [IssueController::class, 'create'])->name('create');
    Route::post('/', [IssueController::class, 'store'])->middleware('throttle:5,1')->name('store');
    Route::get('/export', [IssueController::class, 'export'])->name('export');
    Route::get('/attachments/{media}', [IssueController::class, 'attachment'])->name('attachments.show');
    Route::get('/track/{token}', [IssueController::class, 'track'])->name('track');
    Route::get('/{issue}', [IssueController::class, 'show'])->name('show');
    Route::put('/{issue}/status', [IssueController::class, 'updateStatus'])->name('status');
    Route::put('/{issue}/assign', [IssueController::class, 'assign'])->name('assign');
    Route::post('/{issue}/comment', [IssueController::class, 'comment'])->middleware('throttle:10,1')->name('comment');
    Route::delete('/{issue}', [IssueController::class, 'destroy'])->name('destroy');
});
