<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\Dashboard\FeesPanel;
use App\Services\Dashboard\MemorizationPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Dashboard
 */
class DashboardPanelsController extends Controller
{
    /**
     * Memorization progress card: pages this week, reviews due, juz finished this month, top 5 behind plan.
     *
     * @queryParam term string Package term. Example: 2026-2027
     */
    public function memorization(Request $request, MemorizationPanel $panel): JsonResponse
    {
        abort_unless($request->user()->can('dashboard.view'), 403);

        return response()->json(['data' => $panel->build($request->user(), $this->term($request))]);
    }

    /**
     * Fees & dues card: collected this vs last month, overdue, due this week, top 5 overdue students.
     *
     * @queryParam term string Package term. Example: 2026-2027
     */
    public function fees(Request $request, FeesPanel $panel): JsonResponse
    {
        abort_unless($request->user()->can('dashboard.view') && $request->user()->can('wallets.view'), 403);

        return response()->json(['data' => $panel->build($request->user(), $this->term($request))]);
    }

    /** Send the payment-due reminder for a student's overdue dues (once per family phone per day). */
    public function remind(Request $request, Student $student, FeesPanel $panel): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('dashboard.view') && $user->can('wallets.view') && $panel->canRemind($user), 403);
        abort_unless($panel->visible($user, $student), 403);

        $sent = $panel->remind($user, $student);

        return response()->json(['message' => __('dashboard_panels.fees.reminder_sent'), 'sent' => $sent]);
    }

    /** ?term_id= (academic term) or the older ?term= label. */
    private function term(Request $request): int|string|null
    {
        $request->validate(['term' => ['nullable', 'string', 'max:60']]);

        return \App\Support\TermScope::fromRequest($request);
    }
}
