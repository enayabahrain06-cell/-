<?php

namespace App\Http\Controllers\Api\Messages;

use App\Enums\ExcuseStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceConfirmation;
use App\Models\AttendanceExcuse;
use App\Models\InboundMessage;
use App\Models\Lesson;
use App\Models\LessonMessagingRule;
use App\Models\LessonSession;
use App\Models\MessageLog;
use App\Services\Attendance\AttendanceService;
use App\Services\AuditLogger;
use App\Services\Messaging\AttendanceMessenger;
use App\Services\Messaging\MessagingRules;
use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Section 23 screens: messaging rules, per-circle switches, the per-session delivery view with "send now",
 * the supervisor inbox of inbound replies, and the review of excuses received after attendance was taken.
 * Everything tied to a student or session follows the viewer's gender track.
 *
 * @group Messages
 */
class AttendanceMessagingController extends Controller
{
    public function __construct(private MessagingRules $rules) {}

    public function rules(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('messages.manage'), 403);

        return response()->json(['data' => $this->rules->all()]);
    }

    public function updateRules(Request $request, AuditLogger $audit): JsonResponse
    {
        abort_unless($request->user()->can('messages.manage'), 403);
        $time = ['string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $data = $request->validate([
            'reminder_1_enabled' => ['sometimes', 'boolean'], 'reminder_1_minutes' => ['sometimes', 'integer', 'min:15', 'max:1440'],
            'reminder_2_enabled' => ['sometimes', 'boolean'], 'reminder_2_minutes' => ['sometimes', 'integer', 'min:5', 'max:720'],
            'absence_enabled' => ['sometimes', 'boolean'], 'repeated_absence_enabled' => ['sometimes', 'boolean'],
            'repeated_absence_throttle_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'repeated_absence_count' => ['sometimes', 'integer', 'min:2', 'max:20'], 'repeated_absence_days' => ['sometimes', 'integer', 'min:7', 'max:180'],
            'location_change_enabled' => ['sometimes', 'boolean'], 'session_cancelled_enabled' => ['sometimes', 'boolean'],
            'quiet_start' => ['sometimes', ...$time], 'quiet_end' => ['sometimes', ...$time],
            'quiet_days' => ['sometimes', 'array'], 'quiet_days.*' => [Rule::in(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'])],
            'student_copy_min_age' => ['sometimes', 'integer', 'min:6', 'max:25'],
            'supervisor_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[0-9 ]{6,20}$/'],
            'auto_reply_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'invalid_after_failures' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);
        if (isset($data['reminder_1_minutes'], $data['reminder_2_minutes']) && $data['reminder_2_minutes'] >= $data['reminder_1_minutes']) {
            abort(422, __('messages.rules.order'));
        }
        $before = $this->rules->all();
        $after = $this->rules->update($data);
        $audit->record('messaging.rules_updated', null, array_intersect_key($before, $data), array_intersect_key($after, $data), $request->user()->id);

        return response()->json(['message' => __('api.saved'), 'data' => $after]);
    }

    /** Per-circle switches: reminders on/off and the second (short) reminder on/off. */
    public function lessonRule(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson);
        $data = $request->validate(['reminders_enabled' => ['required', 'boolean'], 'second_reminder_enabled' => ['required', 'boolean']]);
        LessonMessagingRule::updateOrCreate(['lesson_id' => $lesson->id], $data);

        return response()->json(['message' => __('api.saved'), 'data' => $this->rules->forLesson($lesson->id)]);
    }

    /** Everything sent or planned for one session, with confirmations and excuses. */
    public function session(Request $request, LessonSession $session): JsonResponse
    {
        $this->authorize('viewAttendance', $session);
        $session->loadMissing(['lesson:id,name,gender', 'location:id,name']);
        $logs = MessageLog::with('student:id,full_name')->where('lesson_session_id', $session->id)->orderBy('created_at')->get();

        return response()->json(['data' => [
            'session' => [
                'id' => $session->id, 'date' => $session->session_date->toDateString(), 'start_time' => substr((string) $session->start_time, 0, 5),
                'status' => $session->status->value, 'lesson' => ['id' => $session->lesson?->id, 'name' => $session->lesson?->name],
                'reminder_sent_at' => display_tz($session->reminder_sent_at)?->toIso8601String(),
            ],
            'lesson_rule' => $this->rules->forLesson($session->lesson_id),
            'can_send' => $request->user()->can('messages.send'),
            'messages' => $logs->map(fn (MessageLog $l) => [
                'id' => $l->id, 'type' => $l->type?->value, 'status' => $l->status?->value, 'recipient_phone' => $l->recipient_phone,
                'recipient_type' => $l->recipient_type?->value, 'student' => $l->student ? ['id' => $l->student->id, 'full_name' => $l->student->full_name] : null,
                'scheduled_for' => $l->scheduled_for?->toIso8601String(), 'sent_at' => $l->sent_at?->toIso8601String(),
                'delivered_at' => $l->delivered_at?->toIso8601String(), 'read_at' => $l->read_at?->toIso8601String(),
                'error' => $l->getAttribute('error'), 'body' => $l->body,
            ]),
            'confirmations' => AttendanceConfirmation::with('student:id,full_name')->where('lesson_session_id', $session->id)->get()
                ->map(fn ($c) => ['student' => $c->student?->full_name, 'confirmed_at' => $c->confirmed_at?->toIso8601String(), 'via' => $c->via]),
            'excuses' => AttendanceExcuse::with('student:id,full_name')->where('lesson_session_id', $session->id)->get()->map(fn ($e) => $this->excuseRow($e)),
        ]]);
    }

    /** Manual "send now": releases or creates the long reminder for everyone enrolled. */
    public function sendNow(Request $request, LessonSession $session, AttendanceMessenger $messenger): JsonResponse
    {
        $this->authorize('viewAttendance', $session);
        abort_unless($request->user()->can('messages.send'), 403);
        $result = $messenger->sendNow($session);

        return response()->json(['message' => __('messages.send_now_done', ['n' => $result['queued']]), 'data' => $result]);
    }

    /** Supervisor inbox: replies that were not a keyword (status open), newest first. */
    public function inbox(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('messages.view'), 403);
        $status = $request->input('status', 'open');
        $page = InboundMessage::with(['student:id,full_name,gender', 'user:id,name', 'handler:id,name'])
            ->tap(fn (Builder $q) => $this->trackScope($q, $request))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('received_at')->paginate((int) $request->integer('per_page', 30));

        return response()->json([
            'data' => collect($page->items())->map(fn (InboundMessage $m) => [
                'id' => $m->id, 'from_phone' => $m->from_phone, 'body' => $m->body, 'intent' => $m->intent instanceof \BackedEnum ? $m->intent->value : $m->intent,
                'status' => $m->status?->value, 'received_at' => $m->received_at?->toIso8601String(),
                'student' => $m->student ? ['id' => $m->student->id, 'full_name' => $m->student->full_name] : null,
                'sender' => $m->user?->name, 'handled_by' => $m->handler?->name, 'handled_at' => $m->handled_at?->toIso8601String(),
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(),
                'open' => InboundMessage::where('status', 'open')->tap(fn (Builder $q) => $this->trackScope($q, $request))->count()],
        ]);
    }

    public function resolve(Request $request, InboundMessage $inbound): JsonResponse
    {
        abort_unless($request->user()->can('messages.view'), 403);
        abort_unless($this->inTrack($request, $inbound), 403);
        $inbound->update(['status' => 'resolved', 'handled_by' => $request->user()->id, 'handled_at' => now()]);

        return response()->json(['message' => __('api.saved')]);
    }

    /** Excuses waiting for the teacher or supervisor (received after attendance was taken). */
    public function excuses(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('attendance.record'), 403);
        $user = $request->user();
        $rows = AttendanceExcuse::with(['student:id,full_name,gender', 'session.lesson:id,name,teacher_id'])
            ->where('status', $request->input('status', ExcuseStatus::Pending->value))
            ->whereHas('student', fn ($q) => Track::scope($q, $user))
            // Teachers without supervision rights see excuses of their own circles only.
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereHas('session.lesson', fn ($l) => $l->where('teacher_id', $user->id)))
            ->latest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($e) => $this->excuseRow($e))]);
    }

    public function reviewExcuse(Request $request, AttendanceExcuse $excuse, AttendanceService $attendance): JsonResponse
    {
        $excuse->loadMissing('session');
        $this->authorize('recordAttendance', $excuse->session);
        $data = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]]);
        abort_unless($excuse->status === ExcuseStatus::Pending, 422, __('messages.excuse_reviewed'));
        $excuse = $data['decision'] === 'approve' ? $attendance->approveExcuse($excuse, $request->user()) : $attendance->rejectExcuse($excuse, $request->user());

        return response()->json(['message' => __('api.saved'), 'data' => $this->excuseRow($excuse->loadMissing(['student', 'session.lesson']))]);
    }

    // ---------------------------------------------------------------------------------------------

    private function excuseRow(AttendanceExcuse $e): array
    {
        return [
            'id' => $e->id, 'status' => $e->status?->value, 'body' => $e->body, 'phone' => $e->phone, 'source' => $e->source,
            'created_at' => $e->created_at?->toIso8601String(), 'reviewed_at' => $e->reviewed_at?->toIso8601String(),
            'student' => $e->student ? ['id' => $e->student->id, 'full_name' => $e->student->full_name] : null,
            'session' => $e->relationLoaded('session') && $e->session ? ['id' => $e->session->id, 'date' => $e->session->session_date->toDateString(), 'lesson' => $e->session->lesson?->name] : null,
        ];
    }

    /** Messages matched to a student follow the student's track; unmatched senders are shown to viewers of both tracks only. */
    private function trackScope(Builder $q, Request $request): void
    {
        $limit = Track::genderFor($request->user());
        if ($limit) {
            $q->whereHas('student', fn ($s) => $s->whereIn('gender', [$limit->value]));
        }
    }

    private function inTrack(Request $request, InboundMessage $m): bool
    {
        $limit = Track::genderFor($request->user());

        return ! $limit || ($m->student && $m->student->gender?->value === $limit->value);
    }
}
