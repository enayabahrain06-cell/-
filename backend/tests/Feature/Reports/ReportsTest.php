<?php

use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentProgress;
use App\Models\User;

beforeEach(function () {
    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->femaleTeacher = User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female']);
    $this->boysLesson = Lesson::factory()->create(['name' => 'حلقة البنين', 'package_id' => Package::factory()->create(['gender' => 'male'])->id, 'teacher_id' => $this->maleTeacher->id]);
    $this->girlsLesson = Lesson::factory()->create(['name' => 'حلقة البنات', 'package_id' => Package::factory()->girls()->create()->id, 'teacher_id' => $this->femaleTeacher->id]);
    $this->boy = Student::factory()->male()->create(['full_name' => 'أحمد']);
    $this->boy2 = Student::factory()->male()->create(['full_name' => 'يوسف']);
    $this->girl = Student::factory()->female()->create(['full_name' => 'مريم']);
    foreach ([[$this->boysLesson, $this->boy], [$this->boysLesson, $this->boy2], [$this->girlsLesson, $this->girl]] as [$l, $s]) {
        LessonStudent::create(['lesson_id' => $l->id, 'student_id' => $s->id, 'joined_at' => today(), 'status' => 'active']);
    }

    $tz = config('ahl.display_timezone', 'Asia/Bahrain');
    $this->today = now($tz)->toDateString();
    $this->q = '?from='.now($tz)->subDays(10)->toDateString().'&to='.$this->today;

    // Boys: 3 sessions (one cancelled, one without attendance), boy absent twice, boy2 present/late. Girls: 1 session, girl absent.
    $days = [now($tz)->subDays(3)->toDateString(), now($tz)->subDays(2)->toDateString(), now($tz)->subDay()->toDateString()];
    $s1 = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => $days[0], 'status' => 'held', 'attendance_taken_at' => now()->subDays(3)]);
    $s2 = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => $days[1], 'status' => 'held', 'attendance_taken_at' => now()->subDays(2)]);
    LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => $days[2], 'status' => 'cancelled']);
    LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => $this->today, 'status' => 'scheduled']);
    $g1 = LessonSession::factory()->create(['lesson_id' => $this->girlsLesson->id, 'session_date' => $days[0], 'status' => 'held', 'attendance_taken_at' => now()]);
    foreach ([[$s1, $this->boy, 'absent'], [$s2, $this->boy, 'absent'], [$s1, $this->boy2, 'present'], [$s2, $this->boy2, 'late'], [$g1, $this->girl, 'absent']] as [$s, $st, $status]) {
        Attendance::create(['lesson_session_id' => $s->id, 'student_id' => $st->id, 'status' => $status]);
    }
    $this->s1 = $s1;
});

it('lists only the reports a user may open, with filter options limited to their track', function () {
    actingAsRole('super_admin');
    $cat = $this->getJson('/api/reports')->assertOk()->json();
    expect(collect($cat['data'])->pluck('key')->all())->toContain('attendance', 'absence', 'evaluation', 'exams', 'teachers', 'messages', 'juz', 'finance', 'tracks')
        ->and(collect($cat['data'])->firstWhere('key', 'finance')['endpoint'])->toBe('reports/finance')
        ->and(collect($cat['data'])->firstWhere('key', 'attendance')['filters'])->toContain('gender')
        ->and(collect($cat['options']['lessons'])->pluck('name')->all())->toContain('حلقة البنين', 'حلقة البنات');

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $cat = $this->getJson('/api/reports')->assertOk()->json();
    expect(collect($cat['data'])->pluck('key')->all())->not->toContain('tracks')
        ->and(collect($cat['data'])->firstWhere('key', 'attendance')['filters'])->not->toContain('gender')
        ->and(collect($cat['options']['lessons'])->pluck('name')->all())->toBe(['حلقة البنات']);

    // Teachers have no reports.view by default.
    $this->actingAs($this->maleTeacher, 'sanctum');
    $this->getJson('/api/reports')->assertForbidden();
    $this->getJson('/api/reports/attendance')->assertForbidden();
});

