<?php

namespace App\Http\Controllers\Api\Circles;

use App\Enums\Gender;
use App\Enums\LessonStudentStatus;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Policies\LessonPolicy;
use App\Services\Circles\CircleEnrollmentService;
use App\Services\Circles\CircleMatcher;
use App\Services\Registration\PackageSuitability;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Lessons & locations
 * @subgroup Circle enrollment
 */
class CircleController extends Controller
{
    public function __construct(private CircleMatcher $matcher, private CircleEnrollmentService $circles) {}

    /**
     * Circles for a gender and birth date (optionally one package), fitting ones first, best match first.
     * Used by circle pickers (quick enrollment, accepting a request, moving a student).
     */
    public function match(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lessons.view') || $request->user()->can('enrollment.quick') || $request->user()->can('registrations.manage'), 403);
        $data = $request->validate([
            'gender' => ['required', 'in:male,female'],
            'birth_date' => ['required', 'date', 'before:today'],
            'package_id' => ['nullable', 'integer'],
            'fitting_only' => ['boolean'],
        ]);

        $rows = $this->matcher->evaluate(Gender::from($data['gender']), Carbon::parse($data['birth_date']), $request->user(), $data['package_id'] ?? null);
        if ($request->boolean('fitting_only')) {
            $rows = $rows->where('fits', true)->values();
        }

        return response()->json([
            'data' => $rows->map(fn ($r) => CircleMatcher::present($r))->values(),
            'recommended_id' => $rows->firstWhere('fits', true)['lesson']->id ?? null,
        ]);
    }

    /** The student's circle history (one row per stay), newest first. */
    public function history(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        return response()->json(['data' => $this->circles->history($student)->map(fn (LessonStudent $r) => [
            'id' => $r->id,
            'lesson' => $r->lesson ? ['id' => $r->lesson->id, 'name' => $r->lesson->name, 'teacher' => $r->lesson->teacher?->name, 'age_group' => $r->lesson->ageGroup?->name()] : null,
            'status' => $r->status?->value,
            'joined_at' => $r->joined_at?->toDateString(),
            'left_at' => $r->left_at?->toDateString(),
            'moved_by' => $r->mover?->name,
            'moved_to' => $r->movedTo ? ['id' => $r->movedTo->id, 'name' => $r->movedTo->name] : null,
            'reason' => $r->reason,
        ])->values()]);
    }

    /** Circles the student could move to (not the current one), with why others do not fit. */
    public function moveOptions(Request $request, Student $student): JsonResponse
    {
        $this->authorizeMove($request, $student);
        $current = $this->circles->activeRows($student)->first();

        $rows = $this->matcher->evaluate($student->gender, $student->birth_date, $request->user(), null, $current?->lesson_id);

        return response()->json([
            'current' => $current?->lesson ? ['id' => $current->lesson->id, 'name' => $current->lesson->name] : null,
            'data' => $rows->map(fn ($r) => CircleMatcher::present($r))->values(),
            'recommended_id' => $rows->firstWhere('fits', true)['lesson']->id ?? null,
        ]);
    }

    /** Move the student to another circle from an effective date; the old stay is closed with who and why. */
    public function move(Request $request, Student $student): JsonResponse
    {
        $this->authorizeMove($request, $student);
        $data = $request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
            'effective_date' => ['nullable', 'date', 'after_or_equal:'.today()->subDays(30)->toDateString()],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $to = Lesson::findOrFail($data['lesson_id']);
        abort_unless(LessonPolicy::ownsOrManages($request->user(), $to), 403);
        if ($this->circles->activeRows($student)->contains('lesson_id', $to->id)) {
            return response()->json(['message' => __('circles.errors.same_circle'), 'errors' => ['lesson_id' => [__('circles.errors.same_circle')]]], 422);
        }

        $row = $this->circles->move($student, $to, $request->user(), Carbon::parse($data['effective_date'] ?? today()), $data['reason'] ?? null);

        return response()->json(['message' => __('circles.moved', ['circle' => $to->name]), 'data' => ['id' => $row->id, 'lesson_id' => $to->id, 'joined_at' => $row->joined_at?->toDateString()]]);
    }

    /**
     * Yearly check: students whose age on a date (default: today) is outside their circle's age range,
     * each with the best circle to move them to.
     */
    public function agedOut(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lessons.manage'), 403);
        $on = $request->filled('on') ? Carbon::parse($request->date('on')) : today();

        $rows = LessonStudent::with(['student', 'lesson.package', 'lesson.ageGroup', 'lesson.teacher:id,name'])
            ->where('status', LessonStudentStatus::Active->value)
            ->whereHas('student', fn ($q) => Track::scope($q, $request->user()))
            ->get()
            ->filter(fn (LessonStudent $r) => $r->student?->birth_date && $r->lesson)
            ->map(function (LessonStudent $r) use ($on, $request) {
                $age = PackageSuitability::ageOn($r->student->birth_date, $on);
                [$min, $max] = CircleMatcher::range($r->lesson);
                if ($age >= $min && ($max === null || $age <= $max)) {
                    return null;
                }
                $best = $this->matcher->candidates($r->student->gender, $r->student->birth_date, $request->user(), null, $r->lesson_id, $on)->first();

                return [
                    'student' => ['id' => $r->student->id, 'full_name' => $r->student->full_name, 'student_no' => $r->student->student_no, 'gender' => $r->student->gender?->value],
                    'age' => $age,
                    'lesson' => ['id' => $r->lesson->id, 'name' => $r->lesson->name, 'teacher' => $r->lesson->teacher?->name, 'min_age' => $min, 'max_age' => $max, 'age_group' => $r->lesson->ageGroup?->name()],
                    'direction' => $age > ($max ?? PHP_INT_MAX) ? 'older' : 'younger',
                    'suggested' => $best ? ['id' => $best['lesson']->id, 'name' => $best['lesson']->name, 'free_seats' => $best['free_seats']] : null,
                ];
            })->filter()->sortBy(fn ($r) => [$r['lesson']['name'], $r['student']['full_name']])->values();

        return response()->json(['on' => $on->toDateString(), 'data' => $rows]);
    }

    private function authorizeMove(Request $request, Student $student): void
    {
        $user = $request->user();
        abort_unless(Track::allows($user, $student->gender), 403);
        $current = $this->circles->activeRows($student)->first();
        // Supervisors (lessons.manage) in the track, or the teacher of the current circle.
        abort_unless($user->can('lessons.manage') || ($current?->lesson && $current->lesson->teacher_id === $user->id), 403);
    }
}
