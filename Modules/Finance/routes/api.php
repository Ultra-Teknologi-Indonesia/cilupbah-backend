<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('accounts/lookup/all', [\Modules\Finance\Http\Controllers\AccountLookupController::class, 'all'])->name('finance.accounts.lookup')->middleware('role_or_permission:owner|view-akun');

});
