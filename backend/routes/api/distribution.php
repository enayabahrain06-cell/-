<?php

use App\Http\Controllers\Api\Distribution\DistributionController;
use Illuminate\Support\Facades\Route;

// توزيع المستويات, ترفيع الطلبة, تحديث المستوى (inside the auth:sanctum group; distribution.manage).
Route::prefix('distribution')->group(function () {
    Route::get('options', [DistributionController::class, 'options']);
    Route::get('students', [DistributionController::class, 'students']);
    Route::post('place', [DistributionController::class, 'place']);
    Route::get('promotion', [DistributionController::class, 'promotion']);
    Route::post('promotion', [DistributionController::class, 'promote']);
    Route::get('level/{student}', [DistributionController::class, 'levelShow']);
    Route::post('level/{student}', [DistributionController::class, 'levelChange']);
});
