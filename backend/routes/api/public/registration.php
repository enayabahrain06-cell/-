<?php

use App\Http\Controllers\Api\Registration\PublicPlacementController;
use App\Http\Controllers\Api\Registration\PublicRegistrationController;
use Illuminate\Support\Facades\Route;

// Public (no login): registration page data, submission and tracking.
Route::prefix('public')->middleware('throttle:public')->group(function () {
    Route::get('settings', [PublicRegistrationController::class, 'settings']);
    Route::get('packages', [PublicRegistrationController::class, 'packages']);
    Route::post('registrations', [PublicRegistrationController::class, 'store'])->middleware('throttle:registration-submit');
    Route::get('registrations/{requestNo}', [PublicRegistrationController::class, 'track']);
});

// Placement test inside the registration flow; the token is the only key to an attempt. Its own named
// limiters keep autosaving answers from counting against the page limit or the registration POST limit.
Route::prefix('public/placement')->group(function () {
    Route::post('/', [PublicPlacementController::class, 'start'])->middleware('throttle:placement');
    Route::get('{token}', [PublicPlacementController::class, 'show'])->middleware('throttle:placement-answers');
    Route::put('{token}/answers', [PublicPlacementController::class, 'saveAnswers'])->middleware('throttle:placement-answers');
    Route::post('{token}/submit', [PublicPlacementController::class, 'submit'])->middleware('throttle:placement');
});
