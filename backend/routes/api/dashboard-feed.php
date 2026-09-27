<?php

use App\Http\Controllers\Api\Dashboard\DashboardFeedController;
use Illuminate\Support\Facades\Route;

// Dashboard "coming up this week" and "recent activity" (inside auth:sanctum group)
Route::get('dashboard/upcoming', [DashboardFeedController::class, 'upcoming']);
Route::get('dashboard/activity', [DashboardFeedController::class, 'activity']);
Route::get('dashboard/activity/all', [DashboardFeedController::class, 'activityAll']);
