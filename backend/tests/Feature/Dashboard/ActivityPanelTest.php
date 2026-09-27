<?php

use App\Models\AuditLog;
use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\Package;
use App\Models\Payment;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;

/** Record an audit row at a given time. */
function auditAt(string $when, string $action, $subject, array $old, array $new, ?User $by = null): AuditLog
{
    $log = app(AuditLogger::class)->record($action, $subject, $old, $new, $by?->id);
    $log->forceFill(['created_at' => $when])->save();

    return $log;
}

beforeEach(function () {
    $this->admin = User::factory()->create(['name' => 'المشرف العام']);
    $this->maleTeacher = User::factory()->role('teacher')->create(['name' => 'أ. يوسف', 'gender' => 'male', 'track' => 'male']);
    $this->boysPkg = Package::factory()->create(['term' => 'T-boys']);
    $this->girlsPkg = Package::factory()->girls()->create(['term' => 'T-girls']);
    $this->boysLesson = Lesson::factory()->create(['name' => 'حلقة البنين', 'package_id' => $this->boysPkg->id, 'teacher_id' => $this->maleTeacher->id]);
    $this->girlsLesson = Lesson::factory()->create(['name' => 'حلقة البنات', 'package_id' => $this->girlsPkg->id]);
    $this->boys = Student::factory()->male()->count(2)->create();
    foreach ($this->boys as $b) {
        LessonStudent::create(['lesson_id' => $this->boysLesson->id, 'student_id' => $b->id, 'joined_at' => today(), 'status' => 'active']);
    }

    // Oldest → newest: t(1) … t(9); plus one event outside the 30-day window.
    $t = fn (int $h) => now()->subHours(20 - $h)->toDateTimeString();

    $this->boysSession = LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'attendance_taken_at' => $t(1), 'taken_by' => $this->maleTeacher->id]);
    foreach ($this->boys as $i => $b) {
        $this->boysSession->attendances()->create(['student_id' => $b->id, 'status' => $i ? 'absent' : 'present']);
    }
    $this->girlsSession = LessonSession::factory()->create(['lesson_id' => $this->girlsLesson->id, 'attendance_taken_at' => $t(2)]);
    LessonSession::factory()->create(['lesson_id' => $this->boysLesson->id, 'session_date' => today()->subDays(40)->toDateString(), 'attendance_taken_at' => now()->subDays(40)]);

    // One sheet of two evaluations → one event.
    foreach ($this->boys as $b) {
        Evaluation::create(['student_id' => $b->id, 'lesson_id' => $this->boysLesson->id, 'lesson_session_id' => $this->boysSession->id, 'type' => 'daily',
            'evaluated_on' => today()->toDateString(), 'memorization' => 9, 'tajweed' => 9, 'revision' => 9, 'behavior' => 9,
            'evaluated_by' => $this->maleTeacher->id, 'created_at' => $t(3)]);
    }

    $this->request = RegistrationRequest::factory()->create(['package_id' => $this->girlsPkg->id, 'gender' => 'female', 'full_name' => 'مريم أحمد', 'source' => 'public', 'created_at' => $t(4)]);
    $this->payment = Payment::create(['receipt_no' => 'R-1', 'student_id' => $this->boys[0]->id, 'amount_fils' => 20000, 'method' => 'cash', 'received_by' => $this->admin->id, 'paid_at' => $t(5)]);
    $hall = Location::factory()->create(['name' => 'قاعة ٢']);
    LessonLocationOverride::create(['lesson_id' => $this->boysLesson->id, 'location_id' => $hall->id, 'override_date' => today()->addDay()->toDateString(), 'created_at' => $t(6)]);
    auditAt($t(7), 'package.updated', $this->girlsPkg, ['start_time' => '16:00:00', 'seats' => 30], ['start_time' => '17:00:00', 'seats' => 30], $this->admin);
    auditAt($t(8), 'package.updated', $this->girlsPkg, ['seats' => 30], ['seats' => 40], $this->admin); // not a schedule change
    auditAt($t(9), 'student.circle_moved', $this->boys[1], ['lesson_id' => null], ['lesson_id' => $this->boysLesson->id], $this->admin);
});

it('merges the latest events newest first, one per evaluation sheet, schedule edits only', function () {
    actingAsRole('super_admin');
    $items = collect($this->getJson('/api/dashboard/activity')->assertOk()->json('data'));

    expect($items->pluck('type')->all())->toBe(['schedule', 'schedule', 'schedule', 'payment', 'registration', 'evaluation', 'attendance', 'attendance'])
        ->and($items->pluck('id')->all())->toContain("payment-{$this->payment->id}", "request-{$this->request->id}");

    $eval = $items->firstWhere('type', 'evaluation');
    expect($eval['description'])->toContain('٢')->toContain('حلقة البنين')
        ->and($eval['user'])->toBe('أ. يوسف')
        ->and($eval['link'])->toBe("/evaluation/{$this->boysSession->id}");

    $att = $items->firstWhere('id', "attendance-{$this->boysSession->id}");
    expect($att)->toMatchArray(['user' => 'أ. يوسف', 'link' => "/attendance/{$this->boysSession->id}"])
        ->and($att['description'])->toContain('١')->toContain('٢');
    expect($items->firstWhere('type', 'payment'))->toMatchArray(['amount_fils' => 20000, 'user' => 'المشرف العام'])
        ->and($items->first()['at'])->toContain('+03:00');
});

it('scopes the feed by track, teacher circles, permissions and term, and paginates the full log', function () {
    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    expect(collect($this->getJson('/api/dashboard/activity')->json('data'))->pluck('type')->all())
        ->toBe(['schedule', 'registration', 'attendance']);

    // A teacher: own circle only, and no payments or registration requests.
    $this->actingAs($this->maleTeacher, 'sanctum');
    expect(collect($this->getJson('/api/dashboard/activity')->json('data'))->pluck('type')->all())
        ->toBe(['schedule', 'schedule', 'evaluation', 'attendance']);

    actingAsRole('super_admin');
    expect(collect($this->getJson('/api/dashboard/activity?term=T-girls')->json('data'))->pluck('type')->all())
        ->toBe(['schedule', 'payment', 'registration', 'attendance']);

    $page = $this->getJson('/api/dashboard/activity/all?per_page=3&page=2')->assertOk()->json();
    expect($page['meta'])->toMatchArray(['current_page' => 2, 'last_page' => 3, 'total' => 8, 'window_days' => 30])
        ->and($page['data'])->toHaveCount(3);

    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/dashboard/activity')->assertForbidden();
    $this->getJson('/api/dashboard/activity/all')->assertForbidden();
});
