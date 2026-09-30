<?php

use App\Models\AcademicTerm;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Division;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Support\WeekDays;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => today()->subWeeks(3)->toDateString(), 'end_date' => today()->addMonths(3)->toDateString()]);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1']);
    $this->level2 = Level::create(['name_ar' => 'المستوى الثاني', 'name_en' => 'Level 2']);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id]);
    $this->teacher = User::factory()->role('teacher')->create(['phone' => '36400100']);
    $this->lesson = Lesson::factory()->create(['name' => 'صف النور', 'package_id' => $this->pkg->id, 'level_id' => $this->level->id, 'teacher_id' => $this->teacher->id]);
    $this->enroll = function (Lesson $lesson, int $n = 1) {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $s = Student::factory()->create();
            LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
            $out[] = $s;
        }

        return $out;
    };
    $this->session = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->toDateString()]);
});

// ── حضور التقسيم / مراقبة تسجيل حضور التقسيم ─────────────────────────────

it('records a division\'s attendance through the class session, for the division teacher only within the division', function () {
    [$a, $b, $c] = ($this->enroll)($this->lesson, 3);
    $divTeacher = User::factory()->role('teacher')->create();
    $division = Division::create(['academic_term_id' => $this->term->id, 'lesson_id' => $this->lesson->id, 'name' => 'المجموعة أ', 'teacher_id' => $divTeacher->id]);
    $division->students()->sync([$a->id, $b->id]);

    $this->actingAs($divTeacher, 'sanctum');
    $list = $this->getJson('/api/attendance/divisions?date='.today()->toDateString())->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.divisions.0'))->toMatchArray(['id' => $division->id, 'students' => 2, 'recorded' => 0, 'missing' => 2]);

    $url = "/api/attendance/divisions/{$division->id}/sessions/{$this->session->id}";
    expect($this->getJson($url)->assertOk()->json('data.*.student.id'))->toEqualCanonicalizing([$a->id, $b->id]);

    // Only the division's students can be written.
    $this->putJson($url, ['records' => [['student_id' => $c->id, 'status' => 'present']]])->assertUnprocessable();
    $this->putJson($url, ['records' => [['student_id' => $a->id, 'status' => 'present'], ['student_id' => $b->id, 'status' => 'absent', 'note' => 'مريض']]])
        ->assertOk()->assertJsonPath('saved', 2);
    expect(Attendance::where('lesson_session_id', $this->session->id)->count())->toBe(2)
        ->and(Attendance::where('student_id', $b->id)->value('note'))->toBe('مريض')
        ->and($this->session->fresh()->attendance_taken_at)->not->toBeNull();

    $list = $this->getJson('/api/attendance/divisions?date='.today()->toDateString())->assertOk();
    expect($list->json('data.0.divisions.0.missing'))->toBe(0);
    expect($this->getJson($url)->json('data.0.attendance.status'))->not->toBeNull();

    // The class teacher reaches the division too; another teacher does not; a guardian never.
    $this->actingAs($this->teacher, 'sanctum');
    $this->getJson($url)->assertOk();
    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->getJson($url)->assertForbidden();
    $this->putJson($url, ['records' => [['student_id' => $a->id, 'status' => 'present']]])->assertForbidden();
    expect($this->getJson('/api/attendance/divisions')->json('data'))->toBe([]);
    actingAsRole('guardian');
    $this->getJson('/api/attendance/divisions')->assertForbidden();
});

it('refuses a session of another class and a cancelled session', function () {
    [$a] = ($this->enroll)($this->lesson, 1);
    $division = Division::create(['academic_term_id' => $this->term->id, 'lesson_id' => $this->lesson->id, 'name' => 'أ']);
    $division->students()->sync([$a->id]);
    $other = LessonSession::factory()->create(['session_date' => today()->toDateString()]);
    actingAsRole('supervisor');
    $this->getJson("/api/attendance/divisions/{$division->id}/sessions/{$other->id}")->assertNotFound();

    $this->session->update(['status' => 'cancelled']);
    $this->putJson("/api/attendance/divisions/{$division->id}/sessions/{$this->session->id}", ['records' => [['student_id' => $a->id, 'status' => 'present']]])->assertUnprocessable();
    expect($this->getJson('/api/attendance/divisions')->json('data'))->toBe([]); // cancelled nights are not monitored
});

// ── مراقبة تسجيل الحضور ──────────────────────────────────────────────────

