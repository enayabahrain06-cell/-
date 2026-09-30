<?php

use App\Http\Controllers\Api\Grades\ExamGradesController;
use App\Http\Controllers\Api\Grades\GradebookController;
use App\Http\Controllers\Api\Grades\GradeComponentController;
use App\Http\Controllers\Api\Grades\GradeMonitorController;
use Illuminate\Support\Facades\Route;

// الدرجات (Phase 6), inside the auth:sanctum group.

// توزيع الدرجات: components per level subject of the term
Route::post('grade-components/reorder', [GradeComponentController::class, 'reorder']);
Route::apiResource('grade-components', GradeComponentController::class)->parameters(['grade-components' => 'component'])->except('show');

// U10 / الدروس المطلوبة / رفع درجات الامتحان
Route::get('grades/exam-options', [ExamGradesController::class, 'options']);
Route::get('exams/{exam}/required-lessons', [ExamGradesController::class, 'requiredLessons'])->whereNumber('exam');
Route::put('exams/{exam}/required-lessons', [ExamGradesController::class, 'saveRequiredLessons'])->whereNumber('exam');
Route::get('exams/{exam}/scores-template.xlsx', [ExamGradesController::class, 'scoresTemplate'])->whereNumber('exam');
Route::post('exams/{exam}/scores-preview', [ExamGradesController::class, 'scoresPreview'])->whereNumber('exam');

// الدرجات / عرض الدرجات / تنزيل الدرجات
Route::get('grades/book', [GradebookController::class, 'index']);
Route::put('grades/book', [GradebookController::class, 'save']);
Route::get('grades/book.xlsx', [GradebookController::class, 'export']);
Route::get('grades/students/{student}', [GradebookController::class, 'student'])->whereNumber('student');

// مراقبة تسليم الدرجات
Route::get('grades/monitor', [GradeMonitorController::class, 'index']);
