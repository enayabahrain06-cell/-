<?php

use App\Models\Attendance;
use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Services\Wallet\WalletService;

/** A guardian with two children in one circle, a student login for the elder child, and another family. */
beforeEach(function () {
    $this->teacher = User::factory()->role('teacher')->create(['name' => 'الأستاذ يوسف']);
    $this->lesson = Lesson::factory()->create(['teacher_id' => $this->teacher->id, 'name' => 'حلقة النور']);

    $this->guardian = User::factory()->withoutPassword()->create();
    $this->guardian->assignRole('guardian');
    $this->studentUser = User::factory()->withoutPassword()->create();
    $this->studentUser->assignRole('student');

    $this->elder = Student::factory()->male()->create(['full_name' => 'حسين علي', 'birth_date' => today()->subYears(12), 'guardian_user_id' => $this->guardian->id, 'user_id' => $this->studentUser->id, 'cpr' => '130101234']);
    $this->younger = Student::factory()->female()->create(['full_name' => 'زهراء علي', 'birth_date' => today()->subYears(9), 'guardian_user_id' => $this->guardian->id]);
    $this->stranger = Student::factory()->male()->create(['full_name' => 'غريب']);
    $this->otherGuardian = User::factory()->withoutPassword()->create();
    $this->otherGuardian->assignRole('guardian');
    $this->stranger->update(['guardian_user_id' => $this->otherGuardian->id]);

    foreach ([$this->elder, $this->younger, $this->stranger] as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
    }

    $this->today = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->toDateString()]);
    $this->next = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->addDays(2)->toDateString(), 'start_time' => '17:00:00']);
    Attendance::create(['lesson_session_id' => $this->today->id, 'student_id' => $this->elder->id, 'status' => 'present']);
    Attendance::create(['lesson_session_id' => $this->today->id, 'student_id' => $this->younger->id, 'status' => 'absent']);

    Evaluation::create([
        'student_id' => $this->elder->id, 'lesson_id' => $this->lesson->id, 'lesson_session_id' => $this->today->id, 'type' => 'daily',
        'evaluated_on' => today()->toDateString(), 'evaluated_by' => $this->teacher->id,
        'memorization' => 9, 'tajweed' => 8, 'revision' => 7, 'behavior' => 10, 'note' => 'تلاوة متقنة',
    ]);

    app(WalletService::class)->createInvoice($this->younger, null, 15000, now()->addDays(5), 'رسوم الفصل');
});

it('gives a guardian one card per own child with today, next session, KPIs, note and amount due', function () {
    $this->actingAs($this->guardian, 'sanctum');
    $res = $this->getJson('/api/me/overview')->assertOk();

    $cards = collect($res->json('data.students'));
    expect($res->json('data.role'))->toBe('guardian')
        ->and($cards->pluck('student.id')->all())->toBe([$this->elder->id, $this->younger->id])
        ->and($res->json('data.totals'))->toBe(['outstanding_fils' => 15000, 'due_students' => 1]);

    $elder = $cards->firstWhere('student.id', $this->elder->id);
    expect($elder['circle'])->toMatchArray(['name' => 'حلقة النور', 'teacher' => 'الأستاذ يوسف'])
        ->and($elder['today'])->toMatchArray(['date' => today()->toDateString(), 'attendance' => 'present'])
        ->and($elder['next_session'])->toMatchArray(['date' => today()->addDays(2)->toDateString(), 'start_time' => '17:00'])
        ->and($elder['kpis'])->toMatchArray(['attendance_percent' => 100, 'evaluation_average' => 34.0, 'evaluation_max' => 40])
        ->and($elder['latest_note'])->toMatchArray(['note' => 'تلاوة متقنة', 'teacher' => 'الأستاذ يوسف'])
        ->and($elder['week']['attendance'])->toMatchArray(['present' => 1, 'absent' => 0])
        ->and($elder['wallet']['is_due'])->toBeFalse()
        // The national ID is staff-only.
        ->and($elder['student'])->not->toHaveKey('cpr');

    $younger = $cards->firstWhere('student.id', $this->younger->id);
    expect($younger['today']['attendance'])->toBe('absent')
        ->and($younger['wallet'])->toMatchArray(['outstanding_fils' => 15000, 'is_due' => true])
        ->and($younger['latest_note'])->toBeNull();
});

it('gives a student only their own card, never a sibling', function () {
    $this->actingAs($this->studentUser, 'sanctum');
    $res = $this->getJson('/api/me/overview')->assertOk();

    expect($res->json('data.role'))->toBe('student')
        ->and(collect($res->json('data.students'))->pluck('student.id')->all())->toBe([$this->elder->id])
        ->and($res->json('data.totals.outstanding_fils'))->toBe(0);
});

it('returns an empty portal for staff without a family', function () {
    actingAsRole('supervisor');
    $this->getJson('/api/me/overview')->assertOk()->assertJsonCount(0, 'data.students');
    $this->getJson('/api/me/schedule')->assertOk()->assertJsonCount(0, 'data.sessions');
    $this->getJson('/api/me/messages')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects the portal endpoints without a session', function () {
    $this->getJson('/api/me/overview')->assertUnauthorized();
    $this->getJson('/api/me/schedule')->assertUnauthorized();
    $this->getJson('/api/me/messages')->assertUnauthorized();
});

