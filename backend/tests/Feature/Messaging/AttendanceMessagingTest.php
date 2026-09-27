<?php

use App\Models\Attendance;
use App\Models\AttendanceConfirmation;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;
use App\Services\Messaging\AttendanceMessenger;
use App\Services\Messaging\InboundMessageHandler;
use App\Services\WhatsApp\Inbound\InboundEvent;
use Illuminate\Support\Carbon;

/*
 * Section 23: automatic attendance messaging. Two reminders, absence and repeated absence, cancellation,
 * quiet hours, one template per recipient per session, and the inbound replies حاضر / عذر / إيقاف / تشغيل.
 */

beforeEach(function () {
    // 12:00 in Bahrain on a Sunday; the session starts at 16:00 local.
    $this->travelTo(Carbon::parse('2026-09-20 09:00:00', 'UTC'));
    $this->lesson = Lesson::factory()->create(['name' => 'حلقة الفجر']);
    $this->kid = Student::factory()->male()->create(['guardian_phone' => '+97336400001', 'student_phone' => '+97336400002', 'birth_date' => '2016-01-01']);
    $this->teen = Student::factory()->male()->create(['guardian_phone' => '+97336400003', 'student_phone' => '+97336400004', 'birth_date' => '2011-01-01']);
    foreach ([$this->kid, $this->teen] as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => '2026-09-01', 'status' => 'active', 'current_memorization' => 'سورة الملك']);
    }
    $this->session = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => '2026-09-20', 'start_time' => '16:00:00', 'end_time' => '17:30:00', 'status' => 'scheduled']);
    $this->messenger = app(AttendanceMessenger::class);
});

function inbound(string $from, string $body): void
{
    app(InboundMessageHandler::class)->handle(InboundEvent::message($from, $body, uniqid('m', true)));
}

it('plans the long and short reminders once, and copies students aged 12 or more', function () {
    $this->messenger->planReminders($this->session);
    $this->messenger->planReminders($this->session);

    $long = MessageLog::where('type', 'attendance_reminder_long')->get();
    expect($long->pluck('recipient_phone')->sort()->values()->all())->toBe(['+97336400001', '+97336400003', '+97336400004'])
        ->and(MessageLog::where('type', 'attendance_reminder_short')->count())->toBe(3)
        ->and($long->first()->status->value)->toBe('scheduled')
        ->and($long->first()->body)->toContain('حلقة الفجر')->toContain('16:00');
});

it('skips the short reminder for a guardian who confirmed with حاضر', function () {
    inbound('+97336400001', 'حاضر');
    expect(AttendanceConfirmation::where('student_id', $this->kid->id)->exists())->toBeTrue();

    $this->messenger->planReminders($this->session);
    expect(MessageLog::where('type', 'attendance_reminder_short')->where('student_id', $this->kid->id)->count())->toBe(0)
        ->and(MessageLog::where('type', 'attendance_reminder_long')->where('student_id', $this->kid->id)->count())->toBe(1);
});

it('records an excuse from عذر, replies once and sends no reminders to that student', function () {
    inbound('+97336400001', 'عذر');

    expect(Attendance::where('student_id', $this->kid->id)->where('lesson_session_id', $this->session->id)->value('status')?->value)->toBe('excused')
        ->and(MessageLog::where('type', 'excuse_received')->where('recipient_phone', '+97336400001')->count())->toBe(1);
    $this->messenger->planReminders($this->session);
    expect(MessageLog::whereIn('type', ['attendance_reminder_long', 'attendance_reminder_short'])->where('student_id', $this->kid->id)->count())->toBe(0);
});

it('stops and resumes notifications for a number', function () {
    inbound('+97336400001', 'إيقاف');
    expect(MessageLog::where('type', 'notifications_stopped')->count())->toBe(1);

    Attendance::create(['lesson_session_id' => $this->session->id, 'student_id' => $this->kid->id, 'status' => 'absent']);
    $this->travelTo(Carbon::parse('2026-09-20 14:00:00', 'UTC'));
    $this->messenger->sendAbsenceNotices($this->session);
    $notice = MessageLog::where('type', 'absence_notice')->first();
    expect($notice?->status->value)->not->toBe('queued'); // blocked by the opt-out

    inbound('+97336400001', 'تشغيل');
    expect(MessageLog::where('type', 'notifications_resumed')->count())->toBe(1);
});

it('sends absence notices to the guardian only, once, with the missed assignment', function () {
    $this->travelTo(Carbon::parse('2026-09-20 14:00:00', 'UTC')); // 17:00 local
    Attendance::create(['lesson_session_id' => $this->session->id, 'student_id' => $this->teen->id, 'status' => 'absent']);
    $this->messenger->sendAbsenceNotices($this->session);
    $this->messenger->sendAbsenceNotices($this->session);

    $logs = MessageLog::where('type', 'absence_notice')->get();
    expect($logs)->toHaveCount(1)
        ->and($logs->first()->recipient_phone)->toBe('+97336400003')
        ->and($logs->first()->body)->toContain('سورة الملك');
});