it('lists the sessions whose attendance is not taken, with the night\'s teachers, and reminds a teacher', function () {
    Queue::fake();
    $periodTeacher = User::factory()->role('teacher')->create(['phone' => '36400200']);
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'lesson_id' => $this->lesson->id,
        'weekday' => WeekDays::keyFor(today()), 'start_time' => '16:00:00', 'end_time' => '17:00:00', 'subject_id' => Subject::quranId(), 'teacher_id' => $periodTeacher->id]);
    $taken = LessonSession::factory()->create(['lesson_id' => Lesson::factory()->create(['package_id' => $this->pkg->id, 'level_id' => $this->level2->id])->id,
        'session_date' => today()->toDateString(), 'attendance_taken_at' => now(), 'status' => 'held']);
    LessonSession::factory()->create(['lesson_id' => Lesson::factory()->create(['package_id' => $this->pkg->id])->id, 'session_date' => today()->toDateString(), 'status' => 'cancelled']);
    LessonSession::factory()->create(['session_date' => today()->toDateString()]); // a class of no term: out of scope

    actingAsRole('supervisor');
    $r = $this->getJson('/api/attendance/monitor')->assertOk();
    expect($r->json('counts'))->toBe(['sessions' => 2, 'taken' => 1, 'not_taken' => 1])
        ->and($r->json('data.*.id'))->toBe([$this->session->id])
        ->and($r->json('data.0.teachers.*.id'))->toBe([$periodTeacher->id])
        ->and($r->json('data.0.level.id'))->toBe($this->level->id);
    expect($this->getJson("/api/attendance/monitor?teacher_id={$this->teacher->id}")->json('data'))->toBe([])
        ->and($this->getJson("/api/attendance/monitor?level_id={$this->level2->id}")->json('counts.not_taken'))->toBe(0);
    $this->getJson('/api/attendance/monitor?from='.today()->toDateString().'&to='.today()->addDays(90)->toDateString())->assertUnprocessable();

    // The reminder goes to one of the night's teachers only, as a WhatsApp message logged like any other.
    $this->postJson("/api/attendance/monitor/{$this->session->id}/remind", ['teacher_id' => $this->teacher->id])->assertUnprocessable();
    $this->postJson("/api/attendance/monitor/{$this->session->id}/remind", ['teacher_id' => $periodTeacher->id])->assertOk();
    expect(MessageLog::where('user_id', $periodTeacher->id)->where('lesson_session_id', $this->session->id)->where('recipient_type', 'user')->count())->toBe(1)
        ->and(AuditLog::where('action', 'attendance.reminder_sent')->count())->toBe(1);
    $this->postJson("/api/attendance/monitor/{$taken->id}/remind", ['teacher_id' => $taken->lesson->teacher_id])->assertUnprocessable();

    // A managers' screen: teachers neither list nor remind.
    $this->actingAs($periodTeacher, 'sanctum');
    $this->getJson('/api/attendance/monitor')->assertForbidden();
    $this->postJson("/api/attendance/monitor/{$this->session->id}/remind", ['teacher_id' => $periodTeacher->id])->assertForbidden();
    actingAsRole('guardian');
    $this->getJson('/api/attendance/monitor')->assertForbidden();
});

// ── حضور المشرفين / عرض حضور المشرفين ────────────────────────────────────

it('records the night\'s supervisors from مشرفو الليالي, allows adding one, and summarises per supervisor', function () {
    $onDuty = User::factory()->role('supervisor')->create(['name' => 'أحمد']);
    $extra = User::factory()->role('supervisor')->create(['name' => 'يوسف']);
    $day = WeekDays::keyFor(today());
    \App\Models\NightSupervisor::create(['academic_term_id' => $this->term->id, 'weekday' => $day, 'user_id' => $onDuty->id]);

    $me = actingAsRole('supervisor');
    $r = $this->getJson('/api/staff-attendance/day?kind=supervisor')->assertOk();
    expect($r->json('data.*.user.id'))->toBe([$onDuty->id])->and($r->json('data.0.expected'))->toBeTrue()
        ->and($r->json('candidates.*.id'))->toContain($extra->id)->toContain($me->id);

    $payload = ['academic_term_id' => $this->term->id, 'date' => today()->toDateString(), 'kind' => 'supervisor', 'records' => [
        ['user_id' => $onDuty->id, 'status' => 'late', 'check_in' => '16:20', 'check_out' => '19:00'],
        ['user_id' => $extra->id, 'status' => 'present', 'check_in' => '16:00'],
    ]];
    $this->putJson('/api/staff-attendance/day', $payload)->assertOk()->assertJsonPath('saved', 2);
    $r = $this->getJson('/api/staff-attendance/day?kind=supervisor')->assertOk();
    expect($r->json('data.*.user.id'))->toBe([$onDuty->id, $extra->id])
        ->and($r->json('data.0.record'))->toMatchArray(['status' => 'late', 'check_in' => '16:20', 'check_out' => '19:00'])
        ->and($r->json('data.1.expected'))->toBeFalse();

    // Correcting writes the audit log; rules: role, times, dates of the term.
    $payload['records'] = [['user_id' => $onDuty->id, 'status' => 'present', 'check_in' => '16:00']];
    $this->putJson('/api/staff-attendance/day', $payload)->assertOk();
    expect(AuditLog::where('action', 'staff_attendance.corrected')->count())->toBe(1);
    $this->putJson('/api/staff-attendance/day', ['records' => [['user_id' => $this->teacher->id, 'status' => 'present']]] + $payload)->assertUnprocessable();
    $this->putJson('/api/staff-attendance/day', ['records' => [['user_id' => $onDuty->id, 'status' => 'present', 'check_in' => '18:00', 'check_out' => '17:00']]] + $payload)->assertUnprocessable();
    $this->putJson('/api/staff-attendance/day', ['date' => today()->subMonths(2)->toDateString()] + $payload)->assertUnprocessable();

    // Summary: tonight is a held night (a session exists), so it is expected for the supervisor on duty.
    $sum = $this->getJson('/api/staff-attendance/summary?kind=supervisor')->assertOk();
    $row = collect($sum->json('data'))->firstWhere('user.id', $onDuty->id);
    expect($row)->toMatchArray(['expected' => 1, 'attended' => 1, 'rate' => 100])
        ->and(collect($sum->json('data'))->firstWhere('user.id', $extra->id))->toMatchArray(['expected' => 0, 'attended' => 1, 'rate' => null]);
    $detail = $this->getJson("/api/staff-attendance/detail?kind=supervisor&user_id={$onDuty->id}&month=".today()->format('Y-m'))->assertOk();
    expect($detail->json('data.0'))->toMatchArray(['date' => today()->toDateString(), 'expected' => true])
        ->and($detail->json('data.0.record.status'))->toBe('present');

    $id = $r->json('data.1.record.id');
    $this->deleteJson("/api/staff-attendance/{$id}")->assertOk();

    // Teachers have no access.
    $this->actingAs($this->teacher, 'sanctum');
    $this->getJson('/api/staff-attendance/day?kind=supervisor')->assertForbidden();
    $this->putJson('/api/staff-attendance/day', $payload)->assertForbidden();
    $this->getJson('/api/staff-attendance/summary?kind=teacher')->assertForbidden();
});

