<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;
use Modules\Auth\Http\Controllers\ForgotPasswordController;
use Modules\Auth\Http\Controllers\PermissionController;
use Modules\Auth\Http\Controllers\RoleController;
use Modules\Auth\Http\Controllers\UserController;

Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');

    Route::prefix('forgot-password')->group(function () {
        Route::post('/', [ForgotPasswordController::class, 'send'])
            ->middleware('throttle:5,1')
            ->name('auth.forgot-password.send');
        Route::post('/verify-otp', [ForgotPasswordController::class, 'verify'])
            ->middleware('throttle:10,1')
            ->name('auth.forgot-password.verify');
        Route::post('/reset', [ForgotPasswordController::class, 'reset'])
            ->middleware('throttle:5,1')
            ->name('auth.forgot-password.reset');
    });

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');

        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware('throttle:20,1')
            ->name('auth.refresh');

        Route::post('/unlock', [AuthController::class, 'unlock'])
            ->middleware('throttle:10,1')
            ->name('auth.unlock');
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile'])->name('auth.profile');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('auth.profile.update');
    Route::put('/profile/avatar', [AuthController::class, 'updateAvatar'])->name('auth.profile.avatar');
    Route::put('/profile/password', [AuthController::class, 'changePassword'])->name('auth.profile.password');
    Route::get('/profile/histories', [AuthController::class, 'myHistories'])->name('auth.profile.histories');
    Route::get('/profile/login-histories', [AuthController::class, 'myLoginHistories'])->name('auth.profile.login-histories');
    Route::get('/profile/sessions', [AuthController::class, 'sessions'])->name('auth.profile.sessions');
    Route::post('/profile/sessions/{id}/revoke', [AuthController::class, 'revokeSession'])->name('auth.profile.sessions.revoke');
    Route::post('/profile/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions'])->name('auth.profile.sessions.revoke-others');

    Route::get('/systemsetting/users', [UserController::class, 'lookup'])->name('auth.users.lookup')->middleware('role_or_permission:owner|view-user');

    Route::get('/roles', [RoleController::class, 'index'])->name('auth.roles.index')->middleware('role_or_permission:owner|view-role');
    Route::get('/roles/{id}', [RoleController::class, 'show'])->whereUuid('id')->name('auth.roles.show')->middleware('role_or_permission:owner|view-role');

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
        Route::put('/users/{id}/permissions', [UserController::class, 'syncPermissions'])->whereUuid('id')->name('auth.users.permissions');
    });

    Route::middleware('role_or_permission:owner|delete-user')->group(function () {
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->whereUuid('id')->name('auth.users.destroy');
    });

    Route::middleware('role_or_permission:owner|view-user-history')->group(function () {
        Route::get('/users/{id}/login-history', [UserController::class, 'loginHistory'])->whereUuid('id')->name('auth.users.login-history');
        Route::get('/users/{id}/histories', [UserController::class, 'histories'])->whereUuid('id')->name('auth.users.histories');
    });

    Route::middleware('role_or_permission:owner|force-logout-user')->group(function () {
        Route::post('/users/{id}/force-logout', [UserController::class, 'forceLogout'])->whereUuid('id')->name('auth.users.force-logout');
        Route::post('/users/bulk-force-logout', [UserController::class, 'bulkForceLogout'])->name('auth.users.bulk-force-logout');
    });

    Route::middleware('role_or_permission:owner|view-permission')->group(function () {
        Route::get('/permissions', [PermissionController::class, 'index'])->name('auth.permissions.index');
        Route::get('/permissions/catalog', [PermissionController::class, 'catalog'])->name('auth.permissions.catalog');
    });
});
