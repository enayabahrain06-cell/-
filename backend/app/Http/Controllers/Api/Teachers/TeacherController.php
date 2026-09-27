<?php

namespace App\Http\Controllers\Api\Teachers;

use App\Enums\LessonStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Reports\TeacherPerformanceReport;
use App\Support\Track;
use App\Support\WeekDays;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * @group Teachers
 */
class TeacherController extends Controller
{
    /** Week order on the Teachers page (the authority's week starts on Saturday). */
    private const WEEK = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    /**
     * Teachers (users with the teacher role) with gender, specialization and circle load.
     * Filters: gender (male|female; "mixed" means female, the early-years rule), search, active.
     * Scoped staff only see teachers of their own track.
     *
     * Without `stats` the response is the plain picker list used by the lessons screens.
     * With `stats=1` it is paginated (`page`, `per_page`) and each teacher carries this month's numbers:
     * active students, sessions held / cancelled, attendance-taken rate and average evaluation.
     *
     * @queryParam stats boolean Example: 1
     * @queryParam page integer Example: 1
     */
    public function index(Request $request, TeacherPerformanceReport $performance): JsonResponse
    {
        abort_unless($request->user()->can('teachers.view') || $request->user()->can('lessons.manage'), 403);

        $gender = $request->string('gender')->toString();
        if ($gender === 'mixed') {
            $gender = 'female';
        }
        $limit = Track::genderFor($request->user())?->value;

        $teachers = User::role('teacher')->with('teacher')
            ->withCount(['lessons as active_circles_count' => fn ($q) => $q->where('status', 'active')])
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$request->string('search').'%')->orWhere('phone', 'like', '%'.$request->string('search').'%')))
            ->orderBy('name')
            ->get()
            ->filter(function (User $u) use ($gender, $limit) {
                $g = Track::staffGender($u)?->value;

                return (! $gender || $g === $gender) && (! $limit || $g === $limit);
            })
            ->values();

        $row = fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'gender' => Track::staffGender($u)?->value,
            'specialization' => $u->teacher?->specialization,
            'is_active' => (bool) $u->is_active,
            'active_circles' => (int) $u->active_circles_count,
        ];

        if (! $request->boolean('stats')) {
            return response()->json(['data' => $teachers->map($row)]);
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));
        $lastPage = max(1, (int) ceil($teachers->count() / $perPage));
        $page = min(max(1, (int) $request->integer('page', 1)), $lastPage);
        $pageItems = $teachers->forPage($page, $perPage)->values();
        $ids = $pageItems->pluck('id')->all();

        [$stats] = $performance->stats($request->user(), []);
        $students = $this->activeStudentCounts($ids);

        return response()->json([
            'data' => $pageItems->map(fn (User $u) => $row($u) + [
                'active_students' => $students[$u->id] ?? 0,
                'month' => $this->monthNumbers($stats->get($u->id)),
            ]),
            'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'total' => $teachers->count(), 'per_page' => $perPage],
        ]);
    }

    /** Teacher profile: account, circles, weekly timetable, this month's numbers and the next 7 days of sessions. */
    public function show(Request $request, User $teacher, TeacherPerformanceReport $performance): JsonResponse
    {
        $this->authorizeView($request->user(), $teacher);
        $teacher->loadMissing('teacher');
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $today = now($tz)->toDateString();

        $lessons = Lesson::with(['package:id,name', 'location:id,name'])->withCount('activeStudents')
            ->where('teacher_id', $teacher->id)->orderBy('name')->get();
        $active = $lessons->filter(fn (Lesson $l) => $l->status === LessonStatus::Active)->values();

        [$stats] = $performance->stats($request->user(), ['teacher_id' => $teacher->id]);

        $upcoming = LessonSession::with(['lesson:id,name', 'location:id,name'])
            ->whereIn('lesson_id', $lessons->pluck('id')->all() ?: [0])
            ->whereBetween('session_date', [$today, now($tz)->addDays(6)->toDateString()])
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->orderBy('session_date')->orderBy('start_time')->limit(30)->get();

        $evaluations30 = Evaluation::whereIn('lesson_id', $lessons->pluck('id')->all() ?: [0])
            ->where('evaluated_on', '>=', now($tz)->subDays(29)->toDateString())->count();

        $viewer = $request->user();

        return response()->json(['data' => [
            'id' => $teacher->id,
            'name' => $teacher->name,
            'phone' => $teacher->phone,
            'email' => $teacher->email,
            'gender' => Track::staffGender($teacher)?->value,
            'specialization' => $teacher->teacher?->specialization,
            'bio' => $teacher->teacher?->bio,
            'is_active' => (bool) $teacher->is_active,
            'last_login_at' => display_tz($teacher->last_login_at)?->toIso8601String(),
            'active_students' => $this->activeStudentCounts([$teacher->id])[$teacher->id] ?? 0,
            'month' => $this->monthNumbers($stats->get($teacher->id)),
            'evaluations_30d' => $evaluations30,
            'circles' => $lessons->map(fn (Lesson $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'package' => $l->package?->name,
                'status' => $l->status->value,
                'gender' => $l->gender?->value,
                'days' => $this->orderedDays($l->days ?? []),
                'start_time' => substr((string) $l->start_time, 0, 5),
                'end_time' => substr((string) $l->end_time, 0, 5),
                'location' => $l->location?->name,
                'students' => (int) $l->active_students_count,
                'capacity' => $l->capacity,
            ])->values(),
            'timetable' => $this->timetable($active),
            'upcoming' => $upcoming->map(fn (LessonSession $s) => [
                'id' => $s->id,
                'lesson_id' => $s->lesson_id,
                'lesson' => $s->lesson?->name,
                'date' => $s->session_date->toDateString(),
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'location' => $s->location?->name,
                'attendance_taken' => $s->attendance_taken_at !== null,
            ])->values(),
            'can' => [
                'edit' => $viewer->can('teachers.manage'),
                'edit_account' => $viewer->can('users.manage'),
                'take_attendance' => $viewer->can('attendance.record'),
            ],
        ]]);
    }

    /** Teacher record details (specialization, bio). The account itself (name, phone, active) is edited on the Users page. */
    public function update(Request $request, User $teacher, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('teachers.manage'), 403);
        $this->authorizeView($request->user(), $teacher);

        $data = $request->validate([
            'specialization' => ['nullable', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = Teacher::firstOrNew(['user_id' => $teacher->id]);
        if (! $record->exists) {
            $record->gender = Track::staffGender($teacher)?->value ?? $teacher->gender ?? 'male';
            $record->is_active = true;
        }
        $old = $record->exists ? $record->only(['specialization', 'bio']) : [];
        $record->fill($data)->save();

        $changed = array_diff_assoc(array_map(fn ($v) => (string) $v, $record->only(['specialization', 'bio'])), array_map(fn ($v) => (string) $v, $old + ['specialization' => null, 'bio' => null]));
        if ($changed) {
            $audit->record('teacher.updated', $record, array_intersect_key($old, $changed), array_intersect_key($record->only(['specialization', 'bio']), $changed));
        }

        return response()->json(['message' => __('api.saved'), 'data' => $record->only(['specialization', 'bio'])]);
    }

    /** Staff with teachers.view (or lessons.manage) see teachers of their track; a teacher may open their own page. */
    private function authorizeView(User $viewer, User $teacher): void
    {
        abort_unless($teacher->hasRole('teacher'), 404);

        $staff = $viewer->can('teachers.view') || $viewer->can('lessons.manage');
        abort_unless(($staff && Track::allows($viewer, Track::staffGender($teacher))) || $viewer->id === $teacher->id, 403);
    }

    /**
     * Distinct active students in each teacher's active circles.
     *
     * @param  list<int>  $teacherIds
     * @return array<int, int>
     */
    private function activeStudentCounts(array $teacherIds): array
    {
        if (! $teacherIds) {
            return [];
        }
        $lessonTeacher = Lesson::whereIn('teacher_id', $teacherIds)->where('status', LessonStatus::Active->value)->pluck('teacher_id', 'id');

        return LessonStudent::whereIn('lesson_id', $lessonTeacher->keys()->all() ?: [0])
            ->where('status', LessonStudentStatus::Active->value)->get(['lesson_id', 'student_id'])
            ->groupBy(fn ($r) => $lessonTeacher[$r->lesson_id])
            ->map(fn ($rows) => $rows->pluck('student_id')->unique()->count())->all();
    }

    private function monthNumbers(?array $s): array
    {
        return [
            'sessions_due' => (int) ($s['sessions_due'] ?? 0),
            'held' => (int) ($s['held'] ?? 0),
            'cancelled' => (int) ($s['cancelled'] ?? 0),
            'taken_rate' => $s['taken_rate'] ?? null,
            'on_time_rate' => $s['on_time_rate'] ?? null,
            'evaluations' => (int) ($s['evaluations'] ?? 0),
            'avg_evaluation' => $s['avg_evaluation'] ?? null,
            'attendance_rate' => $s['attendance_rate'] ?? null,
        ];
    }

    /** @return list<string> */
    private function orderedDays(array $days): array
    {
        return array_values(array_filter(self::WEEK, fn ($d) => in_array($d, $days, true)));
    }

    /** Seven days, Saturday first, each with the circles that meet that day sorted by start time. */
    private function timetable(Collection $lessons): array
    {
        return collect(self::WEEK)->map(fn (string $day) => [
            'day' => $day,
            'items' => $lessons->filter(fn (Lesson $l) => in_array($day, $l->days ?? [], true))
                ->sortBy(fn (Lesson $l) => WeekDays::time((string) $l->start_time))
                ->map(fn (Lesson $l) => [
                    'lesson_id' => $l->id,
                    'name' => $l->name,
                    'start_time' => substr((string) $l->start_time, 0, 5),
                    'end_time' => substr((string) $l->end_time, 0, 5),
                    'location' => $l->location?->name,
                ])->values()->all(),
        ])->all();
    }
}