// ── عرض حضور المعلمين (with recording) ───────────────────────────────────

it('expects the teachers of the night\'s periods and derives who took attendance', function () {
    $second = User::factory()->role('teacher')->create();
    $day = WeekDays::keyFor(today());
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'lesson_id' => $this->lesson->id,
        'weekday' => $day, 'start_time' => '16:00:00', 'end_time' => '17:00:00', 'subject_id' => Subject::quranId(), 'teacher_id' => $this->teacher->id]);
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'lesson_id' => null,
        'weekday' => $day, 'start_time' => '17:00:00', 'end_time' => '17:45:00', 'subject_id' => Subject::quranId(), 'teacher_id' => $second->id]);
    $this->session->update(['attendance_taken_at' => now(), 'status' => 'held']);

    actingAsRole('supervisor');
    $r = $this->getJson('/api/staff-attendance/day?kind=teacher')->assertOk();
    $rows = collect($r->json('data'))->keyBy('user.id');
    expect($rows->keys()->all())->toEqualCanonicalizing([$this->teacher->id, $second->id])
        ->and($rows[$this->teacher->id]['took_attendance'])->toBe(['taken' => 1, 'total' => 1])
        ->and($rows[$second->id]['sessions'][0]['lesson']['id'])->toBe($this->lesson->id);

    $this->putJson('/api/staff-attendance/day', ['academic_term_id' => $this->term->id, 'date' => today()->toDateString(), 'kind' => 'teacher',
        'records' => [['user_id' => $this->teacher->id, 'status' => 'present'], ['user_id' => $second->id, 'status' => 'absent', 'check_in' => '16:00', 'notes' => 'بعذر']]])->assertOk();
    $r = $this->getJson('/api/staff-attendance/day?kind=teacher')->assertOk();
    expect(collect($r->json('data'))->firstWhere('user.id', $second->id)['record'])->toMatchArray(['status' => 'absent', 'check_in' => null]);

    // Supervisors are not accepted as teachers.
    $this->putJson('/api/staff-attendance/day', ['academic_term_id' => $this->term->id, 'date' => today()->toDateString(), 'kind' => 'teacher',
        'records' => [['user_id' => User::factory()->role('supervisor')->create()->id, 'status' => 'present']]])->assertUnprocessable();

    $sum = collect($this->getJson('/api/staff-attendance/summary?kind=teacher')->assertOk()->json('data'))->keyBy('user.id');
    expect($sum[$this->teacher->id])->toMatchArray(['expected' => 1, 'attended' => 1, 'rate' => 100, 'sessions' => ['taken' => 1, 'total' => 1]])
        ->and($sum[$second->id])->toMatchArray(['expected' => 1, 'attended' => 0, 'absent' => 1, 'rate' => 0]);
    $detail = $this->getJson("/api/staff-attendance/detail?kind=teacher&user_id={$this->teacher->id}")->assertOk();
    expect($detail->json('data.0.took_attendance'))->toBe(['taken' => 1, 'total' => 1]);
});

it('creates and rolls back the staff_attendances table', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('staff_attendances'))->toBeTrue();
    $guard = 0;
    while (\Illuminate\Support\Facades\Schema::hasTable('staff_attendances') && $guard++ < 50) {
        \Illuminate\Support\Facades\Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    }
    expect(\Illuminate\Support\Facades\Schema::hasTable('staff_attendances'))->toBeFalse();
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    expect(\Illuminate\Support\Facades\Schema::hasTable('staff_attendances'))->toBeTrue();
});
