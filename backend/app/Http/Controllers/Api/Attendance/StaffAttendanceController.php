<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\LessonSession;
use App\Models\NightSupervisor;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Services\Attendance\NightStaff;
use App\Services\AuditLogger;
use App\Support\TermScope;
use App\Support\WeekDays;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Attendance
 * @subgroup Staff
 *
 * حضور المشرفين / عرض حضور المشرفين / عرض حضور المعلمين (with recording). One table, staff_attendances, per user,
 * night and role kind. Who is expected is read, never copied: supervisors from مشرفو الليالي, teachers from the
 * night's timetable periods (NightStaff). For teachers "took attendance" is derived from their sessions'
 * attendance_taken_at and shown next to the manual status.
 *
 * Reading needs staff_attendance.view or staff_attendance.record; writing needs staff_attendance.record.
 */
class StaffAttendanceController extends Controller
{
    public function __construct(private NightStaff $staff, private AuditLogger $audit) {}

    /** One night: the expected staff of the kind, anyone recorded on top, their rows and the candidates to add. */
    public function day(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $f = $request->validate(['date' => ['nullable', 'date'], 'kind' => ['required', Rule::in(StaffAttendance::KINDS)]]);
        $date = substr((string) ($f['date'] ?? today()->toDateString()), 0, 10);
        $kind = $f['kind'];

        $sessionsByTeacher = $kind === 'teacher' ? $this->staff->teacherSessions($term, $date, $date) : [];
        $expected = $kind === 'teacher' ? array_keys($sessionsByTeacher) : $this->staff->supervisorsOn($term, WeekDays::keyFor(Carbon::parse($date)));
        $rows = StaffAttendance::where('academic_term_id', $term->id)->where('role_kind', $kind)->where('attendance_date', $date)->get()->keyBy('user_id');
        $ids = array_values(array_unique(array_merge($expected, $rows->keys()->map(fn ($v) => (int) $v)->all())));
        $users = User::whereIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'name', 'phone']);

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'date' => $date,
            'kind' => $kind,
            'in_term' => self::inTerm($term, $date),
            'can_record' => $request->user()->can('staff_attendance.record'),
            'data' => $users->map(function (User $u) use ($expected, $rows, $sessionsByTeacher, $kind) {
                $sessions = $sessionsByTeacher[$u->id] ?? collect();

                return [
                    'user' => ['id' => $u->id, 'name' => $u->name, 'phone' => $u->phone],
                    'expected' => in_array($u->id, $expected, true),
                    'record' => ($r = $rows->get($u->id)) ? self::row($r) : null,
                ] + ($kind === 'teacher' ? [
                    'sessions' => $sessions->map(fn (LessonSession $s) => [
                        'id' => $s->id, 'lesson' => ['id' => $s->lesson->id, 'name' => $s->lesson->name],
                        'start_time' => substr((string) $s->start_time, 0, 5), 'end_time' => substr((string) $s->end_time, 0, 5),
                        'attendance_taken' => $s->attendance_taken_at !== null,
                    ])->values(),
                    'took_attendance' => ['taken' => $sessions->whereNotNull('attendance_taken_at')->count(), 'total' => $sessions->count()],
                ] : []);
            })->sortBy(fn ($p) => [$p['expected'] ? 0 : 1, $p['user']['name']])->values(),
            'candidates' => User::role($kind)->where('is_active', true)->whereNotIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]);
    }

    /** Record (or correct) the night's rows. */
    public function save(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('staff_attendance.record'), 403);
        $data = $request->validate([
            'academic_term_id' => ['required', 'integer', 'exists:academic_terms,id'],
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::in(StaffAttendance::KINDS)],
            'records' => ['required', 'array', 'min:1', 'max:300'],
            'records.*.user_id' => ['required', 'integer', 'distinct'],
            'records.*.status' => ['required', Rule::in(StaffAttendance::STATUSES)],
            'records.*.check_in' => ['nullable', 'date_format:H:i'],
            'records.*.check_out' => ['nullable', 'date_format:H:i'],
            'records.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $term = AcademicTerm::findOrFail($data['academic_term_id']);
        $date = Carbon::parse($data['date'])->toDateString();
        if (! self::inTerm($term, $date)) {
            throw ValidationException::withMessages(['date' => __('staff_attendance.errors.outside_term')]);
        }
        $ids = array_map(fn ($r) => (int) $r['user_id'], $data['records']);
        $valid = User::role($data['kind'])->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if (array_diff($ids, $valid)) {
            throw ValidationException::withMessages(['records' => __('staff_attendance.errors.role_'.$data['kind'])]);
        }
        foreach ($data['records'] as $i => $r) {
            if (! empty($r['check_in']) && ! empty($r['check_out']) && $r['check_out'] < $r['check_in']) {
                throw ValidationException::withMessages(["records.$i.check_out" => __('staff_attendance.errors.times')]);
            }
        }

        $by = $request->user()->id;
        $saved = DB::transaction(function () use ($data, $term, $date, $by) {
            $count = 0;
            foreach ($data['records'] as $r) {
                $row = StaffAttendance::firstOrNew(['user_id' => (int) $r['user_id'], 'attendance_date' => $date, 'role_kind' => $data['kind']]);
                $old = $row->exists ? self::row($row) : [];
                $absent = in_array($r['status'], ['absent', 'excused'], true);
                $row->fill([
                    'academic_term_id' => $term->id,
                    'status' => $r['status'],
                    'check_in' => $absent || empty($r['check_in']) ? null : WeekDays::time($r['check_in']),
                    'check_out' => $absent || empty($r['check_out']) ? null : WeekDays::time($r['check_out']),
                    'notes' => $r['notes'] ?? null,
                    'recorded_by' => $by,
                ]);
                if ($row->isDirty()) {
                    $row->save();
                    $this->audit->record($old ? 'staff_attendance.corrected' : 'staff_attendance.recorded', $row, $old, self::row($row));
                    $count++;
                }
            }

            return $count;
        });

        return response()->json(['message' => __('staff_attendance.saved', ['count' => $saved]), 'saved' => $saved]);
    }

    public function destroy(Request $request, StaffAttendance $staffAttendance): JsonResponse
    {
        abort_unless($request->user()->can('staff_attendance.record'), 403);
        $this->audit->record('staff_attendance.deleted', $staffAttendance, self::row($staffAttendance), []);
        $staffAttendance->delete();

        return response()->json(['message' => __('staff_attendance.deleted')]);
    }

    /**
     * Per staff member of the kind in the term (optionally one month): nights expected (held nights they are on duty
     * or teach, up to today), attended (present or late) and the rate; for teachers also sessions with attendance taken.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $f = $request->validate(['kind' => ['required', Rule::in(StaffAttendance::KINDS)], 'month' => ['nullable', 'date_format:Y-m']]);
        $kind = $f['kind'];
        [$from, $to] = $this->range($term, $f['month'] ?? null);
        $people = $this->expectedDates($term, $kind, $from, $to);
        $rows = $this->rows($term, $kind, $from, $to);
        $ids = array_values(array_unique(array_merge(array_keys($people), $rows->keys()->map(fn ($v) => (int) $v)->all())));
        $users = User::whereIn('id', $ids ?: [0])->orderBy('name')->get(['id', 'name']);

        $data = $users->map(function (User $u) use ($people, $rows, $kind) {
            $expected = $people[$u->id]['dates'] ?? [];
            $mine = $rows->get($u->id, collect());
            $by = fn (array $st) => $mine->filter(fn ($r) => in_array($r->status, $st, true))->count();
            $attendedExpected = $mine->filter(fn ($r) => in_array($r->status, StaffAttendance::ATTENDED, true) && in_array($r->attendance_date->toDateString(), $expected, true))->count();
            $recordedDates = $mine->map(fn ($r) => $r->attendance_date->toDateString())->all();

            return [
                'user' => ['id' => $u->id, 'name' => $u->name],
                'expected' => count($expected),
                'attended' => $by(StaffAttendance::ATTENDED),
                'present' => $by(['present']), 'late' => $by(['late']), 'absent' => $by(['absent']), 'excused' => $by(['excused']),
                'not_recorded' => count(array_diff($expected, $recordedDates)),
                'rate' => count($expected) ? round($attendedExpected / count($expected) * 100, 1) : null,
            ] + ($kind === 'teacher' ? [
                'sessions' => ['taken' => $people[$u->id]['taken'] ?? 0, 'total' => $people[$u->id]['sessions'] ?? 0],
            ] : []);
        })->values();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'kind' => $kind, 'from' => $from, 'to' => $to, 'month' => $f['month'] ?? null,
            'data' => $data,
        ]);
    }

    /** One staff member's nights: expected or recorded dates, newest first. */
    public function detail(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $term = TermScope::single($request);
        $f = $request->validate([
            'kind' => ['required', Rule::in(StaffAttendance::KINDS)], 'month' => ['nullable', 'date_format:Y-m'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $kind = $f['kind'];
        $uid = (int) $f['user_id'];
        [$from, $to] = $this->range($term, $f['month'] ?? null);
        $expected = $this->expectedDates($term, $kind, $from, $to, $uid)[$uid] ?? ['dates' => [], 'by_date' => []];
        $rows = $this->rows($term, $kind, $from, $to, $uid)->get($uid, collect())->keyBy(fn ($r) => $r->attendance_date->toDateString());
        $dates = collect(array_merge($expected['dates'], $rows->keys()->all()))->unique()->sortDesc()->values();
        $user = User::find($uid, ['id', 'name']);

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name],
            'kind' => $kind, 'from' => $from, 'to' => $to,
            'data' => $dates->map(fn ($d) => [
                'date' => $d,
                'expected' => in_array($d, $expected['dates'], true),
                'record' => ($r = $rows->get($d)) ? self::row($r) : null,
            ] + ($kind === 'teacher' ? ['took_attendance' => $expected['by_date'][$d] ?? ['taken' => 0, 'total' => 0]] : []))->values(),
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->can('staff_attendance.view') || $request->user()->can('staff_attendance.record'), 403);
    }

    /** The term's dates (or the month's inside the term) up to today. @return array{0: string, 1: string} */
    private function range(AcademicTerm $term, ?string $month): array
    {
        $today = today()->toDateString();
        if ($month) {
            $m = Carbon::createFromFormat('Y-m-d', $month.'-01');
            $clip = NightStaff::clip($term, $m->toDateString(), min($m->copy()->endOfMonth()->toDateString(), $today));
        } else {
            $start = $term->start_date?->toDateString()
                ?? $this->staff->sessions($term, '1900-01-01', $today)->min('session_date')
                ?? $today;
            $clip = NightStaff::clip($term, substr((string) $start, 0, 10), $today);
        }

        return $clip ?? [$today, $today];
    }

    /**
     * Expected nights per user in the range: supervisors on their duty weekdays among the held nights, teachers on the
     * nights they teach (with their sessions and how many had attendance taken).
     *
     * @return array<int, array{dates: list<string>, sessions?: int, taken?: int, by_date?: array<string, array{taken: int, total: int}>}>
     */
    private function expectedDates(AcademicTerm $term, string $kind, string $from, string $to, ?int $only = null): array
    {
        $out = [];
        if ($kind === 'teacher') {
            foreach ($this->staff->teacherSessions($term, $from, $to) as $uid => $sessions) {
                if ($only && $uid !== $only) {
                    continue;
                }
                $byDate = $sessions->groupBy(fn ($s) => $s->session_date->toDateString())
                    ->map(fn (Collection $g) => ['taken' => $g->whereNotNull('attendance_taken_at')->count(), 'total' => $g->count()])->all();
                $out[$uid] = ['dates' => array_keys($byDate), 'sessions' => $sessions->count(), 'taken' => $sessions->whereNotNull('attendance_taken_at')->count(), 'by_date' => $byDate];
            }

            return $out;
        }
        $held = $this->staff->heldDates($term, $from, $to);
        $duty = NightSupervisor::where('academic_term_id', $term->id)->when($only, fn ($q) => $q->where('user_id', $only))->get(['user_id', 'weekday'])
            ->groupBy('user_id')->map(fn ($g) => $g->map(fn ($n) => $n->weekday->value)->all());
        foreach ($duty as $uid => $days) {
            $out[(int) $uid] = ['dates' => array_values(array_filter($held, fn ($d) => in_array(WeekDays::keyFor(Carbon::parse($d)), $days, true))), 'by_date' => []];
        }

        return $out;
    }

    /** @return Collection<int, Collection<int, StaffAttendance>> rows grouped by user */
    private function rows(AcademicTerm $term, string $kind, string $from, string $to, ?int $only = null): Collection
    {
        return StaffAttendance::where('academic_term_id', $term->id)->where('role_kind', $kind)->whereBetween('attendance_date', [$from, $to])
            ->when($only, fn ($q) => $q->where('user_id', $only))->get()->groupBy('user_id');
    }

    private static function inTerm(AcademicTerm $term, string $date): bool
    {
        return (! $term->start_date || $date >= $term->start_date->toDateString()) && (! $term->end_date || $date <= $term->end_date->toDateString());
    }

    private static function row(StaffAttendance $r): array
    {
        return [
            'id' => $r->id, 'status' => $r->status,
            'check_in' => $r->check_in ? substr((string) $r->check_in, 0, 5) : null,
            'check_out' => $r->check_out ? substr((string) $r->check_out, 0, 5) : null,
            'notes' => $r->notes,
        ];
    }
}
