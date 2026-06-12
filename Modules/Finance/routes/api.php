<?php

use Illuminate\Support\Facades\Route;
use Modules\Finance\Http\Controllers\FinanceController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('finances', FinanceController::class)->names('finance');

    // Cash & Bank (Jubelio): receives = uang masuk, payments = uang keluar.
    // {id} di-whereUuid → non-UUID = 404, bukan error cast uuid (500).
    Route::get('cashbank/payments', [\Modules\Finance\Http\Controllers\CashbankController::class, 'payments'])->name('cashbank.payments.index');
    Route::get('cashbank/payments/{id}', [\Modules\Finance\Http\Controllers\CashbankController::class, 'paymentShow'])->whereUuid('id')->name('cashbank.payments.show');
    Route::get('cashbank/receives', [\Modules\Finance\Http\Controllers\CashbankController::class, 'receives'])->name('cashbank.receives.index');
    Route::get('cashbank/receives/{id}', [\Modules\Finance\Http\Controllers\CashbankController::class, 'receiveShow'])->whereUuid('id')->name('cashbank.receives.show');

    // Journal & Chart of Accounts (Jubelio).
    Route::get('accounts/lookup/all', [\Modules\Finance\Http\Controllers\AccountLookupController::class, 'all'])->name('finance.accounts.lookup');
    Route::get('journal', [\Modules\Finance\Http\Controllers\JournalController::class, 'index'])->name('finance.journal.index');
    // manual-journal HARUS sebelum journal/{id} agar tidak tertangkap sebagai {id}.
    Route::get('journal/manual-journal', [\Modules\Finance\Http\Controllers\JournalController::class, 'manualIndex'])->name('finance.journal.manual.index');
    Route::post('journal/manual-journal', [\Modules\Finance\Http\Controllers\JournalController::class, 'saveManual'])->name('finance.journal.manual.save');
    Route::get('journal/{id}', [\Modules\Finance\Http\Controllers\JournalController::class, 'show'])->whereUuid('id')->name('finance.journal.show');
});
