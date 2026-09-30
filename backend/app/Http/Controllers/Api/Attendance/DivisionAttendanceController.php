<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Division;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\User;
use App\Policies\LessonPolicy;
use App\Services\Attendance\AttendanceService;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @group Attendance
 * @subgroup Divisions
 *
 * حضور التقسيم and مراقبة تسجيل حضور التقسيم. Attendance of one division is the class session's attendance for the
 * division's students: the same attendances rows, saved through AttendanceService (no other table).
 *
 * Reach: managers (lessons.manage, in their track), the teachers of the class (TeacherScope), and the division's own
 * teacher. Reading needs attendance.view or attendance.record; saving needs attendance.record.
 */
class DivisionAttendanceController extends Controller
{
    public function __construct(private AttendanceService $service) {}

    /**
     * The night's sessions (not cancelled) of the term with the divisions the user reaches: each division's student
     * count and how many of them have an attendance row. Used by both screens (the monitor highlights missing > 0).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('attendance.view') || $user->can('attendance.record'), 403);
        $term = TermScope::single($request);
        $date = $request->validate(['date' => ['nullable', 'date']])['date'] ?? today()->toDateString();
        $date = substr((string) $date, 0, 10);

        $sessions = LessonSession::with(['lesson:id,name,teacher_id,gender,level_id', 'location:id,name'])
            ->where('session_date', $date)->where('status', '!=', SessionStatus::Cancelled->value)
            ->tap(fn ($q) => TermScope::via($q, $term->id, 'lesson.package'))
            ->tap(fn ($q) => Track::scopeVia($q, $user, 'lesson'))
            ->orderBy('start_time')->get();
        $lessonIds = $sessions->pluck('lesson_id')->unique()->values();
        $divisions = Division::with(['teacher:id,name', 'students:id'])->whereIn('lesson_id', $lessonIds->all() ?: [0])
            ->where('academic_term_id', $term->id)->orderBy('sort')->orderBy('name')->get()->groupBy('lesson_id');

        $manager = $user->can('lessons.manage');
        $taught = $manager ? [] : TeacherScope::lessonIds($user)->whereIn('lessons.id', $lessonIds->all() ?: [0])->pluck('lessons.id')->map(fn ($v) => (int) $v)->all();
        $active = LessonStudent::whereIn('lesson_id', $lessonIds->all() ?: [0])->where('status', LessonStudentStatus::Active->value)
            ->get(['lesson_id', 'student_id'])->groupBy('lesson_id')->map(fn ($g) => $g->pluck('student_id')->map(fn ($v) => (int) $v)->all());
        $recorded = Attendance::whereIn('lesson_session_id', $sessions->pluck('id')->all() ?: [0])->get(['lesson_session_id', 'student_id'])
            ->groupBy('lesson_session_id')->map(fn ($g) => $g->pluck('student_id')->map(fn ($v) => (int) $v)->all());

        $out = [];
        foreach ($sessions as $s) {
            $all = $manager || in_array((int) $s->lesson_id, $taught, true);
            $mine = $divisions->get($s->lesson_id, collect())->filter(fn (Division $d) => $all || (int) $d->teacher_id === (int) $user->id);
            if ($mine->isEmpty()) {
                continue;
            }
            $inClass = $active->get($s->lesson_id, []);
            $done = $recorded->get($s->id, []);
            $out[] = [
                'id' => $s->id,
                'session_date' => $s->session_date->toDateString(),
                'start_time' => substr((string) $s->start_time, 0, 5),
                'end_time' => substr((string) $s->end_time, 0, 5),
                'attendance_taken' => $s->attendance_taken_at !== null,
                'lesson' => ['id' => $s->lesson->id, 'name' => $s->lesson->name],
                'location' => $s->location ? ['id' => $s->location->id, 'name' => $s->location->name] : null,
                'divisions' => $mine->map(function (Division $d) use ($inClass, $done) {
                    $ids = array_values(array_intersect($d->students->pluck('id')->map(fn ($v) => (int) $v)->all(), $inClass));
                    $count = count(array_intersect($ids, $done));

                    return [
                        'id' => $d->id, 'name' => $d->name,
                        'teacher' => $d->teacher ? ['id' => $d->teacher->id, 'name' => $d->teacher->name] : null,
                        'students' => count($ids), 'recorded' => $count, 'missing' => count($ids) - $count,
                    ];
                })->values(),
            ];
        }

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'date' => $date,
            'can_record' => $user->can('attendance.record'),
            'data' => $out,
        ]);
    }

    /** The division's students in the session with their attendance (or null). */
    public function show(Request $request, Division $division, LessonSession $session): JsonResponse
    {
        $user = $request->user();
        $this->assertSession($division, $session);
        abort_unless(($user->can('attendance.view') || $user->can('attendance.record')) && $this->reaches($user, $division), 403);

        $ids = $this->studentIds($division);
        $rows = collect($this->service->roster($session))->filter(fn ($r) => in_array((int) $r['student']->id, $ids, true))
            ->sortBy(fn ($r) => $r['student']->full_name)->values();
        $session->loadMissing(['lesson:id,name', 'location:id,name']);

        return response()->json([
            'session' => [
                'id' => $session->id, 'session_date' => $session->session_date->toDateString(),
                'start_time' => substr((string) $session->start_time, 0, 5), 'end_time' => substr((string) $session->end_time, 0, 5),
                'status' => $session->status?->value, 'attendance_taken' => $session->attendance_taken_at !== null,
                'lesson' => ['id' => $session->lesson->id, 'name' => $session->lesson->name],
                'location' => $session->location ? ['id' => $session->location->id, 'name' => $session->location->name] : null,
            ],
            'division' => ['id' => $division->id, 'name' => $division->name, 'teacher' => $division->teacher ? ['id' => $division->teacher->id, 'name' => $division->teacher->name] : null],
            'can_record' => $user->can('attendance.record') && $session->status !== SessionStatus::Cancelled,
            'data' => $rows->map(fn ($r) => [
                'student' => ['id' => $r['student']->id, 'full_name' => $r['student']->full_name, 'student_no' => $r['student']->student_no],
                'attendance' => $r['attendance'] ? ['status' => $r['attendance']->status?->value, 'note' => $r['attendance']->note] : null,
            ])->values(),
        ]);
    }