it('counts attendance per circle, student and day, excluding cancelled and future sessions', function () {
    actingAsRole('super_admin');
    $r = $this->getJson('/api/reports/attendance'.$this->q)->assertOk()->json();

    $circles = collect($r['sections'][0]['rows'])->keyBy(0);
    // Boys: sessions due 3 (the cancelled one excluded, today's counts as due), taken 2; 1 present, 1 late, 2 absent → 50%.
    expect($circles['حلقة البنين'])->toBe(['حلقة البنين', $this->maleTeacher->name, 3, 2, 1, 1, 2, 0, 50])
        ->and($circles['حلقة البنات'][8])->toBe(0);

    $students = collect($r['sections'][1]['rows'])->keyBy(1);
    expect($students['أحمد'][7])->toBe(0)->and($students['يوسف'][7])->toBe(100);
    expect(collect($r['summary'])->pluck(1, 0)->all()[__('reports.attendance.rate')])->toBe('40%');
    expect($r['data']['by_day'])->toHaveCount(2);

    // Girls supervisor: only the girls' circle; a gender filter cannot widen it.
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $r = $this->getJson('/api/reports/attendance'.$this->q.'&gender=male')->assertOk()->json();
    expect(collect($r['sections'][0]['rows'])->pluck(0)->all())->toBe(['حلقة البنات']);
});

it('lists repeated absence over the configured limit and honours min_absences', function () {
    actingAsRole('super_admin');
    $rows = $this->getJson('/api/reports/absence'.$this->q)->assertOk()->json('sections.0.rows');
    expect($rows)->toBe([]); // default limit is 3, the boy has 2

    $rows = $this->getJson('/api/reports/absence'.$this->q.'&min_absences=2')->assertOk()->json('sections.0.rows');
    expect($rows)->toHaveCount(1)->and($rows[0][1])->toBe('أحمد')->and($rows[0][4])->toBe(2);
});

it('averages evaluations and sums new memorization', function () {
    foreach ([[$this->boy, 8, 6], [$this->boy2, 10, 10]] as [$s, $mem, $taj]) {
        Evaluation::create(['student_id' => $s->id, 'lesson_id' => $this->boysLesson->id, 'type' => 'daily', 'evaluated_on' => $this->today,
            'memorization' => $mem, 'tajweed' => $taj, 'revision' => 8, 'behavior' => 10]);
    }
    StudentProgress::create(['student_id' => $this->boy->id, 'lesson_id' => $this->boysLesson->id, 'type' => 'memorized', 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 10, 'ayah_count' => 10, 'recorded_on' => $this->today]);
    StudentProgress::create(['student_id' => $this->boy->id, 'lesson_id' => $this->boysLesson->id, 'type' => 'revised', 'surah_number' => 1, 'from_ayah' => 1, 'to_ayah' => 7, 'ayah_count' => 7, 'recorded_on' => $this->today]);

    actingAsRole('super_admin');
    $r = $this->getJson('/api/reports/evaluation'.$this->q)->assertOk()->json();
    $circle = collect($r['sections'][0]['rows'])->firstWhere(0, 'حلقة البنين');
    // memorization 9, tajweed 8, revision 8, behaviour 10 → overall (8.0 + 9.5) / 2 = 8.75 → 8.8
    expect(array_slice($circle, 2))->toEqual([2, 9.0, 8.0, 8.0, 10.0, 8.8, 10, 1]);
});

