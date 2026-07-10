<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\FinanceController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::middleware('role_or_permission:owner|view-akun')->group(function () {
        Route::apiResource('finances', FinanceController::class)->only(['index', 'show'])->names('finance');
    });
    Route::middleware('role_or_permission:owner|edit-akun')->group(function () {
        Route::apiResource('finances', FinanceController::class)->only(['store', 'update', 'destroy'])->names('finance');
    });

    Route::middleware('role_or_permission:owner|view-kas-bank')->group(function () {
        Route::get('cashbank/payments', [\Modules\Finance\Http\Controllers\CashbankController::class, 'payments'])->name('cashbank.payments.index');
        Route::get('cashbank/payments/{id}', [\Modules\Finance\Http\Controllers\CashbankController::class, 'paymentShow'])->whereUuid('id')->name('cashbank.payments.show');
        Route::get('cashbank/receives', [\Modules\Finance\Http\Controllers\CashbankController::class, 'receives'])->name('cashbank.receives.index');
        Route::get('cashbank/receives/{id}', [\Modules\Finance\Http\Controllers\CashbankController::class, 'receiveShow'])->whereUuid('id')->name('cashbank.receives.show');
    });

    Route::get('accounts/lookup/all', [\Modules\Finance\Http\Controllers\AccountLookupController::class, 'all'])->name('finance.accounts.lookup');

    Route::middleware('role_or_permission:owner|view-akun')->group(function () {
        Route::get('systemsetting/account-mapping', [\Modules\Finance\Http\Controllers\AccountMappingController::class, 'index'])->name('finance.account-mapping.index');
    });
    Route::middleware('role_or_permission:owner|edit-akun')->group(function () {
        Route::post('systemsetting/account-mapping', [\Modules\Finance\Http\Controllers\AccountMappingController::class, 'store'])->name('finance.account-mapping.store');
    });

    Route::middleware('role_or_permission:owner|view-jurnal')->group(function () {
        Route::get('journal', [\Modules\Finance\Http\Controllers\JournalController::class, 'index'])->name('finance.journal.index');
        Route::get('journal/manual-journal', [\Modules\Finance\Http\Controllers\JournalController::class, 'manualIndex'])->name('finance.journal.manual.index');
    });
    Route::middleware('role_or_permission:owner|create-jurnal')->group(function () {
        Route::post('journal/manual-journal', [\Modules\Finance\Http\Controllers\JournalController::class, 'saveManual'])->name('finance.journal.manual.save');
    });
    Route::middleware('role_or_permission:owner|view-jurnal')->group(function () {
        Route::get('journal/{id}', [\Modules\Finance\Http\Controllers\JournalController::class, 'show'])->whereUuid('id')->name('finance.journal.show');
    });
});
