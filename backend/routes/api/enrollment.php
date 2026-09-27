<?php

use App\Http\Controllers\Api\Enrollment\QuickEnrollmentController;
use Illuminate\Support\Facades\Route;

// Quick enrollment by staff (spec section 15). Loaded inside the auth:sanctum group.
Route::prefix('enrollment')->group(function () {
    Route::get('options', [QuickEnrollmentController::class, 'options']);
    Route::get('lookup', [QuickEnrollmentController::class, 'lookup']);
    Route::post('/', [QuickEnrollmentController::class, 'store'])->middleware('throttle:60,1');
    Route::get('import/template', [QuickEnrollmentController::class, 'template']);
    Route::post('import/preview', [QuickEnrollmentController::class, 'preview']);
    Route::post('import', [QuickEnrollmentController::class, 'commit']);
});
