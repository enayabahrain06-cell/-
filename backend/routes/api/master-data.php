<?php

use App\Http\Controllers\Api\MasterData\AcademicTermController;
use App\Http\Controllers\Api\MasterData\LevelController;
use App\Http\Controllers\Api\MasterData\NightController;
use App\Http\Controllers\Api\MasterData\SubjectController;
use Illuminate\Support\Facades\Route;

// Master data: academic terms, levels, subjects (inside auth:sanctum group).
Route::post('academic-terms/{academicTerm}/current', [AcademicTermController::class, 'makeCurrent']);
Route::apiResource('academic-terms', AcademicTermController::class)->parameters(['academic-terms' => 'academicTerm'])->except('show');
Route::apiResource('levels', LevelController::class)->except('show');
Route::get('nights', [NightController::class, 'index']);
Route::put('nights/{night}', [NightController::class, 'update']);
Route::get('master-data/supervisors', [NightController::class, 'supervisors']);
Route::apiResource('subjects', SubjectController::class)->except('show');
