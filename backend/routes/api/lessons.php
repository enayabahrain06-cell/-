<?php

use App\Http\Controllers\Api\Lessons\LessonController;
use App\Http\Controllers\Api\Lessons\LessonSessionController;
use App\Http\Controllers\Api\Lessons\LocationBookingController;
use App\Http\Controllers\Api\Lessons\LocationController;
use Illuminate\Support\Facades\Route;

// Locations (halls)
Route::get('locations/free', [LocationController::class, 'free']);
Route::get('locations/{location}/calendar', [LocationController::class, 'calendar']);
Route::post('locations/{location}/toggle', [LocationController::class, 'toggle']);
Route::apiResource('locations', LocationController::class);
Route::apiResource('location-bookings', LocationBookingController::class)->parameters(['location-bookings' => 'booking'])->except(['show']);

// Lessons (circles)
Route::get('lessons/{lesson}/conflicts', [LessonController::class, 'conflicts']);
Route::get('lessons/{lesson}/sessions', [LessonController::class, 'sessions']);
Route::get('lessons/{lesson}/candidates', [LessonController::class, 'candidates']);
Route::post('lessons/{lesson}/students',[LessonController::class, 'enroll']);
Route::delete('lessons/{lesson}/students/{student}', [LessonController::class, 'unenroll']);
Route::post('lessons/{lesson}/change-location', [LessonController::class, 'changeLocation']);
Route::apiResource('lessons', LessonController::class);

// Sessions
Route::get('sessions/today', [LessonSessionController::class, 'today']);
Route::get('sessions/{session}', [LessonSessionController::class, 'show']);
Route::patch('sessions/{session}', [LessonSessionController::class, 'update']);
