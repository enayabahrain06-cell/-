<?php

use App\Http\Controllers\Api\Registration\PackageController;
use App\Http\Controllers\Api\Registration\RegistrationRequestController;
use Illuminate\Support\Facades\Route;

// Packages & registration requests (inside auth:sanctum group)
Route::apiResource('packages', PackageController::class);

Route::get('registrations', [RegistrationRequestController::class, 'index']);
Route::post('registrations/bulk-accept', [RegistrationRequestController::class, 'bulkAccept']);
Route::get('registrations/{registration}', [RegistrationRequestController::class, 'show']);
Route::get('registrations/{registration}/circles', [RegistrationRequestController::class, 'circles']);
Route::put('registrations/{registration}', [RegistrationRequestController::class, 'update']);
Route::post('registrations/{registration}/photo', [RegistrationRequestController::class, 'photo']);
Route::post('registrations/{registration}/accept', [RegistrationRequestController::class, 'accept']);
Route::post('registrations/{registration}/waitlist', [RegistrationRequestController::class, 'waitlist']);
Route::post('registrations/{registration}/reject', [RegistrationRequestController::class, 'reject']);
