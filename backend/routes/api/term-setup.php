<?php

use App\Http\Controllers\Api\TermSetup\LevelRoomController;
use App\Http\Controllers\Api\TermSetup\LevelSubjectController;
use App\Http\Controllers\Api\TermSetup\NightSupervisorController;
use App\Http\Controllers\Api\TermSetup\PlanController;
use App\Http\Controllers\Api\TermSetup\SubjectLessonController;
use App\Http\Controllers\Api\TermSetup\TermSetupController;
use App\Http\Controllers\Api\TermSetup\TimetableController;
use Illuminate\Support\Facades\Route;

// Term setup (اعدادات الفصل), inside the auth:sanctum group. Reads take ?term_id= (default: the current term).
Route::prefix('term-setup')->group(function () {
    Route::get('options', [TermSetupController::class, 'options']);
    Route::post('copy', [TermSetupController::class, 'copy']);
    Route::get('plan/view', [PlanController::class, 'view']);
    Route::get('subject-lessons/import/template', [SubjectLessonController::class, 'importTemplate']);
    Route::post('subject-lessons/import/preview', [SubjectLessonController::class, 'importPreview']);
    Route::post('subject-lessons/import', [SubjectLessonController::class, 'importCommit']);
    Route::apiResource('level-rooms', LevelRoomController::class)->parameters(['level-rooms' => 'levelRoom'])->only(['index', 'store', 'destroy']);
    Route::apiResource('level-subjects', LevelSubjectController::class)->parameters(['level-subjects' => 'levelSubject'])->except('show');
    Route::apiResource('subject-lessons', SubjectLessonController::class)->parameters(['subject-lessons' => 'subjectLesson'])->except('show');
    Route::apiResource('plan', PlanController::class)->parameters(['plan' => 'planItem'])->except('show');
    Route::apiResource('night-supervisors', NightSupervisorController::class)->parameters(['night-supervisors' => 'nightSupervisor'])->only(['index', 'store', 'destroy']);
    Route::apiResource('timetable', TimetableController::class)->parameters(['timetable' => 'timetableSlot'])->except('show');
});
