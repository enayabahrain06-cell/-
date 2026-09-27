<?php

use App\Http\Controllers\Api\Registration\PublicRegistrationController;
use Illuminate\Support\Facades\Route;

// Public (no login): registration page data, submission and tracking.
Route::prefix('public')->middleware('throttle:60,1')->group(function () {
    Route::get('settings', [PublicRegistrationController::class, 'settings']);
    Route::get('packages', [PublicRegistrationController::class, 'packages']);
    Route::post('registrations', [PublicRegistrationController::class, 'store'])->middleware('throttle:10,1');
    Route::get('registrations/{requestNo}', [PublicRegistrationController::class, 'track']);
});
