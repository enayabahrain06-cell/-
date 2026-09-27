<?php

use App\Http\Controllers\Api\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

// System settings (settings.manage) — inside the auth:sanctum group.
Route::prefix('admin/settings')->group(function () {
    Route::get('/', [SettingsController::class, 'index']);
    Route::put('/', [SettingsController::class, 'update']);
    Route::post('logo', [SettingsController::class, 'uploadLogo']);
    Route::delete('logo', [SettingsController::class, 'deleteLogo']);
});
