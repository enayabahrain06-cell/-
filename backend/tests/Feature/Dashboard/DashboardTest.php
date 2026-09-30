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

it('lists every visible alert with type filter and pagination, even when another track has hundreds', function () {
    // One open alert per subject, so each needs its own boys' lesson.
    $boysLessons = Lesson::factory()->count(210)->create(['package_id' => $this->boysLesson->package_id, 'teacher_id' => $this->maleTeacher->id]);
    $boysLessons->each(fn ($l, $i) => Alert::raise(AlertType::LocationConflict, 'تعارض '.($i + 1), null, $l, AlertSeverity::Danger));
    Alert::raise(AlertType::RepeatedAbsence, 'غياب متكرر', null, $this->girl);
    $invoice = app(\App\Services\Wallet\WalletService::class)->createInvoice($this->girl, $this->girlsLesson->package, 30000, now()->addWeek(), 'رسوم');
    Alert::raise(AlertType::InvoiceOverdue, 'فاتورة متأخرة', null, $invoice, AlertSeverity::Danger);

    // The girls' alerts are older than the 210 boys' ones, and must still reach a girls-track supervisor.
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $d = $this->getJson('/api/dashboard')->assertOk()->json('data');
    expect($d['alerts']['total'])->toBe(2)
        ->and($d['alerts']['by_type'])->toBe(['invoice_overdue' => 1, 'repeated_absence' => 1])
        ->and($d['alerts']['items'][0]['subject'])->toMatchArray(['type' => 'Invoice', 'student_id' => $this->girl->id])
        ->and($d)->toHaveKey('generated_at');

    $list = $this->getJson('/api/alerts?type=repeated_absence')->assertOk()->json();
    expect($list['meta']['total'])->toBe(1)->and($list['data'][0]['subject'])->toBe(['type' => 'Student', 'id' => $this->girl->id]);

    actingAsRole('super_admin');
    $page = $this->getJson('/api/alerts?type=location_conflict&per_page=100&page=3')->assertOk()->json();
    expect($page['meta'])->toMatchArray(['current_page' => 3, 'last_page' => 3, 'total' => 210])
        ->and($page['data'])->toHaveCount(10)
        ->and($page['data'][0]['title'])->toBe('تعارض 10');
    $this->getJson('/api/alerts?type=nonsense')->assertUnprocessable();

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/alerts')->assertForbidden();
});

it('splits active students into age bands, scoped like the student KPI', function () {
    $tz = config('ahl.display_timezone', 'Asia/Bahrain');
    $this->boy->update(['birth_date' => now($tz)->subYears(5)->toDateString()]);
    $this->girl->update(['birth_date' => now($tz)->subYears(12)->addDay()->toDateString()]); // turns 12 tomorrow: still 11
    $adult = Student::factory()->female()->create(['birth_date' => now($tz)->subYears(25)->toDateString()]);
    Student::factory()->female()->create(['birth_date' => now($tz)->subYears(8)->toDateString(), 'status' => 'inactive']);

    actingAsRole('super_admin');
    $age = $this->getJson('/api/dashboard')->assertOk()->json('data.age_distribution');
    expect($age['total'])->toBe(3)
        ->and(collect($age['bands'])->pluck('count', 'key')->all())->toBe(['0-6' => 1, '7-9' => 0, '10-12' => 1, '13-15' => 0, '16-18' => 0, '19+' => 1])
        ->and($age['average'])->toEqual(round((5 + 11 + 25) / 3, 1));

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    expect($this->getJson('/api/dashboard')->json('data.age_distribution.total'))->toBe(2);

    $this->actingAs($this->maleTeacher, 'sanctum');
    expect(collect($this->getJson('/api/dashboard')->json('data.age_distribution.bands'))->pluck('count', 'key')->filter()->all())->toBe(['0-6' => 1]);
});

