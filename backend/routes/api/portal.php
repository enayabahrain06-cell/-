<?php

use App\Http\Controllers\Api\Portal\FamilyPortalController;
use Illuminate\Support\Facades\Route;

// Student / guardian portal (3.4): scoped to the signed-in user's own family in the controller.
Route::prefix('me')->group(function () {
    Route::get('overview', [FamilyPortalController::class, 'overview']);
    Route::get('schedule', [FamilyPortalController::class, 'schedule']);
    Route::get('messages', [FamilyPortalController::class, 'messages']);
    Route::get('badges', [FamilyPortalController::class, 'badges']);
});
