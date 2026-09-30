<?php

use App\Http\Controllers\Api\Admin\MenuLayoutController;
use Illuminate\Support\Facades\Route;

// القائمة: the shared staff menu layout (inside the auth:sanctum group).
Route::get('menu-layout', [MenuLayoutController::class, 'show']);
Route::put('menu-layout', [MenuLayoutController::class, 'update']);
