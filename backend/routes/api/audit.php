<?php

use App\Http\Controllers\Api\Audit\AuditLogController;
use Illuminate\Support\Facades\Route;

// Audit log viewer (inside auth:sanctum group)
Route::get('audit-logs', [AuditLogController::class, 'index']);
Route::get('audit-logs/options', [AuditLogController::class, 'options']);
