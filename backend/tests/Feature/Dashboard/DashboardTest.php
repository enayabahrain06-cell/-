<?php

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->femaleTeacher = User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female']);
    $this->boysLesson = Lesson::factory()->create(['name' => 'حلقة البنين', 'package_id' => Package::factory()->create(['gender' => 'male'])->id, 'teacher_id' => $this->maleTeacher->id]);
    $this->girlsLesson = Lesson::factory()->create(['name' => 'حلقة البنات', 'package_id' => Package::factory()->girls()->create()->id, 'teacher_id' => $this->femaleTeacher->id]);
    $this->boy = Student::factory()->male()->create();
    $this->girl = Student::factory()->female()->create();
    LessonStudent::create(['lesson_id' => $this->boysLesson->id, 'student_id' => $this->boy->id, 'joined_at' => today(), 'status' => 'active']);
    LessonStudent::create(['lesson_id' => $this->girlsLesson->id, 'student_id' => $this->girl->id, 'joined_at' => today(), 'status' => 'active']);
    $this->boysToday = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => today()->toDateString()]);
    $this->girlsToday = LessonSession::factory()->create(['lesson_id' => $this->girlsLesson->id, 'session_date' => today()->toDateString()]);
    Attendance::create(['lesson_session_id' => $this->boysToday->id, 'student_id' => $this->boy->id, 'status' => 'present']);
    Attendance::create(['lesson_session_id' => $this->girlsToday->id, 'student_id' => $this->girl->id, 'status' => 'absent']);
});

it('builds KPIs, today sessions with hall status, the chart and alerts for the Super Admin', function () {
    LessonLocationOverride::create(['lesson_id' => $this->girlsLesson->id, 'location_id' => $this->girlsLesson->location_id, 'override_date' => today()->toDateString()]);
    Alert::raise(AlertType::LocationConflict, 'تعارض', null, $this->boysLesson, AlertSeverity::Danger);
    Alert::raise(AlertType::RepeatedAbsence, 'غياب متكرر', null, $this->girl);

    actingAsRole('super_admin');
    $d = $this->getJson('/api/dashboard')->assertOk()->json('data');

    expect($d['kpis'])->toMatchArray(['active_students' => 2, 'active_circles' => 2, 'sessions_today' => 2, 'attendance_rate_7d' => 50])
        ->and($d['kpis'])->toHaveKeys(['pending_registrations', 'collected_this_month_fils', 'students_due'])
        ->and($d['attendance_chart'])->toHaveCount(14)
        ->and(end($d['attendance_chart']))->toMatchArray(['present' => 1, 'absent' => 1, 'rate' => 50]);

    $today = collect($d['today'])->keyBy('lesson');
    expect($today['حلقة البنين']['location_status'])->toBe('conflict')
        ->and($today['حلقة البنات']['location_status'])->toBe('changed')
        ->and($today['حلقة البنين'])->toMatchArray(['enrolled' => 1, 'present' => 1]);

    expect($d['alerts']['total'])->toBe(2)->and($d['alerts']['items'][0]['severity'])->toBe('danger');
});

it('scopes the dashboard to the girls track for a female supervisor and to own circles for a teacher', function () {
    Alert::raise(AlertType::LocationConflict, 'تعارض', null, $this->boysLesson, AlertSeverity::Danger);
    Alert::raise(AlertType::RepeatedAbsence, 'غياب متكرر', null, $this->girl);

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $d = $this->getJson('/api/dashboard')->assertOk()->json('data');
    expect($d['scope']['track'])->toBe('female')
        ->and($d['kpis']['active_students'])->toBe(1)
        ->and(collect($d['today'])->pluck('lesson')->all())->toBe(['حلقة البنات'])
        ->and(collect($d['alerts']['items'])->pluck('type')->all())->toBe(['repeated_absence']);

    $this->actingAs($this->maleTeacher, 'sanctum');
    $d = $this->getJson('/api/dashboard')->assertOk()->json('data');
    expect($d['scope']['own_circles_only'])->toBeTrue()
        ->and(collect($d['today'])->pluck('lesson')->all())->toBe(['حلقة البنين'])
        ->and($d['kpis'])->not->toHaveKey('collected_this_month_fils')
        ->and(collect($d['alerts']['items'])->pluck('type')->all())->toBe(['location_conflict']);
});

it('resolves an alert and refuses guardians', function () {
    $alert = Alert::raise(AlertType::RepeatedAbsence, 'غياب متكرر', null, $this->girl);

    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $this->postJson("/api/alerts/{$alert->id}/resolve")->assertForbidden(); // other track

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->postJson("/api/alerts/{$alert->id}/resolve")->assertOk();
    expect($alert->fresh()->status->value)->toBe('resolved');

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/dashboard')->assertForbidden();
});
