<?php

use App\Http\Controllers\Api\Media\MediaController;
use App\Http\Controllers\Api\Media\StudentPhotoController;
use Illuminate\Support\Facades\Route;

// Student photos (policy-checked). Bulk route is declared before the {student} routes.
Route::post('students/photos/bulk', [StudentPhotoController::class, 'bulk']);
Route::post('students/{student}/photo', [StudentPhotoController::class, 'store']);
Route::delete('students/{student}/photo', [StudentPhotoController::class, 'destroy']);
Route::get('students/{student}/photo-url', [StudentPhotoController::class, 'url']);

// Any media file, streamed after the collection-based gate.
Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
