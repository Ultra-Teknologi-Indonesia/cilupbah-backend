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
});
