<?php

use App\Http\Controllers\Api\Admin\SettingsController;
use Illuminate\Support\Facades\Route;

// Authority logo for the login and registration pages and printouts (no login needed).
Route::get('public/logo', [SettingsController::class, 'logo'])->middleware('throttle:60,1');
