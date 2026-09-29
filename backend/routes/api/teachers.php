<?php

use App\Http\Controllers\Api\Teachers\TeacherController;
use Illuminate\Support\Facades\Route;

// Teachers (inside auth:sanctum group)
Route::get('teachers', [TeacherController::class, 'index']);
Route::get('teachers/{teacher}', [TeacherController::class, 'show'])->whereNumber('teacher');
Route::put('teachers/{teacher}', [TeacherController::class, 'update'])->whereNumber('teacher');
Route::post('teachers/{teacher}/photo', [TeacherController::class, 'storePhoto'])->whereNumber('teacher');
Route::delete('teachers/{teacher}/photo', [TeacherController::class, 'destroyPhoto'])->whereNumber('teacher');
