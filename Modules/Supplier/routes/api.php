<?php

use Illuminate\Support\Facades\Route;
use Modules\Supplier\Http\Controllers\SupplierController;
use Modules\Supplier\Http\Controllers\ContactController;
use Modules\Supplier\Http\Controllers\ContactImportController;
use Modules\Supplier\Http\Controllers\SalesmanController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {

    Route::get('salesmen/all', [SalesmanController::class, 'all'])->name('salesmen.all')->middleware('role_or_permission:owner|view-salesman');

    Route::middleware('role_or_permission:owner|view-salesman')->group(function () {
        Route::get('salesmen', [SalesmanController::class, 'index'])->name('salesmen.index');
    });
    Route::middleware('role_or_permission:owner|create-salesman')->group(function () {
        Route::post('salesmen', [SalesmanController::class, 'store'])->name('salesmen.store');
    });
    Route::middleware('role_or_permission:owner|view-salesman')->group(function () {
        Route::get('salesmen/{id}', [SalesmanController::class, 'show'])->name('salesmen.show');
    });
    Route::middleware('role_or_permission:owner|edit-salesman')->group(function () {
        Route::put('salesmen/{id}', [SalesmanController::class, 'update'])->name('salesmen.update');
    });
    Route::middleware('role_or_permission:owner|delete-salesman')->group(function () {
        Route::delete('salesmen', [SalesmanController::class, 'destroy'])->name('salesmen.destroy');
    });

    Route::middleware('role_or_permission:owner|import-kontak-pelanggan')->group(function () {
        Route::get('contacts/import/template', [ContactImportController::class, 'downloadTemplate'])->name('contacts.import.template');
        Route::post('contacts/import/validate', [ContactImportController::class, 'validate'])->name('contacts.import.validate');
        Route::post('contacts/import/save', [ContactImportController::class, 'save'])->name('contacts.import.save');
    });

    Route::get('contacts/customers', [ContactController::class, 'customers'])->name('contacts.customers')->middleware('role_or_permission:owner|view-kontak-pelanggan');
    Route::get('contacts/suppliers', [ContactController::class, 'suppliers'])->name('contacts.suppliers')->middleware('role_or_permission:owner|view-kontak-pemasok');
    Route::get('contacts/customers-suppliers', [ContactController::class, 'customersSuppliers'])->name('contacts.customers-suppliers')->middleware('role_or_permission:owner|view-kontak-pelanggan');

    Route::middleware('role_or_permission:owner|view-kontak-pelanggan')->group(function () {
        Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    });
    Route::middleware('role_or_permission:owner|create-kontak-pelanggan')->group(function () {
        Route::post('contacts', [ContactController::class, 'store'])->name('contacts.store');
    });
    Route::middleware('role_or_permission:owner|edit-kontak-pelanggan')->group(function () {
        Route::put('contacts/{id}', [ContactController::class, 'update'])->name('contacts.update');
    });
    Route::middleware('role_or_permission:owner|delete-kontak-pelanggan')->group(function () {
        Route::delete('contacts', [ContactController::class, 'destroy'])->name('contacts.destroy');
    });
    Route::middleware('role_or_permission:owner|view-kontak-pelanggan')->group(function () {
        Route::get('contacts/{id}', [ContactController::class, 'show'])->name('contacts.show');
    });

    Route::middleware('role_or_permission:owner|edit-kontak-pelanggan')->group(function () {
        Route::post('contact/category', [ContactController::class, 'storeCategory'])->name('contact.category.store');
        Route::put('contact/category/{id}', [ContactController::class, 'updateCategory'])->name('contact.category.update');
    });
    Route::middleware('role_or_permission:owner|delete-kontak-pelanggan')->group(function () {
        Route::delete('contact/category', [ContactController::class, 'destroyCategory'])->name('contact.category.destroy');
    });
    Route::middleware('role_or_permission:owner|view-kontak-pelanggan')->group(function () {
        Route::get('contact/category', [ContactController::class, 'categories'])->name('contact.category');
    });

    Route::get('contact/account-payable', [ContactController::class, 'accountPayableOptions'])->name('contact.account-payable')->middleware('role_or_permission:owner|view-kontak-pemasok');

    Route::middleware('role_or_permission:owner|view-kontak-pemasok')->group(function () {
        Route::apiResource('suppliers', SupplierController::class)->only(['index', 'show'])->names('supplier');
    });
    Route::middleware('role_or_permission:owner|create-kontak-pemasok')->group(function () {
        Route::apiResource('suppliers', SupplierController::class)->only(['store'])->names('supplier');
    });
    Route::middleware('role_or_permission:owner|edit-kontak-pemasok')->group(function () {
        Route::apiResource('suppliers', SupplierController::class)->only(['update'])->names('supplier');
    });
    Route::middleware('role_or_permission:owner|delete-kontak-pemasok')->group(function () {
        Route::apiResource('suppliers', SupplierController::class)->only(['destroy'])->names('supplier');
    });
});