it('summarises a hall conflict with the hall name, session count and weekdays, and lists the sessions', function () {
    $hall = \App\Models\Location::factory()->create(['name' => 'القاعة الكبرى']);
    $monday = today()->addDay();
    while ($monday->dayOfWeek !== 1) {
        $monday->addDay();
    }
    $attrs = ['location_id' => $hall->id, 'days' => ['mon'], 'start_time' => '16:00:00', 'end_time' => '17:30:00',
        'start_date' => $monday->toDateString(), 'end_date' => $monday->copy()->addWeeks(2)->toDateString()];
    $other = Lesson::factory()->create($attrs + ['name' => 'حلقة الإمام نافع', 'package_id' => $this->boysLesson->package_id]);
    $mine = Lesson::factory()->create($attrs + ['name' => 'حلقة ورش', 'package_id' => $this->boysLesson->package_id]);
    app(\App\Services\Lessons\SessionSync::class)->apply($mine);
    Alert::raise(AlertType::LocationConflict, 'تعارض', 'نص قديم طويل', $mine, AlertSeverity::Danger);

    actingAsRole('super_admin');
    $a = collect($this->getJson('/api/alerts?type=location_conflict')->assertOk()->json('data'))->firstWhere('subject.id', $mine->id);

    expect($a['title'])->toContain('القاعة الكبرى')->toContain('حلقة ورش')
        ->and($a['conflict'])->toMatchArray(['location' => 'القاعة الكبرى', 'count' => 3, 'with' => ['حلقة الإمام نافع'], 'weekdays' => [1], 'start_time' => '16:00'])
        ->and($a['conflict']['sessions'])->toHaveCount(3)
        ->and($a['conflict']['from'])->toBe($monday->toDateString())
        ->and($a['body'])->not->toContain('نص قديم');
});

it('adds circles without a teacher, counts absences in a row, and messages the guardian once a day', function () {
    // teacher_id is required, so a circle is "without a teacher" when its teacher's account is deactivated.
    $gone = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male', 'is_active' => false]);
    $orphan = Lesson::factory()->create(['name' => 'حلقة بلا معلم', 'teacher_id' => $gone->id, 'package_id' => $this->boysLesson->package_id, 'status' => 'active']);
    $this->boy->update(['guardian_phone' => '36111222']);
    foreach ([3, 2, 1] as $daysAgo) {
        $s = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => today()->subDays($daysAgo)->toDateString()]);
        Attendance::create(['lesson_session_id' => $s->id, 'student_id' => $this->boy->id, 'status' => 'absent']);
    }
    // Today's session has him present in beforeEach; move it back so the absences are the latest run.
    $this->boysToday->update(['session_date' => today()->subDays(10)->toDateString()]);
    $alert = Alert::raise(AlertType::RepeatedAbsence, 'غياب متكرر', null, $this->boy);

    actingAsRole('super_admin');
    $items = collect($this->getJson('/api/alerts')->assertOk()->json('data'));
    expect($items->firstWhere('type', 'lesson_no_teacher')['subject'])->toBe(['type' => 'Lesson', 'id' => $orphan->id])
        ->and($items->firstWhere('type', 'repeated_absence')['absence'])->toBe(['consecutive' => 3, 'has_phone' => true]);

    $this->postJson("/api/alerts/{$alert->id}/message-guardian")->assertOk();
    expect(\App\Models\MessageLog::where('student_id', $this->boy->id)->where('template_key', 'repeated_absence')->count())->toBe(1);
    $this->postJson("/api/alerts/{$alert->id}/message-guardian")->assertStatus(429);

    $res = $this->postJson("/api/alerts/{$alert->id}/resolve")->assertOk()->json();
    expect($res['resolved_by'])->not->toBeEmpty()->and($alert->fresh()->resolved_by)->not->toBeNull();

    $this->actingAs($this->maleTeacher, 'sanctum');
    expect(collect($this->getJson('/api/alerts')->json('data'))->pluck('type'))->not->toContain('lesson_no_teacher');
});

it('filters alerts by term', function () {
    $this->boysLesson->package->update(['term' => '2026-2027']);
    $this->girlsLesson->package->update(['term' => '2025-2026']);
    Alert::raise(AlertType::LocationConflict, 'تعارض', null, $this->boysLesson, AlertSeverity::Danger);
    Alert::raise(AlertType::RepeatedAbsence, 'غياب متكرر', null, $this->girl);

    actingAsRole('super_admin');
    expect(collect($this->getJson('/api/alerts?term=2026-2027')->assertOk()->json('data'))->pluck('type')->all())->toBe(['location_conflict'])
        ->and($this->getJson('/api/alerts?term=2025-2026')->json('meta.by_type'))->toBe(['repeated_absence' => 1]);
});
