<?php

use Illuminate\Support\Facades\Route;
use Modules\Auth\Http\Controllers\AuthController;

Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::put('/profile/avatar', [AuthController::class, 'updateAvatar']);
    Route::get('/roles', [\Modules\Auth\Http\Controllers\RoleController::class, 'index']);
    
    Route::middleware('role_or_permission:owner|create-role')->post('/roles', [\Modules\Auth\Http\Controllers\RoleController::class, 'store']);
    Route::middleware('role_or_permission:owner|edit-role')->put('/roles/{id}', [\Modules\Auth\Http\Controllers\RoleController::class, 'update']);
    Route::middleware('role_or_permission:owner|delete-role')->delete('/roles/{id}', [\Modules\Auth\Http\Controllers\RoleController::class, 'destroy']);
    
    Route::middleware('role_or_permission:owner|view-user')->group(function () {
        Route::get('/users', [\Modules\Auth\Http\Controllers\UserController::class, 'index']);
    });

    Route::middleware('role_or_permission:owner|export-user')->group(function () {
        Route::get('/users/export', [\Modules\Auth\Http\Controllers\UserController::class, 'export']);
    });

    Route::middleware('role_or_permission:owner|create-user')->group(function () {
        Route::post('/users', [\Modules\Auth\Http\Controllers\UserController::class, 'store']);
    });
    
    Route::middleware('role_or_permission:owner|edit-user')->group(function () {
        Route::put('/users/{id}', [\Modules\Auth\Http\Controllers\UserController::class, 'update'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|view-user-history')->group(function () {
        Route::get('/users/{id}/histories', [\Modules\Auth\Http\Controllers\UserController::class, 'histories'])->whereUuid('id');
    });

    Route::middleware('role_or_permission:owner|force-logout-user')->group(function () {
        Route::post('/users/{id}/force-logout', [\Modules\Auth\Http\Controllers\UserController::class, 'forceLogout'])->whereUuid('id');
        Route::post('/users/bulk-force-logout', [\Modules\Auth\Http\Controllers\UserController::class, 'bulkForceLogout']);
    });

    Route::middleware('role_or_permission:owner|view-permission')->group(function () {
        Route::get('/permissions', [\Modules\Auth\Http\Controllers\PermissionController::class, 'index']);
    });
});
