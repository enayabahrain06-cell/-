<?php

use App\Models\Attendance;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\Student;
use App\Services\Lessons\LessonService;
use App\Services\Lessons\SessionGenerator;
use App\Support\WeekDays;

it('creates one session per scheduled weekday inside the date range, idempotently', function () {
    $lesson = Lesson::factory()->create([
        'days' => ['sat', 'mon'],
        'start_date' => today()->toDateString(),
        'end_date' => today()->addDays(13)->toDateString(),
    ]);

    $created = app(SessionGenerator::class)->generateFor($lesson, today()->addWeeks(8));

    $expected = 0;
    for ($d = today(); $d->lte(today()->addDays(13)); $d->addDay()) {
        if (in_array(WeekDays::keyFor($d), ['sat', 'mon'], true)) {
            $expected++;
        }
    }

    expect($created)->toBe($expected)->and($expected)->toBe(4);
    expect(LessonSession::where('lesson_id', $lesson->id)->count())->toBe($expected);
    expect(LessonSession::where('lesson_id', $lesson->id)->get()->every(fn ($s) => in_array(WeekDays::keyFor($s->session_date), ['sat', 'mon'], true) && $s->location_id === $lesson->location_id))->toBeTrue();

    expect(app(SessionGenerator::class)->generateFor($lesson, today()->addWeeks(8)))->toBe(0);
});

it('runs through the artisan command for all active lessons', function () {
    Lesson::factory()->create(['days' => ['sun'], 'start_date' => today()->toDateString(), 'end_date' => null]);
    Lesson::factory()->create(['days' => ['sun'], 'start_date' => today()->toDateString(), 'end_date' => null, 'status' => 'ended']);

    $this->artisan('lesson-sessions:generate', ['--weeks' => 2])->assertSuccessful();

    $sundays = 0;
    for ($d = today(); $d->lte(today()->addWeeks(2)); $d->addDay()) {
        $sundays += $d->dayOfWeek === 0 ? 1 : 0;
    }

    expect(LessonSession::count())->toBe($sundays)->and($sundays)->toBeGreaterThanOrEqual(2);
});

it('regenerates only future sessions without attendance when the schedule changes', function () {
    $lesson = Lesson::factory()->create(['days' => ['sat', 'mon'], 'start_date' => today()->toDateString(), 'end_date' => today()->addDays(20)->toDateString()]);
    app(SessionGenerator::class)->generateFor($lesson, today()->addWeeks(8));

    $withAttendance = LessonSession::where('lesson_id', $lesson->id)->orderBy('session_date')->skip(1)->first();
    $student = Student::factory()->create();
    Attendance::create(['lesson_session_id' => $withAttendance->id, 'student_id' => $student->id, 'status' => 'present']);
    $withAttendance->update(['attendance_taken_at' => now()]);

    // Past session: must never be touched.
    $past = LessonSession::create(['lesson_id' => $lesson->id, 'session_date' => today()->subDays(3)->toDateString(), 'start_time' => '16:00:00', 'end_time' => '17:30:00', 'status' => 'held']);

    app(LessonService::class)->update($lesson, ['days' => ['wed'], 'start_time' => '18:00', 'end_time' => '19:00']);

    $sessions = LessonSession::where('lesson_id', $lesson->id)->get();
    expect($sessions->firstWhere('id', $withAttendance->id))->not->toBeNull()
        ->and($sessions->firstWhere('id', $withAttendance->id)->start_time)->toBe('16:00:00')
        ->and($sessions->firstWhere('id', $past->id))->not->toBeNull();

    $future = $sessions->filter(fn ($s) => $s->id !== $withAttendance->id && $s->id !== $past->id);
    expect($future->count())->toBeGreaterThan(0)
        ->and($future->every(fn ($s) => WeekDays::keyFor($s->session_date) === 'wed' && $s->start_time === '18:00:00'))->toBeTrue();
});
