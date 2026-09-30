<?php

namespace App\Http\Controllers\Api\Attendance;

use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Enums\SessionStatus;
use App\Http\Controllers\Controller;
use App\Models\LessonSession;
use App\Models\User;
use App\Services\Attendance\NightStaff;
use App\Services\AuditLogger;
use App\Services\Messaging\MessageService;
use App\Support\PhoneNumber;
use App\Support\TermScope;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Attendance
 * @subgroup Monitor
 *
 * مراقبة تسجيل الحضور: the term's sessions in a date range that are not cancelled and whose attendance has not been
 * taken yet (attendance_taken_at null), with the night's teachers (timetable periods, NightStaff), time and room.
 * A managers' screen: listing and reminding need attendance.view and lessons.manage (within the manager's track). The
 * reminder goes out as a WhatsApp message through MessageService (recipient type user, a message_logs row like every other).
 */
class AttendanceMonitorController extends Controller
{
    private const MAX_DAYS = 62;

    public function __construct(private NightStaff $staff, private MessageService $messages, private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('attendance.view') && $user->can('lessons.manage'), 403);
        $term = TermScope::single($request);
        $f = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'level_id' => ['nullable', 'integer'], 'teacher_id' => ['nullable', 'integer'],
        ]);
        $from = substr((string) ($f['from'] ?? today()->toDateString()), 0, 10);
        $to = substr((string) ($f['to'] ?? $from), 0, 10);
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('staff_attendance.errors.range', ['days' => self::MAX_DAYS])]);
        }

        $sessions = $this->staff->sessions($term, $from, $to)
            ->with(['lesson:id,name,package_id,level_id,teacher_id,location_id,gender,days,start_time,end_time', 'lesson.level:id,name_ar,name_en', 'lesson.location:id,name', 'location:id,name'])
            ->tap(fn ($q) => Track::scopeVia($q, $user, 'lesson'))
            ->orderBy('session_date')->orderBy('start_time')->get();
        $teachersOf = $this->staff->teachersOf($sessions);
        $people = User::whereIn('id', collect($teachersOf)->flatten()->unique()->all() ?: [0])->get(['id', 'name', 'phone'])->keyBy('id');

        $levels = $sessions->pluck('lesson.level')->filter()->unique('id')->sortBy(fn ($l) => $l->name())->values();
        $teacherOptions = collect($teachersOf)->flatten()->unique()->map(fn ($id) => $people->get($id))->filter()->sortBy('name')->values();

        $filtered = $sessions->filter(fn ($s) => (empty($f['level_id']) || (int) $s->lesson?->level_id === (int) $f['level_id'])
            && (empty($f['teacher_id']) || in_array((int) $f['teacher_id'], $teachersOf[$s->id] ?? [], true)));
        $pending = $filtered->filter(fn ($s) => $s->attendance_taken_at === null)->values();
        $now = now();

        return response()->json([
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'from' => $from, 'to' => $to,
            'counts' => ['sessions' => $filtered->count(), 'taken' => $filtered->count() - $pending->count(), 'not_taken' => $pending->count()],
            'levels' => $levels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name()])->values(),
            'teachers' => $teacherOptions->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values(),
            'data' => $pending->map(function (LessonSession $s) use ($teachersOf, $people, $now) {
                $room = $s->location ?? $s->lesson?->location;
                $start = Carbon::parse($s->session_date->toDateString().' '.$s->start_time);

                return [
                    'id' => $s->id,
                    'session_date' => $s->session_date->toDateString(),
                    'start_time' => substr((string) $s->start_time, 0, 5),
                    'end_time' => substr((string) $s->end_time, 0, 5),
                    'upcoming' => $start->greaterThan($now),
                    'lesson' => ['id' => $s->lesson->id, 'name' => $s->lesson->name],
                    'level' => $s->lesson->level ? ['id' => $s->lesson->level->id, 'name' => $s->lesson->level->name()] : null,
                    'location' => $room ? ['id' => $room->id, 'name' => $room->name] : null,
                    'teachers' => collect($teachersOf[$s->id] ?? [])->map(fn ($id) => $people->get($id))->filter()->map(fn (User $u) => [
                        'id' => $u->id, 'name' => $u->name, 'phone' => $u->phone, 'whatsapp' => self::waLink($u->phone),
                    ])->values(),
                ];
            })->values(),
        ]);
    }

    /** Remind one of the night's teachers by WhatsApp that the session's attendance is not taken yet. */
    public function remind(Request $request, LessonSession $session): JsonResponse
    {
        $user = $request->user();
        $session->loadMissing('lesson');
        abort_unless($user->can('attendance.view') && $user->can('lessons.manage') && $session->lesson && Track::allows($user, $session->lesson->gender), 403);
        $data = $request->validate(['teacher_id' => ['required', 'integer', 'exists:users,id']]);
        if ($session->status === SessionStatus::Cancelled || $session->attendance_taken_at !== null) {
            throw ValidationException::withMessages(['session' => __('staff_attendance.errors.already_taken')]);
        }
        if (! in_array((int) $data['teacher_id'], $this->staff->teachersOf(collect([$session]))[$session->id] ?? [], true)) {
            throw ValidationException::withMessages(['teacher_id' => __('staff_attendance.errors.not_session_teacher')]);
        }
        $teacher = User::findOrFail($data['teacher_id']);
        if (! PhoneNumber::normalize($teacher->phone)) {
            throw ValidationException::withMessages(['teacher_id' => __('staff_attendance.errors.no_phone')]);
        }
        $locale = $teacher->locale?->value ?? 'ar';
        $body = __('staff_attendance.remind_body', [
            'name' => $teacher->name, 'lesson' => $session->lesson->name,
            'date' => $session->session_date->toDateString(), 'time' => substr((string) $session->start_time, 0, 5),
        ], $locale);
        $log = $this->messages->send(phone: (string) $teacher->phone, type: MessageType::Custom, vars: ['body' => $body], locale: $locale,
            user: $teacher, recipientType: RecipientType::User, session: $session);
        if (! $log) {
            throw ValidationException::withMessages(['teacher_id' => __('staff_attendance.errors.not_sent')]);
        }
        $this->audit->record('attendance.reminder_sent', $session, [], ['teacher_id' => $teacher->id, 'message_log_id' => $log->id]);

        return response()->json(['message' => __('staff_attendance.reminded', ['name' => $teacher->name]), 'log_id' => $log->id]);
    }

    private static function waLink(?string $phone): ?string
    {
        $p = PhoneNumber::normalize($phone);

        return $p ? 'https://wa.me/'.preg_replace('/\D+/', '', $p) : null;
    }
}
