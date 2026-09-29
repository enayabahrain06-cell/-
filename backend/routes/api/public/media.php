<?php

use App\Http\Controllers\Api\Media\MediaController;
use Illuminate\Support\Facades\Route;

// Temporary signed URL (10 minutes). No session/token needed: the signature is the credential.
Route::get('media/students/{student}/photo/{size}', [MediaController::class, 'studentPhoto'])
    ->middleware('signed')
    ->name('media.student-photo');
Route::get('media/teachers/{teacher}/photo/{size}', [MediaController::class, 'teacherPhoto'])
    ->whereNumber('teacher')
    ->middleware('signed')
    ->name('media.teacher-photo');
