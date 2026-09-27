<?php

use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

beforeEach(function () {
    $this->maleTeacher = User::factory()->role('teacher')->create(['name' => 'الشيخ جعفر', 'gender' => 'male', 'track' => 'male']);
    Teacher::create(['user_id' => $this->maleTeacher->id, 'gender' => 'male', 'specialization' => 'تجويد', 'is_active' => true]);
    $this->femaleTeacher = User::factory()->role('teacher')->create(['name' => 'الأستاذة زينب', 'gender' => 'female', 'track' => 'female']);
    Teacher::create(['user_id' => $this->femaleTeacher->id, 'gender' => 'female', 'is_active' => true]);

    $tz = config('ahl.display_timezone', 'Asia/Bahrain');
    $this->today = now($tz)->toDateString();
    $this->boysLesson = Lesson::factory()->create([
        'name' => 'حلقة الإمام نافع', 'package_id' => Package::factory()->create(['gender' => 'male'])->id,
        'teacher_id' => $this->maleTeacher->id, 'days' => ['sat', 'mon'], 'start_time' => '16:00:00', 'end_time' => '17:30:00', 'status' => 'active',
    ]);
    $this->girlsLesson = Lesson::factory()->create([
        'name' => 'حلقة خديجة', 'package_id' => Package::factory()->girls()->create()->id, 'teacher_id' => $this->femaleTeacher->id, 'status' => 'active',
    ]);
    foreach (Student::factory()->male()->count(2)->create() as $s) {
        LessonStudent::create(['lesson_id' => $this->boysLesson->id, 'student_id' => $s->id, 'joined_at' => today(), 'status' => 'active']);
    }
    $this->boy = LessonStudent::where('lesson_id', $this->boysLesson->id)->first()->student_id;

    // This month: one session held (attendance taken), one cancelled, one still due today.
    $held = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => $this->today, 'attendance_taken_at' => now()]);
    Attendance::create(['lesson_session_id' => $held->id, 'student_id' => $this->boy, 'status' => 'present']);
    // One session per circle per day, so the cancelled one belongs to a second (inactive) circle of the same teacher.
    $old = Lesson::factory()->create(['package_id' => $this->boysLesson->package_id, 'teacher_id' => $this->maleTeacher->id, 'status' => 'ended']);
    LessonSession::factory()->create(['lesson_id' => $old->id, 'session_date' => $this->today, 'status' => 'cancelled']);
    $this->upcoming = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => now($tz)->addDays(2)->toDateString()]);
});

it('keeps the plain picker list and adds a paginated list with this month\'s numbers', function () {
    actingAsRole('super_admin');

    $plain = $this->getJson('/api/teachers')->assertOk()->json();
    expect($plain)->not->toHaveKey('meta')->and(collect($plain['data'])->pluck('id'))->toContain($this->maleTeacher->id);

    $res = $this->getJson('/api/teachers?stats=1&per_page=1')->assertOk()->json();
    expect($res['meta'])->toMatchArray(['total' => 2, 'last_page' => 2, 'per_page' => 1]);

    $row = collect($this->getJson('/api/teachers?stats=1&gender=male')->json('data'))->sole();
    expect($row)->toMatchArray(['id' => $this->maleTeacher->id, 'specialization' => 'تجويد', 'active_circles' => 1, 'active_students' => 2])
        ->and($row['month'])->toMatchArray(['held' => 1, 'cancelled' => 1, 'sessions_due' => 1, 'taken_rate' => 100]);

    expect(collect($this->getJson('/api/teachers?stats=1&search=زينب')->json('data'))->pluck('id')->all())->toBe([$this->femaleTeacher->id]);
});

it('limits a track supervisor to their track in the list and the profile', function () {
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);

    expect(collect($this->getJson('/api/teachers?stats=1')->assertOk()->json('data'))->pluck('id')->all())->toBe([$this->femaleTeacher->id]);
    $this->getJson("/api/teachers/{$this->femaleTeacher->id}")->assertOk();
    $this->getJson("/api/teachers/{$this->maleTeacher->id}")->assertForbidden();
});

it('shows the profile with circles, a Saturday-first timetable, numbers and upcoming sessions', function () {
    actingAsRole('super_admin');
    $d = $this->getJson("/api/teachers/{$this->maleTeacher->id}")->assertOk()->json('data');

    expect($d)->toMatchArray(['name' => 'الشيخ جعفر', 'gender' => 'male', 'active_students' => 2])
        ->and(collect($d['circles'])->firstWhere('id', $this->boysLesson->id))->toMatchArray(['id' => $this->boysLesson->id, 'days' => ['sat', 'mon'], 'start_time' => '16:00', 'students' => 2])
        ->and(collect($d['timetable'])->pluck('day')->all())->toBe(['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'])
        ->and($d['timetable'][0]['items'][0]['lesson_id'])->toBe($this->boysLesson->id)
        ->and($d['timetable'][1]['items'])->toBe([])
        ->and($d['month'])->toMatchArray(['held' => 1, 'cancelled' => 1])
        ->and(collect($d['upcoming'])->pluck('id'))->toContain($this->upcoming->id)
        ->and(collect($d['upcoming'])->pluck('date')->unique()->count())->toBeGreaterThanOrEqual(1)
        ->and($d['can'])->toMatchArray(['edit' => true, 'edit_account' => true]);

    $this->getJson('/api/teachers/'.User::factory()->create()->id)->assertNotFound(); // not a teacher
});

it('lets a teacher open only their own page and refuses guardians', function () {
    $this->actingAs($this->maleTeacher, 'sanctum');
    $this->getJson("/api/teachers/{$this->maleTeacher->id}")->assertOk()->assertJsonPath('data.can.edit', false);
    $this->getJson("/api/teachers/{$this->femaleTeacher->id}")->assertForbidden();
    $this->getJson('/api/teachers?stats=1')->assertForbidden();
    $this->putJson("/api/teachers/{$this->maleTeacher->id}", ['bio' => 'x'])->assertForbidden();

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->actingAs($guardian, 'sanctum');
    $this->getJson("/api/teachers/{$this->maleTeacher->id}")->assertForbidden();
});

it('updates specialization and bio with an audit row, and validates', function () {
    actingAsRole('super_admin');

    $this->putJson("/api/teachers/{$this->maleTeacher->id}", ['specialization' => str_repeat('x', 121)])->assertUnprocessable();
    $this->putJson("/api/teachers/{$this->maleTeacher->id}", ['specialization' => 'قراءات', 'bio' => 'مجاز برواية حفص'])->assertOk();

    expect($this->maleTeacher->teacher()->first()->only(['specialization', 'bio']))->toBe(['specialization' => 'قراءات', 'bio' => 'مجاز برواية حفص']);
    $log = AuditLog::where('action', 'teacher.updated')->sole();
    expect($log->user_id)->not->toBeNull();

    // No change → no second audit row.
    $this->putJson("/api/teachers/{$this->maleTeacher->id}", ['specialization' => 'قراءات', 'bio' => 'مجاز برواية حفص'])->assertOk();
    expect(AuditLog::where('action', 'teacher.updated')->count())->toBe(1);

    // A supervisor (teachers.view only) cannot edit.
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $this->putJson("/api/teachers/{$this->maleTeacher->id}", ['bio' => 'x'])->assertForbidden();
});
