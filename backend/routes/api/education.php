<?php

use App\Http\Controllers\Api\Divisions\DivisionController;
use App\Http\Controllers\Api\Evaluation\CriteriaController;
use App\Http\Controllers\Api\Notes\NoteController;
use App\Http\Controllers\Api\Progress\EvaluationController;
use App\Http\Controllers\Api\Progress\QuranLessonsController;
use App\Http\Controllers\Api\Progress\SubjectProgressController;
use Illuminate\Support\Facades\Route;

// متابعة التعليم (Phase 4), inside the auth:sanctum group.

// U9 notes: students, general, levels, level subjects; the student's timeline on the profile.
Route::get('notes/options', [NoteController::class, 'options']);
Route::get('notes/students', [NoteController::class, 'students']);
Route::get('students/{student}/timeline', [NoteController::class, 'timeline'])->whereNumber('student');
Route::apiResource('notes', NoteController::class)->except('show');

// التقسيمات and عرض تقييم طلبة التقسيم
Route::put('divisions/{division}/students', [DivisionController::class, 'assign']);
Route::get('divisions/{division}/evaluations', [DivisionController::class, 'results']);
Route::apiResource('divisions', DivisionController::class)->except('show');

// U5 التقييمات (criteria per subject)
Route::post('evaluation-criteria/reorder', [CriteriaController::class, 'reorder']);
Route::apiResource('evaluation-criteria', CriteriaController::class)->parameters(['evaluation-criteria' => 'criterion'])->except('show');

// Subjects the user may evaluate in a session's class (the evaluation sheet's subject choice)
Route::get('sessions/{session}/evaluation-subjects', [EvaluationController::class, 'sessionSubjects']);

// دروس القرآن (read-only ledger list) and تحديث دروس المواد
Route::get('quran-lessons', [QuranLessonsController::class, 'index']);
Route::get('subject-progress', [SubjectProgressController::class, 'index']);
Route::post('subject-progress', [SubjectProgressController::class, 'store']);
Route::delete('subject-progress/{progress}', [SubjectProgressController::class, 'destroy']);
