<?php

use App\Http\Controllers\Api\Reports\ReportsController;
use App\Services\Reports\ReportCatalog;
use Illuminate\Support\Facades\Route;

// Reports screen (inside auth:sanctum group). reports/finance and reports/progress|issues|tracks/* keep their own routes.
Route::get('reports', [ReportsController::class, 'index']);
Route::get('reports/{key}', [ReportsController::class, 'show'])->whereIn('key', ReportCatalog::SERVED);
