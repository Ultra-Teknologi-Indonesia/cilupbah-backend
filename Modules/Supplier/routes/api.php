<?php

use Illuminate\Support\Facades\Route;
use Modules\Supplier\Http\Controllers\SupplierController;
use Modules\Supplier\Http\Controllers\ContactController;
use Modules\Supplier\Http\Controllers\SalesmanController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('salesmen/all', [SalesmanController::class, 'all'])->name('salesmen.all');
    Route::get('salesmen', [SalesmanController::class, 'index'])->name('salesmen.index');
    Route::post('salesmen', [SalesmanController::class, 'store'])->name('salesmen.store');
    Route::get('salesmen/{id}', [SalesmanController::class, 'show'])->name('salesmen.show');
    Route::put('salesmen/{id}', [SalesmanController::class, 'update'])->name('salesmen.update');
    Route::delete('salesmen', [SalesmanController::class, 'destroy'])->name('salesmen.destroy');

    Route::get('contacts/customers', [ContactController::class, 'customers'])->name('contacts.customers');
    Route::get('contacts/suppliers', [ContactController::class, 'suppliers'])->name('contacts.suppliers');
    Route::get('contacts/customers-suppliers', [ContactController::class, 'customersSuppliers'])->name('contacts.customers-suppliers');
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::put('contacts/{id}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('contacts', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::get('contacts/{id}', [ContactController::class, 'show'])->name('contacts.show');

    Route::post('contact/category', [ContactController::class, 'storeCategory'])->name('contact.category.store');
    Route::put('contact/category/{id}', [ContactController::class, 'updateCategory'])->name('contact.category.update');
    Route::delete('contact/category', [ContactController::class, 'destroyCategory'])->name('contact.category.destroy');
    Route::get('contact/category', [ContactController::class, 'categories'])->name('contact.category');
    Route::get('contact/account-payable', [ContactController::class, 'accountPayableOptions'])->name('contact.account-payable');

    Route::apiResource('suppliers', SupplierController::class)->names('supplier');
});
