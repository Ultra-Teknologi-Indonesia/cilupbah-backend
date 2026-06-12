<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\PermissionController;
use Modules\Auth\Http\Controllers\RoleController;
use Modules\Auth\Http\Controllers\UserController;

Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile'])->name('auth.profile');
    Route::put('/profile/avatar', [AuthController::class, 'updateAvatar'])->name('auth.profile.avatar');

    Route::get('/roles', [RoleController::class, 'index'])->name('auth.roles.index');
    Route::get('/roles/{id}', [RoleController::class, 'show'])->whereUuid('id')->name('auth.roles.show');

    Route::middleware('role_or_permission:owner|create-role')->post('/roles', [RoleController::class, 'store'])->name('auth.roles.store');
    Route::middleware('role_or_permission:owner|edit-role')->group(function () {
        Route::put('/roles/{id}', [RoleController::class, 'update'])->whereUuid('id')->name('auth.roles.update');
        Route::put('/roles/{id}/permissions', [RoleController::class, 'syncPermissions'])->whereUuid('id')->name('auth.roles.permissions');
    });
    Route::middleware('role_or_permission:owner|delete-role')->delete('/roles/{id}', [RoleController::class, 'destroy'])->whereUuid('id')->name('auth.roles.destroy');

    Route::middleware('role_or_permission:owner|view-user')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('auth.users.index');
    });

    Route::middleware('role_or_permission:owner|export-user')->group(function () {
        Route::get('/users/export', [UserController::class, 'export'])->name('auth.users.export');
    });

    Route::middleware('role_or_permission:owner|view-user')->group(function () {
        Route::get('/users/{id}', [UserController::class, 'show'])->whereUuid('id')->name('auth.users.show');
    });

    Route::middleware('role_or_permission:owner|create-user')->group(function () {
        Route::post('/users', [UserController::class, 'store'])->name('auth.users.store');
    });

    Route::middleware('role_or_permission:owner|edit-user')->group(function () {
        Route::put('/users/{id}', [UserController::class, 'update'])->whereUuid('id')->name('auth.users.update');
    });

    Route::middleware('role_or_permission:owner|delete-user')->group(function () {
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereUuid('id')->name('auth.users.destroy');
    });

    Route::middleware('role_or_permission:owner|view-user-history')->group(function () {
        Route::get('/users/{id}/histories', [UserController::class, 'histories'])->whereUuid('id')->name('auth.users.histories');
    });

    Route::middleware('role_or_permission:owner|force-logout-user')->group(function () {
        Route::post('/users/{id}/force-logout', [UserController::class, 'forceLogout'])->whereUuid('id')->name('auth.users.force-logout');
        Route::post('/users/bulk-force-logout', [UserController::class, 'bulkForceLogout'])->name('auth.users.bulk-force-logout');
    });

    Route::middleware('role_or_permission:owner|view-permission')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->name('auth.permissions.index');
    });
});
