<?php

use App\Http\Controllers\Api\Dashboard\DashboardPanelsController;
use Illuminate\Support\Facades\Route;

// Dashboard cards with their own endpoints (inside auth:sanctum group)
Route::get('dashboard/memorization', [DashboardPanelsController::class, 'memorization']);
Route::get('dashboard/fees', [DashboardPanelsController::class, 'fees']);
Route::post('dashboard/fees/remind/{student}', [DashboardPanelsController::class, 'remind'])->middleware('throttle:30,1');
