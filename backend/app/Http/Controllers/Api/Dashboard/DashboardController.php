<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Enums\AlertStatus;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Dashboard
 */
class DashboardController extends Controller
{
    /** KPIs, today's sessions with hall status, 14-day attendance chart and the alerts box (scoped to the viewer). */
    public function show(Request $request, DashboardService $dashboard): JsonResponse
    {
        abort_unless($request->user()->can('dashboard.view'), 403);

        return response()->json(['data' => $dashboard->build($request->user())]);
    }

    /** Mark an alert as handled. */
    public function resolveAlert(Request $request, Alert $alert, DashboardService $dashboard): JsonResponse
    {
        $user = $request->user();
        abort_unless(($user->can('lessons.manage') || $user->can('registrations.manage')) && $dashboard->canSeeAlert($user, $alert), 403);

        $alert->update(['status' => AlertStatus::Resolved, 'resolved_by' => $user->id, 'resolved_at' => now()]);

        return response()->json(['message' => __('dashboard.alert_resolved')]);
    }
}
