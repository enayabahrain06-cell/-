<?php

use App\Http\Controllers\Api\Activities\ActivityAttendanceController;
use App\Http\Controllers\Api\Activities\ActivityBookController;
use App\Http\Controllers\Api\Activities\ActivityController;
use App\Http\Controllers\Api\Activities\ActivityEvaluationController;
use App\Http\Controllers\Api\Activities\ActivityRegistrationController;
use Illuminate\Support\Facades\Route;

// البرامج and الرحلات: one engine (activities?type=program|trip), inside the auth:sanctum group.
Route::get('activities/{activity}/registrations', [ActivityRegistrationController::class, 'index']);
Route::get('activities/{activity}/candidates', [ActivityRegistrationController::class, 'candidates']);
Route::post('activities/{activity}/registrations', [ActivityRegistrationController::class, 'store']);
Route::post('activities/{activity}/registrations/{registration}/confirm', [ActivityRegistrationController::class, 'confirm']);
Route::post('activities/{activity}/registrations/{registration}/cancel', [ActivityRegistrationController::class, 'cancel']);
Route::get('activities/{activity}/attendance', [ActivityAttendanceController::class, 'show']);
Route::put('activities/{activity}/attendance', [ActivityAttendanceController::class, 'update']);
Route::get('activities/{activity}/attendance/summary', [ActivityAttendanceController::class, 'summary']);
Route::post('activities/{activity}/book-deliveries', [ActivityBookController::class, 'deliver']);
Route::delete('activities/{activity}/book-deliveries/{delivery}', [ActivityBookController::class, 'undo']);
Route::put('activities/{activity}/evaluations', [ActivityEvaluationController::class, 'update']);
Route::apiResource('activities', ActivityController::class)->except('show');