it('lists the family schedule for the next two weeks with the student\'s attendance', function () {
    LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->addDays(20)->toDateString()]); // beyond the window
    LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->subDay()->toDateString()]);    // past

    $this->actingAs($this->guardian, 'sanctum');
    $rows = collect($this->getJson('/api/me/schedule')->assertOk()->json('data.sessions'));
    expect($rows)->toHaveCount(4) // two sessions x two children
        ->and($rows->pluck('student_id')->unique()->sort()->values()->all())->toBe(collect([$this->elder->id, $this->younger->id])->sort()->values()->all());

    $one = collect($this->getJson("/api/me/schedule?student_id={$this->younger->id}")->assertOk()->json('data.sessions'));
    expect($one)->toHaveCount(2)
        ->and($one->first())->toMatchArray(['date' => today()->toDateString(), 'attendance' => 'absent', 'lesson' => 'حلقة النور', 'teacher' => 'الأستاذ يوسف'])
        ->and($one->last()['attendance'])->toBeNull();

    // Another family's child: forbidden.
    $this->getJson("/api/me/schedule?student_id={$this->stranger->id}")->assertForbidden();
});

it('does not let a student read a sibling\'s schedule', function () {
    $this->actingAs($this->studentUser, 'sanctum');
    $this->getJson("/api/me/schedule?student_id={$this->elder->id}")->assertOk()->assertJsonCount(2, 'data.sessions');
    $this->getJson("/api/me/schedule?student_id={$this->younger->id}")->assertForbidden();
});

it('lists only messages that went out to this login about its own family', function () {
    $log = fn (array $a) => MessageLog::create($a + ['recipient_type' => 'guardian', 'type' => 'absence', 'locale' => 'ar', 'status' => 'sent', 'sent_at' => now()]);
    $mine = $log(['recipient_phone' => $this->guardian->phone, 'student_id' => $this->younger->id, 'body' => 'غياب زهراء']);
    $general = $log(['recipient_phone' => $this->guardian->phone, 'student_id' => null, 'body' => 'إعلان عام', 'sent_at' => now()->subHour()]);
    $log(['recipient_phone' => $this->guardian->phone, 'student_id' => $this->younger->id, 'body' => 'فشل', 'status' => 'failed']);
    $log(['recipient_phone' => $this->guardian->phone, 'student_id' => $this->stranger->id, 'body' => 'طالب آخر']);
    $log(['recipient_phone' => $this->otherGuardian->phone, 'student_id' => $this->stranger->id, 'body' => 'للعائلة الأخرى']);

    $this->actingAs($this->guardian, 'sanctum');
    $res = $this->getJson('/api/me/messages')->assertOk();
    expect(collect($res->json('data'))->pluck('id')->all())->toBe([$mine->id, $general->id])
        ->and($res->json('data.0.student.full_name'))->toBe('زهراء علي');

    $this->actingAs($this->otherGuardian, 'sanctum');
    expect(collect($this->getJson('/api/me/messages')->assertOk()->json('data'))->pluck('body')->all())->toBe(['للعائلة الأخرى']);
});

it('shows the badge catalogue with earned and locked badges for own family members only', function () {
    $this->seed(\Database\Seeders\BadgeSeeder::class);
    $badge = \App\Models\Badge::where('is_active', true)->orderBy('sort_order')->first();
    \App\Models\StudentBadge::create(['student_id' => $this->elder->id, 'badge_id' => $badge->id, 'period' => 'once', 'awarded_at' => now()]);
    $active = \App\Models\Badge::where('is_active', true)->count();

    $this->actingAs($this->studentUser, 'sanctum');
    $res = $this->getJson('/api/me/badges')->assertOk();
    $rows = collect($res->json('data.0.badges'));
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.student_id'))->toBe($this->elder->id)
        ->and($rows)->toHaveCount($active)
        ->and($rows->where('earned', true)->pluck('id')->all())->toBe([$badge->id])
        ->and($rows->firstWhere('id', $badge->id)['times'])->toBe(1);
    $this->getJson("/api/me/badges?student_id={$this->younger->id}")->assertForbidden();

    $this->actingAs($this->guardian, 'sanctum');
    $this->getJson("/api/me/badges?student_id={$this->younger->id}")->assertOk()
        ->assertJsonPath('data.0.student_id', $this->younger->id);
    $this->getJson("/api/me/badges?student_id={$this->stranger->id}")->assertForbidden();
});

it('keeps every portal read and the photo change inside the family', function () {
    $this->actingAs($this->guardian, 'sanctum');
    foreach ([$this->elder, $this->younger] as $own) {
        $this->getJson("/api/students/{$own->id}/profile")->assertOk()->assertJsonPath('meta.read_only', true);
        $this->getJson("/api/students/{$own->id}/attendance")->assertOk();
        $this->getJson("/api/students/{$own->id}/wallet")->assertOk();
        $this->getJson("/api/certificates/recipients/student/{$own->id}")->assertOk()->assertJsonPath('meta.read_only', true);
    }
    $id = $this->stranger->id;
    $this->getJson("/api/students/{$id}/profile")->assertForbidden();
    $this->getJson("/api/students/{$id}/attendance")->assertForbidden();
    $this->getJson("/api/students/{$id}/wallet")->assertForbidden();
    $this->getJson("/api/certificates/recipients/student/{$id}")->assertForbidden();
    $this->getJson("/api/students/{$id}/photo-url")->assertForbidden();
    $this->postJson("/api/students/{$id}/photo", [])->assertForbidden();

    // The student sees themselves, not the sibling, and cannot change photos.
    $this->actingAs($this->studentUser, 'sanctum');
    $this->getJson("/api/students/{$this->elder->id}/profile")->assertOk();
    $this->getJson("/api/students/{$this->younger->id}/profile")->assertForbidden();
    $this->getJson("/api/students/{$this->younger->id}/wallet")->assertForbidden();
    $this->postJson("/api/students/{$this->elder->id}/photo", [])->assertForbidden();
});
