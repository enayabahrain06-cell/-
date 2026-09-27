<?php

use App\Models\Alert;
use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->teacher = User::factory()->role('teacher')->create();
    $this->lesson = Lesson::factory()->create(['teacher_id' => $this->teacher->id, 'name' => 'حلقة النور']);
    $this->session = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->toDateString()]);
    $this->students = Student::factory()->count(3)->sequence(
        ['student_phone' => '+97336200001', 'guardian_phone' => '+97336200002'],
        ['student_phone' => null, 'guardian_phone' => '+97336200003'],
        ['student_phone' => '+97336200004', 'guardian_phone' => '+97336200004'],
    )->create();
    foreach ($this->students as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => today(), 'status' => 'active', 'current_memorization' => 'سورة النبأ']);
    }
});

it('shows the roster with current assignments', function () {
    $this->actingAs($this->teacher, 'sanctum');

    $res = $this->getJson("/api/sessions/{$this->session->id}/attendance")->assertOk();
    expect($res->json('roster'))->toHaveCount(3)
        ->and($res->json('roster.0.attendance'))->toBeNull()
        ->and($res->json('roster.0.current_memorization'))->toBe('سورة النبأ');
});

it('saves attendance, updates current assignments, marks the session held and queues absence messages', function () {
    $this->actingAs($this->teacher, 'sanctum');
    [$a, $b, $c] = $this->students;

    $res = $this->putJson("/api/sessions/{$this->session->id}/attendance", ['records' => [
        ['student_id' => $a->id, 'status' => 'present', 'memorization_assignment' => 'سورة النازعات', 'revision_assignment' => 'سورة النبأ'],
        ['student_id' => $b->id, 'status' => 'absent'],
        ['student_id' => $c->id, 'status' => 'late', 'note' => 'تأخر 10 دقائق'],
    ]])->assertOk();

    expect($res->json('saved'))->toBe(3)->and($res->json('absent'))->toBe(1);

    $session = $this->session->fresh();
    expect($session->status->value)->toBe('held')->and($session->attendance_taken_at)->not->toBeNull()->and($session->taken_by)->toBe($this->teacher->id);

    expect(LessonStudent::where('student_id', $a->id)->first()->current_memorization)->toBe('سورة النازعات')
        ->and(LessonStudent::where('student_id', $b->id)->first()->current_memorization)->toBe('سورة النبأ'); // unchanged

    // Absence message: student B has no own phone → one message to the guardian, with the missed (current) assignment.
    $logs = MessageLog::where('type', 'absence_notice')->get();
    expect($logs)->toHaveCount(1)
        ->and($logs->first()->recipient_phone)->toBe('+97336200003')
        ->and($logs->first()->body)->toContain('حلقة النور')->toContain('سورة النبأ');
    expect(Attendance::where('student_id', $b->id)->first()->absence_notified_at)->not->toBeNull();

    // Re-saving is an upsert and does not resend.
    $this->putJson("/api/sessions/{$this->session->id}/attendance", ['records' => [['student_id' => $b->id, 'status' => 'absent']]])->assertOk();
    expect(Attendance::where('lesson_session_id', $this->session->id)->count())->toBe(3)
        ->and(MessageLog::where('type', 'absence_notice')->count())->toBe(1);
});

it('deduplicates when student and guardian share a phone', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $c = $this->students[2];

    $this->putJson("/api/sessions/{$this->session->id}/attendance", ['records' => [['student_id' => $c->id, 'status' => 'absent']]])->assertOk();

    expect(MessageLog::where('type', 'absence_notice')->count())->toBe(1);
});

it('marks everyone present in one click', function () {
    $this->actingAs($this->teacher, 'sanctum');

    $this->postJson("/api/sessions/{$this->session->id}/attendance/mark-all-present")->assertOk()->assertJsonPath('saved', 3);

    expect(Attendance::where('lesson_session_id', $this->session->id)->where('status', 'present')->count())->toBe(3)
        ->and(MessageLog::where('type', 'absence_notice')->count())->toBe(0);
});

it('raises a repeated-absence alert after 3 absences within 30 days', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $b = $this->students[1];

    foreach ([20, 10] as $daysAgo) {
        $past = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->subDays($daysAgo)->toDateString(), 'status' => 'held']);
        Attendance::create(['lesson_session_id' => $past->id, 'student_id' => $b->id, 'status' => 'absent', 'absence_notified_at' => now()]);
    }
    // An old absence outside the window must not count.
    $old = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->subDays(45)->toDateString(), 'status' => 'held']);
    Attendance::create(['lesson_session_id' => $old->id, 'student_id' => $b->id, 'status' => 'absent', 'absence_notified_at' => now()]);

    expect(Alert::where('type', 'repeated_absence')->count())->toBe(0);

    $res = $this->putJson("/api/sessions/{$this->session->id}/attendance", ['records' => [['student_id' => $b->id, 'status' => 'absent']]])->assertOk();

    expect($res->json('repeated_absence_alerts'))->toHaveCount(1);
    $alert = Alert::where('type', 'repeated_absence')->where('subject_id', $b->id)->first();
    expect($alert)->not->toBeNull()->and($alert->status->value)->toBe('open');

    // Idempotent: another absence does not create a second open alert.
    $this->putJson("/api/sessions/{$this->session->id}/attendance", ['records' => [['student_id' => $b->id, 'status' => 'absent']]])->assertOk();
    expect(Alert::where('type', 'repeated_absence')->where('subject_id', $b->id)->count())->toBe(1);
});

it('forbids a teacher from another teacher\'s session but allows supervisors', function () {
    $other = User::factory()->role('teacher')->create();
    $this->actingAs($other, 'sanctum');

    $this->getJson("/api/sessions/{$this->session->id}/attendance")->assertForbidden();
    $this->putJson("/api/sessions/{$this->session->id}/attendance", ['records' => [['student_id' => $this->students[0]->id, 'status' => 'present']]])->assertForbidden();
    $this->postJson("/api/sessions/{$this->session->id}/attendance/mark-all-present")->assertForbidden();

    actingAsRole('supervisor');
    $this->getJson("/api/sessions/{$this->session->id}/attendance")->assertOk();
});

it('lets a guardian see their children\'s attendance and a student their own', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $this->postJson("/api/sessions/{$this->session->id}/attendance/mark-all-present")->assertOk();

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->students[0]->update(['guardian_user_id' => $guardian->id]);
    $this->students[1]->update(['guardian_user_id' => $guardian->id]);

    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/me/attendance')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'present');

    $studentUser = User::factory()->withoutPassword()->create();
    $studentUser->assignRole('student');
    $this->students[2]->update(['user_id' => $studentUser->id]);

    $this->actingAs($studentUser, 'sanctum');
    $this->getJson('/api/me/attendance')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.session.lesson_name', 'حلقة النور');
});