it('holds messages due in quiet hours until the next allowed time', function () {
    $this->travelTo(Carbon::parse('2026-09-20 20:30:00', 'UTC')); // 23:30 local
    Attendance::create(['lesson_session_id' => $this->session->id, 'student_id' => $this->teen->id, 'status' => 'absent']);
    $this->messenger->sendAbsenceNotices($this->session);

    $log = MessageLog::where('type', 'absence_notice')->first();
    expect($log->status->value)->toBe('scheduled')
        ->and($log->scheduled_for->copy()->setTimezone('Asia/Bahrain')->format('Y-m-d H:i'))->toBe('2026-09-21 07:00');
});

it('sends the repeated-absence message at most once in the throttle window', function () {
    $this->messenger->sendRepeatedAbsence($this->kid, 3, $this->session);
    $this->travelTo(Carbon::parse('2026-09-27 09:00:00', 'UTC'));
    $this->messenger->sendRepeatedAbsence($this->kid, 4);
    expect(MessageLog::where('type', 'repeated_absence')->count())->toBe(1);

    $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
    $this->messenger->sendRepeatedAbsence($this->kid, 5);
    expect(MessageLog::where('type', 'repeated_absence')->count())->toBe(2);
});

it('cancels pending reminders when the session is cancelled and tells the families', function () {
    $this->messenger->planReminders($this->session);
    $this->session->update(['status' => 'cancelled']);
    $this->messenger->sessionCancelled($this->session->fresh());

    expect(MessageLog::whereIn('type', ['attendance_reminder_long', 'attendance_reminder_short'])->where('status', 'scheduled')->count())->toBe(0)
        ->and(MessageLog::where('type', 'session_cancelled')->count())->toBeGreaterThanOrEqual(2);
});

it('answers other messages with an automatic reply at most once a day', function () {
    inbound('+97336400001', 'متى تبدأ الحلقة؟');
    inbound('+97336400001', 'شكرًا');
    expect(MessageLog::where('type', 'auto_reply_generic')->count())->toBe(1);
});

it('lets a manager update the messaging rules and rejects a second reminder earlier than the first', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $this->getJson('/api/messages/rules')->assertOk()->assertJsonPath('data.quiet_start', '22:00');
    $this->putJson('/api/messages/rules', ['quiet_start' => '21:30', 'reminder_2_minutes' => 45])->assertOk()->assertJsonPath('data.quiet_start', '21:30');
    $this->putJson('/api/messages/rules', ['reminder_1_minutes' => 60, 'reminder_2_minutes' => 90])->assertStatus(422);
    $this->putJson('/api/messages/rules', ['quiet_start' => '25:00'])->assertUnprocessable();

    actingAsRole('teacher', ['gender' => 'male', 'track' => 'male']);
    $this->getJson('/api/messages/rules')->assertForbidden();
});

it('turns reminders off for one circle', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $this->putJson("/api/lessons/{$this->lesson->id}/messaging-rule", ['reminders_enabled' => false, 'second_reminder_enabled' => false])->assertOk();
    $this->messenger->planReminders($this->session);
    expect(MessageLog::count())->toBe(0);
});

it('shows the delivery view of a session and sends reminders now', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $this->postJson("/api/sessions/{$this->session->id}/messages/send-now")->assertOk()->assertJsonPath('data.queued', 3);
    $d = $this->getJson("/api/sessions/{$this->session->id}/messages")->assertOk()->json('data');
    expect($d['messages'])->toHaveCount(3)->and($d['messages'][0]['type'])->toBe('attendance_reminder_long');

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->getJson("/api/sessions/{$this->session->id}/messages")->assertForbidden();
    $this->postJson("/api/sessions/{$this->session->id}/messages/send-now")->assertForbidden();
});

it('keeps the inbox per track and resolves messages', function () {
    inbound('+97336400001', 'متى تبدأ الحلقة؟'); // guardian of a boy
    $girl = Student::factory()->female()->create(['guardian_phone' => '+97336400009']);
    inbound('+97336400009', 'سؤال عن الحلقة');

    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $rows = $this->getJson('/api/messages/inbox')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['student']['id'])->toBe($this->kid->id);
    $girlMsg = \App\Models\InboundMessage::where('student_id', $girl->id)->first();
    $this->postJson("/api/messages/inbox/{$girlMsg->id}/resolve")->assertForbidden();
    $this->postJson("/api/messages/inbox/{$rows[0]['id']}/resolve")->assertOk();
    $this->getJson('/api/messages/inbox')->assertOk()->assertJsonCount(0, 'data');
});

it('approves an excuse received after attendance was taken', function () {
    $teacher = \App\Models\User::find($this->lesson->teacher_id);
    Attendance::create(['lesson_session_id' => $this->session->id, 'student_id' => $this->kid->id, 'status' => 'absent']);
    $this->session->update(['attendance_taken_at' => now(), 'status' => 'held']);
    inbound('+97336400001', 'عذر مريض');
    $excuse = \App\Models\AttendanceExcuse::where('student_id', $this->kid->id)->first();
    expect($excuse?->status->value)->toBe('pending');

    $this->actingAs($teacher, 'sanctum');
    $this->getJson('/api/attendance/excuses')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson("/api/attendance/excuses/{$excuse->id}/review", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');
    expect(Attendance::where('student_id', $this->kid->id)->value('status')->value)->toBe('excused');
    $this->postJson("/api/attendance/excuses/{$excuse->id}/review", ['decision' => 'reject'])->assertStatus(422);

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->getJson('/api/attendance/excuses?status=approved')->assertOk()->assertJsonCount(0, 'data');
});