it('summarises exam results with average and pass rate', function () {
    $exam = Exam::factory()->create(['lesson_id' => $this->boysLesson->id, 'gender' => 'male', 'exam_date' => $this->today, 'total_marks' => 20, 'pass_mark' => 10, 'status' => 'published']);
    ExamAttempt::create(['exam_id' => $exam->id, 'student_id' => $this->boy->id, 'status' => 'graded', 'total_score' => 16, 'passed' => true]);
    ExamAttempt::create(['exam_id' => $exam->id, 'student_id' => $this->boy2->id, 'status' => 'graded', 'total_score' => 8, 'passed' => false]);
    ExamAttempt::create(['exam_id' => $exam->id, 'student_id' => $this->girl->id, 'status' => 'in_progress']);

    actingAsRole('super_admin');
    $r = $this->getJson('/api/reports/exams'.$this->q)->assertOk()->json();
    expect(array_slice($r['sections'][0]['rows'][0], 3))->toEqual([20, 3, 2, 12.0, 60, 50])
        ->and($r['sections'][1]['rows'])->toHaveCount(2)
        ->and($r['sections'][1]['rows'][0][2])->toBe('أحمد');

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    expect($this->getJson('/api/reports/exams'.$this->q)->json('sections.0.rows'))->toBe([]);
});

it('rates teachers on sessions taken, same-day attendance and student attendance', function () {
    // Girls' attendance was taken three days late.
    actingAsRole('super_admin');
    $rows = collect($this->getJson('/api/reports/teachers'.$this->q)->assertOk()->json('sections.0.rows'))->keyBy(0);
    // Boys teacher: due 3 (cancelled excluded, today's included), taken 2, cancelled 1, 67% taken, 100% same day, attendance 50%.
    expect(array_slice($rows[$this->maleTeacher->name], 1))->toBe([1, 3, 2, 1, 67, 100, 0, null, 50])
        ->and($rows[$this->femaleTeacher->name][6])->toBe(0);
});

it('reports WhatsApp messages by type and status with failures, scoped by the student track', function () {
    foreach ([[$this->boy, 'absence', 'sent'], [$this->boy, 'absence', 'failed'], [$this->girl, 'absence', 'delivered'], [$this->girl, 'payment_receipt', 'failed']] as [$s, $type, $status]) {
        MessageLog::create(['recipient_phone' => '+97336000000', 'student_id' => $s->id, 'type' => $type, 'locale' => 'ar', 'body' => 'x', 'status' => $status, 'attempts' => 1, 'error' => $status === 'failed' ? 'timeout' : null]);
    }

    actingAsRole('super_admin');
    $r = $this->getJson('/api/reports/messages'.$this->q)->assertOk()->json();
    expect(collect($r['summary'])->pluck(1)->all())->toBe([4, 2, 2, '50%'])->and($r['sections'][2]['rows'])->toHaveCount(2);

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    expect(collect($this->getJson('/api/reports/messages'.$this->q)->json('summary'))->pluck(1)->all())->toBe([2, 1, 1, '50%']);
});

it('reshapes the existing progress, difficulty and track reports', function () {
    actingAsRole('super_admin');
    foreach (['juz', 'issues', 'high-issues', 'issue-trend', 'tracks'] as $key) {
        $r = $this->getJson("/api/reports/{$key}")->assertOk()->json();
        expect($r)->toHaveKeys(['title', 'summary', 'sections'])->and($r['sections'][0])->toHaveKeys(['headings', 'rows']);
    }
    expect($this->getJson('/api/reports/juz')->json('summary.0.1'))->toBe(3);

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->getJson('/api/reports/tracks')->assertForbidden();
    expect($this->getJson('/api/reports/juz')->json('summary.0.1'))->toBe(1);
});

it('exports to Excel and PDF with reports.export only', function () {
    actingAsRole('super_admin');
    $this->get('/api/reports/attendance'.$this->q.'&format=xlsx')->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $this->get('/api/reports/teachers'.$this->q.'&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');

    $user = actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $user->roles->first()->revokePermissionTo('reports.export');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $this->getJson('/api/reports/attendance?format=pdf')->assertForbidden();
    $this->getJson('/api/reports/attendance')->assertOk();
    expect(collect($this->getJson('/api/reports')->json('data'))->firstWhere('key', 'attendance')['formats'])->toBe([]);
});

it('validates the period and rejects unknown reports', function () {
    actingAsRole('super_admin');
    $this->getJson('/api/reports/attendance?from=2026-09-10&to=2026-09-01')->assertUnprocessable();
    $this->getJson('/api/reports/nonsense')->assertNotFound();
});
