<?php

use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\Location;
use App\Models\LocationBooking;
use App\Services\Lessons\LocationConflictDetector;
use App\Services\Lessons\SessionSync;
use Carbon\Carbon;

function nextWeekday(string $key): Carbon
{
    $map = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];
    $d = today()->addDay();
    while ($d->dayOfWeek !== $map[$key]) {
        $d->addDay();
    }

    return $d;
}

beforeEach(function () {
    $this->hall = Location::factory()->create();
    $this->other = Location::factory()->create();
    $this->lessonA = Lesson::factory()->create([
        'location_id' => $this->hall->id,
        'days' => ['sat', 'mon'],
        'start_time' => '16:00:00',
        'end_time' => '17:30:00',
        'start_date' => today()->toDateString(),
        'end_date' => today()->addMonths(2)->toDateString(),
    ]);
    $this->detector = app(LocationConflictDetector::class);
});

it('flags an overlapping recurring slot in the same hall on a shared weekday', function () {
    $conflicts = $this->detector->forRecurring($this->hall->id, ['mon', 'wed'], today(), today()->addMonth(), '17:00', '18:00');

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]['kind'])->toBe('lesson')
        ->and($conflicts[0]['id'])->toBe($this->lessonA->id)
        ->and($conflicts[0]['days'])->toBe(['mon']);
});

it('treats adjacent times as free', function () {
    $conflicts = $this->detector->forRecurring($this->hall->id, ['mon'], today(), today()->addMonth(), '17:30', '18:30');
    expect($conflicts)->toBe([]);

    $before = $this->detector->forRecurring($this->hall->id, ['mon'], today(), today()->addMonth(), '15:00', '16:00');
    expect($before)->toBe([]);
});

it('ignores other halls and non-shared weekdays', function () {
    expect($this->detector->forRecurring($this->other->id, ['mon'], today(), today()->addMonth(), '16:00', '17:30'))->toBe([])
        ->and($this->detector->forRecurring($this->hall->id, ['tue'], today(), today()->addMonth(), '16:00', '17:30'))->toBe([]);
});

it('detects materialised sessions and skips the lesson being edited', function () {
    count(app(SessionSync::class)->apply($this->lessonA, null, today()->addWeeks(3))['create']);
    $monday = nextWeekday('mon');

    $conflicts = $this->detector->forDate($this->hall->id, $monday, '16:30', '17:00');
    expect($conflicts)->toHaveCount(1)->and($conflicts[0]['kind'])->toBe('session');

    expect($this->detector->forDate($this->hall->id, $monday, '16:30', '17:00', ignoreLessonId: $this->lessonA->id))->toBe([]);
});

it('detects a one-day override moving another lesson into the hall', function () {
    $lessonC = Lesson::factory()->create([
        'location_id' => $this->other->id,
        'days' => ['tue'],
        'start_time' => '16:00:00',
        'end_time' => '17:00:00',
        'start_date' => today()->toDateString(),
    ]);
    $tuesday = nextWeekday('tue');
    LessonLocationOverride::create(['lesson_id' => $lessonC->id, 'location_id' => $this->hall->id, 'override_date' => $tuesday->toDateString()]);

    $conflicts = $this->detector->forDate($this->hall->id, $tuesday, '16:30', '17:30');
    expect($conflicts)->toHaveCount(1)->and($conflicts[0]['kind'])->toBe('override');

    // The override moved lesson C away from its own hall that day, so its default hall is free.
    expect($this->detector->forDate($this->other->id, $tuesday, '16:00', '17:00'))->toBe([]);
});

it('detects manual bookings', function () {
    $friday = nextWeekday('fri');
    LocationBooking::create(['location_id' => $this->hall->id, 'title' => 'محاضرة', 'source' => 'event', 'booking_date' => $friday->toDateString(), 'start_time' => '10:00:00', 'end_time' => '12:00:00']);

    expect($this->detector->forDate($this->hall->id, $friday, '11:00', '13:00'))->toHaveCount(1)
        ->and($this->detector->forDate($this->hall->id, $friday, '12:00', '13:00'))->toBe([]);
});

it('raises a dashboard alert when a lesson is saved with a conflict and resolves it once fixed', function () {
    actingAsRole('supervisor');
    $teacher = \App\Models\User::factory()->role('teacher')->create();

    $res = $this->postJson('/api/lessons', [
        'name' => 'حلقة ابن كثير', 'package_id' => $this->lessonA->package_id, 'teacher_id' => $teacher->id,
        'location_id' => $this->hall->id, 'days' => ['mon'], 'start_time' => '17:00', 'end_time' => '18:00',
        'capacity' => 10, 'start_date' => today()->toDateString(),
    ])->assertCreated();

    expect($res->json('conflicts'))->toHaveCount(1);
    $lessonId = $res->json('data.id');
    expect(\App\Models\Alert::where('type', 'location_conflict')->where('subject_id', $lessonId)->where('status', 'open')->exists())->toBeTrue();

    $this->putJson("/api/lessons/{$lessonId}", ['start_time' => '17:30', 'end_time' => '18:30'])->assertOk()->assertJsonPath('conflicts', []);
    expect(\App\Models\Alert::where('type', 'location_conflict')->where('subject_id', $lessonId)->where('status', 'open')->exists())->toBeFalse();
});
