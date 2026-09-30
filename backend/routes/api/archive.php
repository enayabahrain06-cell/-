<?php

use App\Http\Controllers\Api\Archive\ArchiveController;
use Illuminate\Support\Facades\Route;

// رفع الأرشيف (archive.manage) and عرض الأرشيف (archive.view), inside the auth:sanctum group.
Route::prefix('archive')->group(function () {
    Route::get('template', [ArchiveController::class, 'template']);
    Route::post('preview', [ArchiveController::class, 'preview']);
    Route::post('batches', [ArchiveController::class, 'commit']);
    Route::get('batches', [ArchiveController::class, 'batches']);
    Route::delete('batches/{batch}', [ArchiveController::class, 'destroyBatch']);
    Route::get('records', [ArchiveController::class, 'records']);
});
Route::get('students/{student}/archive', [ArchiveController::class, 'student']);
