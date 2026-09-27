<?php

/*
 * One test per page: a supervisor scoped to one gender track never sees the other track's data.
 * Fixture: a boys circle and a girls circle, each with one student, today's session and attendance.
 */

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->femaleTeacher = User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female']);
    $this->boysPackage = Package::factory()->create(['gender' => 'male']);
    $this->girlsPackage = Package::factory()->girls()->create();
    $this->boysLesson = Lesson::factory()->create(['name' => 'حلقة البنين', 'package_id' => $this->boysPackage->id, 'teacher_id' => $this->maleTeacher->id]);
    $this->girlsLesson = Lesson::factory()->create(['name' => 'حلقة البنات', 'package_id' => $this->girlsPackage->id, 'teacher_id' => $this->femaleTeacher->id]);
    $this->boy = Student::factory()->male()->create();
    $this->girl = Student::factory()->female()->create();
    LessonStudent::create(['lesson_id' => $this->boysLesson->id, 'student_id' => $this->boy->id, 'joined_at' => today(), 'status' => 'active']);
    LessonStudent::create(['lesson_id' => $this->girlsLesson->id, 'student_id' => $this->girl->id, 'joined_at' => today(), 'status' => 'active']);
    $this->boysSession = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => today()->toDateString()]);
    $this->girlsSession = LessonSession::factory()->create(['lesson_id' => $this->girlsLesson->id, 'session_date' => today()->toDateString()]);
    Attendance::create(['lesson_session_id' => $this->boysSession->id, 'student_id' => $this->boy->id, 'status' => 'present']);
    Attendance::create(['lesson_session_id' => $this->girlsSession->id, 'student_id' => $this->girl->id, 'status' => 'absent']);
});

function girlsSupervisor(): User
{
    return actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
}

it('students page: a girls-track supervisor sees only girls', function () {
    girlsSupervisor();

    expect(collect(test()->getJson('/api/students')->assertOk()->json('data'))->pluck('id')->all())->toBe([$this->girl->id]);
    test()->getJson("/api/students/{$this->boy->id}/profile")->assertForbidden();
    test()->getJson("/api/students/{$this->boy->id}/attendance")->assertForbidden();
    test()->getJson("/api/students/{$this->girl->id}/profile")->assertOk();
});

it('attendance page: a girls-track supervisor sees only girls sessions and cannot open or save a boys sheet', function () {
    girlsSupervisor();

    $day = collect(test()->getJson('/api/sessions/today?date='.today()->toDateString())->assertOk()->json('data'));
    expect($day->pluck('id')->all())->toBe([$this->girlsSession->id]);

    test()->getJson("/api/sessions/{$this->boysSession->id}/attendance")->assertForbidden();
    test()->putJson("/api/sessions/{$this->boysSession->id}/attendance", ['records' => [['student_id' => $this->boy->id, 'status' => 'absent']]])->assertForbidden();
    test()->postJson("/api/sessions/{$this->boysSession->id}/attendance/mark-all-present")->assertForbidden();

    test()->getJson("/api/sessions/{$this->girlsSession->id}/attendance")->assertOk()->assertJsonCount(1, 'roster');
});

it('evaluation page: a girls-track supervisor cannot open, save or list boys evaluations', function () {
    \App\Models\Evaluation::create(['student_id' => $this->boy->id, 'lesson_id' => $this->boysLesson->id, 'lesson_session_id' => $this->boysSession->id, 'type' => 'daily', 'evaluated_on' => today()->toDateString(), 'memorization' => 9, 'tajweed' => 9, 'revision' => 9, 'behavior' => 9]);
    \App\Models\Evaluation::create(['student_id' => $this->girl->id, 'lesson_id' => $this->girlsLesson->id, 'lesson_session_id' => $this->girlsSession->id, 'type' => 'daily', 'evaluated_on' => today()->toDateString(), 'memorization' => 8, 'tajweed' => 8, 'revision' => 8, 'behavior' => 8]);
    girlsSupervisor();

    test()->getJson("/api/sessions/{$this->boysSession->id}/evaluations")->assertForbidden();
    test()->postJson("/api/sessions/{$this->boysSession->id}/evaluations", ['entries' => [['student_id' => $this->boy->id, 'memorization' => 1, 'tajweed' => 1, 'revision' => 1, 'behavior' => 1]]])->assertForbidden();
    test()->postJson("/api/lessons/{$this->boysLesson->id}/evaluations/monthly", ['period' => now()->format('Y-m'), 'entries' => [['student_id' => $this->boy->id, 'memorization' => 1, 'tajweed' => 1, 'revision' => 1, 'behavior' => 1]]])->assertForbidden();

    expect(collect(test()->getJson('/api/evaluations')->assertOk()->json('data'))->pluck('student.id')->all())->toBe([$this->girl->id]);
    test()->getJson("/api/sessions/{$this->girlsSession->id}/evaluations")->assertOk()->assertJsonPath('data.0.evaluation.student_id', $this->girl->id);
});

it('lessons page: a girls-track supervisor sees only girls circles, halls of her track or shared, female teachers, and masked shared-hall slots', function () {
    $shared = \App\Models\Location::factory()->create(['gender' => 'shared', 'name' => 'القاعة الكبرى']);
    $boysHall = \App\Models\Location::factory()->create(['gender' => 'male']);
    $this->boysLesson->update(['location_id' => $shared->id]);
    LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => today()->addDay()->toDateString(), 'location_id' => $shared->id]);
    girlsSupervisor();

    expect(collect(test()->getJson('/api/lessons')->assertOk()->json('data'))->pluck('id')->all())->toBe([$this->girlsLesson->id]);
    test()->getJson("/api/lessons/{$this->boysLesson->id}")->assertForbidden();
    test()->putJson("/api/lessons/{$this->boysLesson->id}", ['name' => 'x'])->assertForbidden();
    test()->postJson("/api/lessons/{$this->boysLesson->id}/change-location", ['mode' => 'all_upcoming', 'location_id' => $shared->id])->assertForbidden();

    $teachers = collect(test()->getJson('/api/teachers')->assertOk()->json('data'))->pluck('id')->all();
    expect($teachers)->toContain($this->femaleTeacher->id)->not->toContain($this->maleTeacher->id);

    $halls = collect(test()->getJson('/api/locations?all=1')->assertOk()->json('data'))->pluck('id');
    expect($halls)->toContain($shared->id)->not->toContain($boysHall->id);
    test()->getJson("/api/locations/{$boysHall->id}/calendar")->assertForbidden();

    $items = collect(test()->getJson("/api/locations/{$shared->id}/calendar")->assertOk()->json('items'));
    expect($items)->not->toBeEmpty()
        ->and($items->every(fn ($i) => $i['masked'] === true && $i['kind'] === 'occupied' && $i['lesson_id'] === null))->toBeTrue()
        ->and($items->pluck('title')->unique()->all())->not->toContain('حلقة البنين');
});
