<?php

use App\Http\Controllers\Api\Circles\AgeGroupController;
use App\Http\Controllers\Api\Circles\CircleController;
use Illuminate\Support\Facades\Route;

// Circles first, then enrollment into a circle (inside auth:sanctum group).
Route::apiResource('age-groups', AgeGroupController::class)->except('show');
Route::get('circles/match', [CircleController::class, 'match']);
Route::get('circles/aged-out', [CircleController::class, 'agedOut']);
Route::get('students/{student}/circles', [CircleController::class, 'history'])->whereNumber('student');
Route::get('students/{student}/move-options', [CircleController::class, 'moveOptions'])->whereNumber('student');
Route::post('students/{student}/move', [CircleController::class, 'move'])->whereNumber('student');
