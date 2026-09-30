<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\ActivityPanel;
use App\Services\Dashboard\UpcomingPanel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Dashboard
 */
class DashboardFeedController extends Controller
{
    /**
     * "Coming up this week": exams and packages ending in the next 7 days, plus certificates awaiting
     * approval and unhandled inbound messages. Each item type follows its own permission.
     *
     * @queryParam term string Package term (packages.term). Example: 2026-2027
     */
    public function upcoming(Request $request, UpcomingPanel $panel): JsonResponse
    {
        $term = $this->term($request);

        return response()->json(['data' => $panel->build($request->user(), $term)]);
    }

    /**
     * The 10 most recent events (attendance, evaluation, registration, payment, schedule change).
     *
     * @queryParam term string Package term. Example: 2026-2027
     */
    public function activity(Request $request, ActivityPanel $panel): JsonResponse
    {
        $term = $this->term($request);

        return response()->json(['data' => $panel->page($request->user(), $term, 1, 10)['data']]);
    }

    /**
     * Full activity log over the last 30 days, paginated.
     *
     * @queryParam page integer Example: 1
     * @queryParam per_page integer 1–100, default 20. Example: 20
     * @queryParam term string Package term. Example: 2026-2027
     */
    public function activityAll(Request $request, ActivityPanel $panel): JsonResponse
    {
        $term = $this->term($request);
        $v = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($panel->page($request->user(), $term, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 20)));
    }

    /** ?term_id= (academic term) or the older ?term= label. */
    private function term(Request $request): int|string|null
    {
        abort_unless($request->user()->can('dashboard.view'), 403);
        $request->validate(['term' => ['nullable', 'string', 'max:60']]);

        return \App\Support\TermScope::fromRequest($request);
    }
}
