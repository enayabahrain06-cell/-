<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\MessageType;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use App\Models\MessageLog;
use App\Models\Student;
use App\Services\AuditLogger;
use App\Services\Dashboard\DashboardService;
use App\Services\Messaging\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * Open alerts the viewer may see (the dashboard alerts section and "view all"), most severe and newest first.
     * Conflict alerts carry a `conflict` summary (hall name, count, date range, weekdays, time, full session list);
     * repeated-absence alerts carry `absence.consecutive`.
     *
     * @queryParam type string Alert type, e.g. location_conflict. Example: repeated_absence
     * @queryParam page integer Example: 1
     * @queryParam per_page integer 1–100, default 20. Example: 20
     * @queryParam term string Package term (packages.term). Example: 2026-2027
     */
    public function alerts(Request $request, DashboardService $dashboard): JsonResponse
    {
        abort_unless($request->user()->can('dashboard.view'), 403);
        $v = $request->validate([
            'type' => ['nullable', Rule::enum(AlertType::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'term' => ['nullable', 'string', 'max:60'],
        ]);

        return response()->json($dashboard->alertsPage(
            $request->user(), $v['type'] ?? null, (int) ($v['page'] ?? 1), (int) ($v['per_page'] ?? 20), null, $v['term'] ?? null,
        ));
    }

    /** Mark an alert as handled; records who and when. */
    public function resolveAlert(Request $request, Alert $alert, DashboardService $dashboard): JsonResponse
    {
        $user = $request->user();
        abort_unless(($user->can('lessons.manage') || $user->can('registrations.manage')) && $dashboard->canSeeAlert($user, $alert), 403);

        $alert->update(['status' => AlertStatus::Resolved, 'resolved_by' => $user->id, 'resolved_at' => now()]);

        return response()->json([
            'message' => __('dashboard.alert_resolved'),
            'resolved_by' => $user->name,
            'resolved_at' => display_tz($alert->resolved_at)?->toIso8601String(),
        ]);
    }

    /** Repeated absence: WhatsApp the guardian through the messaging system, at most once a day per student. */
    public function messageGuardian(Request $request, Alert $alert, DashboardService $dashboard, MessageService $messages, AuditLogger $audit): JsonResponse
    {
        $user = $request->user();
        abort_unless($alert->type === AlertType::RepeatedAbsence && $alert->subject instanceof Student, 404);
        abort_unless(($user->can('messages.send') || $user->can('lessons.manage')) && $dashboard->canSeeAlert($user, $alert), 403);

        /** @var Student $student */
        $student = $alert->subject;
        if (! $student->guardian_phone) {
            return response()->json(['message' => __('dashboard.guardian_no_phone')], 422);
        }

        $dayStart = now(config('ahl.display_timezone', 'Asia/Bahrain'))->startOfDay()->utc();
        $already = MessageLog::where('student_id', $student->id)
            ->where('template_key', MessageType::RepeatedAbsence->value)
            ->where('created_at', '>=', $dayStart)->exists();
        if ($already) {
            return response()->json(['message' => __('dashboard.guardian_already_messaged')], 429);
        }

        $locale = $student->locale?->value ?? 'ar';
        $count = (int) ($dashboard->consecutiveAbsences([$student->id])[$student->id] ?? 0);
        $count = max($count, 3);
        $authority = $locale === 'en' ? config('ahl.authority.name_en') : config('ahl.authority.name_ar');
        $log = $messages->send(
            $student->guardian_phone,
            MessageType::RepeatedAbsence,
            [
                'absence_count' => (string) $count,
                'guardian_name' => $student->guardian?->name ?? '',
                // Used only when no active repeated_absence template exists.
                'body' => __('dashboard.guardian_message_body', ['name' => $student->full_name, 'count' => $count, 'authority' => $authority], $locale),
            ],
            $locale,
            $student,
        );
        abort_unless($log !== null, 422, __('dashboard.guardian_no_phone'));

        $audit->record('alert.guardian_messaged', $alert, [], ['student_id' => $student->id, 'message_log_id' => $log->id]);

        return response()->json(['message' => __('dashboard.guardian_messaged')]);
    }
}
