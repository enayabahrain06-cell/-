<?php

namespace App\Http\Controllers\Api\Activities;

use App\Http\Resources\StudentSummaryResource;
use App\Models\Activity;
use App\Models\ActivityAttendance;
use App\Models\ActivityRegistration;
use App\Services\AuditLogger;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Activities
 *
 * تسجيل حضور البرنامج / حضور الرحلة (activities.attendance) and their follow-up (activities.view): the registered
 * students on one day of the activity. Days outside the activity's dates, or after today, are refused.
 */
class ActivityAttendanceController extends ActivityBase
{
    public function __construct(private AuditLogger $audit) {}

    public function show(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeAny($request, 'activities.attendance', 'activities.view');
        $this->reach($request, $activity);
        $dates = $this->recordableDates($activity);
        $asked = $request->validate(['date' => ['nullable', 'date']])['date'] ?? null;
        $date = $asked ? Carbon::parse($asked)->toDateString() : (in_array(today()->toDateString(), $dates, true) ? today()->toDateString() : (end($dates) ?: $activity->starts_on->toDateString()));
        $records = ActivityAttendance::where('activity_id', $activity->id)->where('attendance_date', $date)->get()->keyBy('student_id');
        $regs = $this->registered($request, $activity);

        return response()->json([
            'activity' => self::activity(self::withCounts(Activity::query())->find($activity->id)),
            'date' => $date,
            'dates' => $dates,
            'data' => $regs->map(fn (ActivityRegistration $r) => [
                'student' => (new StudentSummaryResource($r->student))->toArray($request),
                'status' => $records->get($r->student_id)?->status,
                'notes' => $records->get($r->student_id)?->notes,
            ])->values(),
        ]);
    }

    public function update(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->can('activities.attendance'), 403);
        $this->reach($request, $activity);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.status' => ['nullable', Rule::in(ActivityAttendance::STATUSES)],
            'rows.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        $date = Carbon::parse($data['date'])->toDateString();
        if (! in_array($date, $activity->dates(), true)) {
            throw ValidationException::withMessages(['date' => __('activities.errors.date_outside')]);
        }
        if ($date > today()->toDateString()) {
            throw ValidationException::withMessages(['date' => __('activities.errors.date_future')]);
        }
        $allowed = $this->registered($request, $activity)->pluck('student_id')->flip();
        $saved = 0;
        DB::transaction(function () use ($data, $allowed, $activity, $date, $request, &$saved) {
            foreach ($data['rows'] as $row) {
                if (! $allowed->has($row['student_id'])) {
                    throw ValidationException::withMessages(['rows' => __('activities.errors.not_registered')]);
                }
                $key = ['activity_id' => $activity->id, 'student_id' => $row['student_id'], 'attendance_date' => $date];
                if (empty($row['status'])) {
                    ActivityAttendance::where($key)->delete();

                    continue;
                }
                ActivityAttendance::updateOrCreate($key, ['status' => $row['status'], 'notes' => $row['notes'] ?? null, 'recorded_by' => $request->user()->id]);
                $saved++;
            }
        });
        $this->audit->record('activity.attendance_saved', $activity, [], ['date' => $date, 'rows' => $saved]);

        return response()->json(['message' => __('activities.attendance_saved', ['count' => $saved])]);
    }

    /** متابعة الحضور: each date's tally over the registered students; per student rates are on the roster. */
    public function summary(Request $request, Activity $activity): JsonResponse
    {
        $this->authorizeAny($request, 'activities.view', 'activities.attendance');
        $this->reach($request, $activity);
        $regs = $this->registered($request, $activity);
        $rows = ActivityAttendance::where('activity_id', $activity->id)->whereIn('student_id', $regs->pluck('student_id'))->get()
            ->groupBy(fn ($r) => $r->attendance_date->toDateString());

        return response()->json([
            'registered' => $regs->count(),
            'data' => collect($this->recordableDates($activity))->map(fn ($d) => ['date' => $d, ...ActivityRegistrationController::tally($rows->get($d, collect()))])->values(),
        ]);
    }

    /** @return list<string> the activity's days up to today */
    private function recordableDates(Activity $activity): array
    {
        $today = today()->toDateString();

        return array_values(array_filter($activity->dates(), fn ($d) => $d <= $today));
    }

    private function registered(Request $request, Activity $activity): \Illuminate\Support\Collection
    {
        return ActivityRegistration::with('student')->where('activity_id', $activity->id)->where('status', 'registered')
            ->whereHas('student', fn ($q) => Track::scope($q, $request->user()))->get()->sortBy('student.full_name')->values();
    }
}
