<?php

use App\Http\Controllers\Api\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

// Dashboard (inside auth:sanctum group)
Route::get('dashboard', [DashboardController::class, 'show']);
Route::get('alerts', [DashboardController::class, 'alerts']);
Route::post('alerts/{alert}/resolve', [DashboardController::class, 'resolveAlert']);
Route::post('alerts/{alert}/message-guardian', [DashboardController::class, 'messageGuardian']);
