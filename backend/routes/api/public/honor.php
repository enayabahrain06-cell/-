<?php

use App\Http\Controllers\Api\Engagement\HonorController;
use Illuminate\Support\Facades\Route;

// Public TV display of the published honor board (key from settings honor.display_key).
Route::get('public/honor/display', [HonorController::class, 'display'])->middleware('throttle:120,1');
