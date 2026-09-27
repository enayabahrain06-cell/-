<?php

use App\Http\Controllers\Api\Exams\ExamAttemptController;
use App\Http\Controllers\Api\Exams\ExamController;
use App\Http\Controllers\Api\Exams\ExamQuestionController;
use App\Http\Controllers\Api\Exams\ExamResultController;
use App\Http\Controllers\Api\Exams\StudentExamController;
use Illuminate\Support\Facades\Route;

// Staff: exams, question bank, grading, results
Route::get('exams', [ExamController::class, 'index']);
Route::post('exams', [ExamController::class, 'store']);
Route::get('exams/{exam}', [ExamController::class, 'show']);
Route::put('exams/{exam}', [ExamController::class, 'update']);
Route::delete('exams/{exam}', [ExamController::class, 'destroy']);
Route::post('exams/{exam}/publish', [ExamController::class, 'publish']);
Route::post('exams/{exam}/close', [ExamController::class, 'close']);
Route::get('exams/{exam}/roster.pdf', [ExamController::class, 'rosterPdf'])->name('exams.roster');
Route::put('exams/{exam}/scores', [ExamController::class, 'scores']);

Route::get('exams/{exam}/questions', [ExamQuestionController::class, 'index']);
Route::post('exams/{exam}/questions', [ExamQuestionController::class, 'store']);
Route::post('exams/{exam}/questions/reorder', [ExamQuestionController::class, 'reorder']);
Route::put('exams/{exam}/questions/{question}', [ExamQuestionController::class, 'update']);
Route::delete('exams/{exam}/questions/{question}', [ExamQuestionController::class, 'destroy']);

Route::get('exams/{exam}/attempts', [ExamAttemptController::class, 'index']);
Route::get('exams/{exam}/attempts/{attempt}', [ExamAttemptController::class, 'show']);
Route::put('exams/{exam}/attempts/{attempt}/grade', [ExamAttemptController::class, 'grade']);
Route::post('exams/{exam}/attempts/{attempt}/sheet', [ExamController::class, 'uploadSheet']);

Route::get('exams/{exam}/results', [ExamResultController::class, 'show']);
Route::get('exams/{exam}/results.xlsx', [ExamResultController::class, 'excel'])->name('exams.results.xlsx');
Route::post('exams/{exam}/results/send', [ExamResultController::class, 'send']);
Route::post('exams/{exam}/certificates', [ExamResultController::class, 'certificates']);

// Student / guardian portal
Route::prefix('me/exams')->group(function () {
    Route::get('/', [StudentExamController::class, 'index']);
    Route::post('{exam}/start', [StudentExamController::class, 'start']);
    Route::get('{exam}/attempt', [StudentExamController::class, 'attempt']);
    Route::put('{exam}/attempt/answers', [StudentExamController::class, 'saveAnswers']);
    Route::post('{exam}/attempt/answers/{question}/audio', [StudentExamController::class, 'uploadAudio']);
    Route::post('{exam}/attempt/submit', [StudentExamController::class, 'submit']);
});
