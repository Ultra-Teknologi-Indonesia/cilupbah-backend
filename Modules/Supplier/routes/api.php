<?php

use Illuminate\Support\Facades\Route;
use Modules\Supplier\Http\Controllers\SupplierController;
use Modules\Supplier\Http\Controllers\ContactController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('contacts/customers', [ContactController::class, 'customers'])->name('contacts.customers');
    Route::get('contacts/suppliers', [ContactController::class, 'suppliers'])->name('contacts.suppliers');
    Route::get('contacts/customers-suppliers', [ContactController::class, 'customersSuppliers'])->name('contacts.customers-suppliers');
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::put('contacts/{id}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('contacts', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::get('contacts/{id}', [ContactController::class, 'show'])->name('contacts.show');

    Route::get('contact/category', [ContactController::class, 'categories'])->name('contact.category');
    Route::get('contact/account-payable', [ContactController::class, 'accountPayableOptions'])->name('contact.account-payable');

    Route::apiResource('suppliers', SupplierController::class)->names('supplier');
});
