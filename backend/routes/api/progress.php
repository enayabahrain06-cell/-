<?php

use App\Http\Controllers\Api\Progress\CompletionCertificateController;
use App\Http\Controllers\Api\Progress\EvaluationController;
use App\Http\Controllers\Api\Progress\IssueController;
use App\Http\Controllers\Api\Progress\ProgressController;
use App\Http\Controllers\Api\Progress\ProgressReportController;
use App\Http\Controllers\Api\Progress\QuranController;
use App\Http\Controllers\Api\Progress\StudentProfileController;
use Illuminate\Support\Facades\Route;

// Memorization, evaluation and difficulties (inside auth:sanctum group)
Route::get('quran/surahs', [QuranController::class, 'surahs']);

// Student profile and memorization ledger
Route::get('students/{student}/profile', [StudentProfileController::class, 'show'])->whereNumber('student');
Route::get('students/{student}/progress', [ProgressController::class, 'index'])->whereNumber('student');
Route::post('students/{student}/progress', [ProgressController::class, 'store'])->whereNumber('student');
Route::delete('progress/{progressEntry}', [ProgressController::class, 'destroy']);
Route::post('students/{student}/certificates/completion', [CompletionCertificateController::class, 'store'])->whereNumber('student');

// Evaluations
Route::get('evaluations', [EvaluationController::class, 'index']);
Route::get('sessions/{session}/evaluations', [EvaluationController::class, 'sessionSheet']);
Route::post('sessions/{session}/evaluations', [EvaluationController::class, 'storeDaily']);
Route::post('lessons/{lesson}/evaluations/monthly', [EvaluationController::class, 'storeMonthly']);
Route::put('evaluations/{evaluation}', [EvaluationController::class, 'update']);
Route::delete('evaluations/{evaluation}', [EvaluationController::class, 'destroy']);
Route::post('evaluations/{evaluation}/send', [EvaluationController::class, 'send']);

// Difficulties (student issues)
Route::get('issues/options', [IssueController::class, 'options']);
Route::get('issues', [IssueController::class, 'index']);
Route::get('issues/{issue}', [IssueController::class, 'show']);
Route::put('issues/{issue}', [IssueController::class, 'update']);
Route::post('issues/{issue}/notes', [IssueController::class, 'storeNote']);
Route::get('students/{student}/issues', [IssueController::class, 'forStudent'])->whereNumber('student');
Route::post('students/{student}/issues', [IssueController::class, 'store'])->whereNumber('student');

// Reports
Route::prefix('reports')->group(function () {
    Route::get('progress/by-juz', [ProgressReportController::class, 'byJuz']);
    Route::get('issues/high-severity', [ProgressReportController::class, 'highSeverity']);
    Route::get('issues/categories', [ProgressReportController::class, 'categories']);
    Route::get('issues/resolved-monthly', [ProgressReportController::class, 'resolvedMonthly']);
});
