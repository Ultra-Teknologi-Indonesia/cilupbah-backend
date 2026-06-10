<?php

use Illuminate\Support\Facades\Route;
use Modules\Supplier\Http\Controllers\SupplierController;
use Modules\Supplier\Http\Controllers\ContactController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    // ─── Contacts (§11 — literal routes FIRST) ───
    Route::get('contacts/customers', [ContactController::class, 'customers'])->name('contacts.customers');
    Route::get('contacts/suppliers', [ContactController::class, 'suppliers'])->name('contacts.suppliers');
    Route::get('contacts/customers-suppliers', [ContactController::class, 'customersSuppliers'])->name('contacts.customers-suppliers');
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::delete('contacts', [ContactController::class, 'destroy'])->name('contacts.destroy');
    Route::get('contacts/{id}', [ContactController::class, 'show'])->name('contacts.show');

    // ─── Contact Category ───
    Route::get('contact/category', [ContactController::class, 'categories'])->name('contact.category');

    // ─── Suppliers (existing — backward compat) ───
    Route::apiResource('suppliers', SupplierController::class)->names('supplier');
});
