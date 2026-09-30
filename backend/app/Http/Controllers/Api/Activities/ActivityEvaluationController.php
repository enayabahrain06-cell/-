<?php

namespace App\Http\Controllers\Api\Activities;

use App\Models\Activity;
use App\Models\ActivityEvaluation;
use App\Models\ActivityRegistration;
use App\Services\AuditLogger;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Activities
 *
 * تقييم طلبة البرامج (activities.evaluate): a score out of 100, an optional grade word and notes per registered
 * student. عرض تقييم طلبة البرامج reads the roster.
 */
class ActivityEvaluationController extends ActivityBase
{
    public function __construct(private AuditLogger $audit) {}

    public function update(Request $request, Activity $activity): JsonResponse
    {
        abort_unless($request->user()->can('activities.evaluate'), 403);
        $this->reach($request, $activity);
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*.student_id' => ['required', 'integer'],
            'rows.*.score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'rows.*.grade' => ['nullable', 'string', 'max:50'],
            'rows.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $allowed = ActivityRegistration::with('student:id,gender')->where('activity_id', $activity->id)->where('status', 'registered')->get()
            ->filter(fn ($r) => Track::allows($request->user(), $r->student?->gender))->pluck('student_id')->flip();
        $saved = 0;
        DB::transaction(function () use ($data, $allowed, $activity, $request, &$saved) {
            foreach ($data['rows'] as $row) {
                if (! $allowed->has($row['student_id'])) {
                    throw ValidationException::withMessages(['rows' => __('activities.errors.not_registered')]);
                }
                $key = ['activity_id' => $activity->id, 'student_id' => $row['student_id']];
                $grade = trim((string) ($row['grade'] ?? '')) ?: null;
                $notes = trim((string) ($row['notes'] ?? '')) ?: null;
                if (($row['score'] ?? null) === null && $grade === null && $notes === null) {
                    ActivityEvaluation::where($key)->delete();

                    continue;
                }
                ActivityEvaluation::updateOrCreate($key, ['score' => $row['score'] ?? null, 'grade' => $grade, 'notes' => $notes, 'evaluated_by' => $request->user()->id]);
                $saved++;
            }
        });
        $this->audit->record('activity.evaluations_saved', $activity, [], ['rows' => $saved]);

        return response()->json(['message' => __('activities.evaluations_saved', ['count' => $saved])]);
    }
}
