<?php

use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\AuthController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Public
// ---------------------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('otp/request', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
});

// Module route files may register their own public routes (e.g. registration) outside this group.
foreach (glob(__DIR__.'/api/public/*.php') ?: [] as $file) {
    require $file;
}

// ---------------------------------------------------------------------------
// Authenticated
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::put('locale', [AuthController::class, 'updateLocale']);
        Route::post('logout', [AuthController::class, 'logout']);
    });

    // Users & permissions (Super Admin)
    Route::prefix('admin')->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions']);
        Route::apiResource('users', UserController::class);
    });

    // One file per module: routes/api/<module>.php (already inside the auth group).
    foreach (glob(__DIR__.'/api/*.php') ?: [] as $file) {
        require $file;
    }
});