    /** Save the division's rows (status and note only; assignments already entered are kept). */
    public function save(Request $request, Division $division, LessonSession $session): JsonResponse
    {
        $user = $request->user();
        $this->assertSession($division, $session);
        abort_unless($user->can('attendance.record') && $this->reaches($user, $division), 403);
        $data = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'integer', 'distinct'],
            'records.*.status' => ['required', AttendanceStatus::rule()],
            'records.*.note' => ['nullable', 'string', 'max:1000'],
        ]);
        $ids = $this->studentIds($division);
        if (array_diff(array_map(fn ($r) => (int) $r['student_id'], $data['records']), $ids)) {
            throw ValidationException::withMessages(['records' => __('staff_attendance.errors.not_in_division')]);
        }
        $records = array_map(fn ($r) => ['student_id' => (int) $r['student_id'], 'status' => $r['status']] + (array_key_exists('note', $r) ? ['note' => $r['note']] : []), $data['records']);
        $result = $this->service->save($session, $records, $user);

        return response()->json(['message' => __('api.saved')] + $result);
    }

    private function assertSession(Division $division, LessonSession $session): void
    {
        abort_unless((int) $division->lesson_id === (int) $session->lesson_id, 404);
    }

    private function reaches(User $user, Division $division): bool
    {
        return (int) $division->teacher_id === (int) $user->id || LessonPolicy::ownsOrManages($user, $division->lesson);
    }

    /** Division members who are active students of the class. @return list<int> */
    private function studentIds(Division $division): array
    {
        return DB::table('division_students')->join('lesson_students', fn ($j) => $j->on('lesson_students.student_id', '=', 'division_students.student_id')
            ->where('lesson_students.lesson_id', $division->lesson_id)->where('lesson_students.status', LessonStudentStatus::Active->value))
            ->where('division_students.division_id', $division->id)->pluck('division_students.student_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
