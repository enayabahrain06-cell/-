<?php

use App\Http\Controllers\Api\Students\StudentController;
use Illuminate\Support\Facades\Route;

// Students (inside auth:sanctum group)
Route::get('students', [StudentController::class, 'index']);
Route::get('students/{student}', [StudentController::class, 'show'])->whereNumber('student');
Route::put('students/{student}', [StudentController::class, 'update'])->whereNumber('student');
Route::get('me/students', [StudentController::class, 'me']);
