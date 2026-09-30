<?php

use App\Models\AcademicTerm;
use App\Models\Attendance;
use App\Models\AttendanceConfirmation;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\Level;
use App\Models\Location;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use App\Services\Lessons\ClassSchedule;
use App\Services\Lessons\LocationConflictDetector;
use App\Services\Lessons\SessionSync;
use App\Support\WeekDays;
use Illuminate\Support\Facades\Artisan;

/** Next date (after today) falling on a weekday key. */
function nextDay(string $key): string
{
    $d = today()->addDay();
    while (WeekDays::keyFor($d) !== $key) {
        $d->addDay();
    }

    return $d->toDateString();
}

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل', 'name_en' => 'Term', 'is_current' => true]);
    $this->package = Package::factory()->create(['academic_term_id' => $this->term->id]);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'L1']);
    $this->quran = Subject::quranId();
    $this->fiqh = Subject::create(['name_ar' => 'الفقه', 'name_en' => 'Fiqh', 'code' => 'fiqh'])->id;
    $this->room = Location::factory()->create();
    $this->class = Lesson::factory()->create([
        'package_id' => $this->package->id, 'level_id' => $this->level->id, 'location_id' => $this->room->id,
        'days' => ['sat', 'mon'], 'start_time' => '16:00:00', 'end_time' => '17:00:00',
        'start_date' => today()->toDateString(), 'end_date' => today()->addWeeks(3)->toDateString(),
    ]);
    app(ClassSchedule::class)->setOwnSchedule($this->class, ['sat', 'mon'], '16:00', '17:00');
    $this->sync = app(SessionSync::class);
});

it('creates the class\'s own Quran periods from the migration for classes that had only days/times', function () {
    $old = Lesson::factory()->create(['package_id' => $this->package->id, 'days' => ['tue'], 'start_time' => '18:00:00', 'end_time' => '19:00:00']);
    TimetableSlot::where('lesson_id', $this->class->id)->delete();

    // Back to before the timetable-as-schedule migration (one step at a time, so later migrations don't matter).
    for ($i = 0; $i < 30 && \Illuminate\Support\Facades\Schema::hasColumn('timetable_slots', 'source'); $i++) {
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    }
    Artisan::call('migrate', ['--force' => true]);

    $slot = TimetableSlot::where('lesson_id', $old->id)->sole();
    expect($slot->weekday->value)->toBe('tue')->and($slot->source)->toBe('class')->and($slot->subject_id)->toBe($this->quran)
        ->and(TimetableSlot::where('lesson_id', $this->class->id)->count())->toBe(2);
});

it('builds one session per class per night from its own and its level\'s periods', function () {
    // A whole-level fiqh period after Quran on Saturday, and a new Wednesday night for the level.
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'weekday' => 'sat', 'start_time' => '17:00:00', 'end_time' => '17:45:00', 'subject_id' => $this->fiqh]);
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'weekday' => 'wed', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'subject_id' => $this->fiqh]);

    $plan = $this->sync->apply($this->class->fresh());
    $sat = LessonSession::where('lesson_id', $this->class->id)->where('session_date', nextDay('sat'))->sole();
    $wed = LessonSession::where('lesson_id', $this->class->id)->where('session_date', nextDay('wed'))->sole();

    expect($sat->start_time)->toBe('16:00:00')->and($sat->end_time)->toBe('17:45:00')
        ->and($wed->start_time)->toBe('18:00:00')->and($wed->location_id)->toBe($this->room->id)
        ->and(count($plan['create']))->toBeGreaterThan(0);

    app(ClassSchedule::class)->syncCopy($this->class->fresh());
    expect($this->class->fresh()->days)->toBe(['sat', 'mon', 'wed']);

    // Running again changes nothing.
    $again = $this->sync->plan($this->class->fresh());
    expect($again['create'])->toBe([])->and($again['update'])->toBe([])->and($again['cancel'])->toBe([])->and($again['delete'])->toBe([]);
});

it('cancels a dropped night that has something attached, removes a bare one, and keeps one with attendance', function () {
    $this->sync->apply($this->class->fresh());
    $mondays = LessonSession::where('lesson_id', $this->class->id)->get()->filter(fn ($s) => WeekDays::keyFor($s->session_date) === 'mon')->values();
    expect($mondays->count())->toBeGreaterThanOrEqual(3);
    [$withConfirmation, $withAttendance, $bare] = [$mondays[0], $mondays[1], $mondays[2]];
    $student = Student::factory()->create();
    AttendanceConfirmation::create(['lesson_session_id' => $withConfirmation->id, 'student_id' => $student->id, 'confirmed_at' => now()]);
    Attendance::create(['lesson_session_id' => $withAttendance->id, 'student_id' => $student->id, 'status' => 'present']);

    // Monday leaves the schedule.
    TimetableSlot::where('lesson_id', $this->class->id)->where('weekday', 'mon')->delete();
    $plan = $this->sync->apply($this->class->fresh());

    expect($withConfirmation->fresh()->status->value)->toBe('cancelled')
        ->and(AttendanceConfirmation::where('lesson_session_id', $withConfirmation->id)->exists())->toBeTrue()
        ->and($withAttendance->fresh()->status->value)->toBe('scheduled')
        ->and(LessonSession::find($bare->id))->toBeNull()
        ->and(collect($plan['kept'])->pluck('session_id'))->toContain($withAttendance->id);
});

it('edits a simple schedule from the class form and refuses once the timetable is detailed', function () {
    actingAsRole('super_admin');
    $this->putJson("/api/lessons/{$this->class->id}", ['days' => ['sun'], 'start_time' => '17:00', 'end_time' => '18:00'])->assertOk();
    expect(TimetableSlot::where('lesson_id', $this->class->id)->pluck('weekday')->map->value->all())->toBe(['sun'])
        ->and(LessonSession::where('lesson_id', $this->class->id)->where('session_date', '>=', today()->toDateString())->get()
            ->every(fn ($s) => WeekDays::keyFor($s->session_date) === 'sun' && $s->start_time === '17:00:00'))->toBeTrue();

    TimetableSlot::create(['academic_term_id' => $this->term->id, 'lesson_id' => $this->class->id, 'level_id' => $this->level->id, 'weekday' => 'sun', 'start_time' => '18:00:00', 'end_time' => '18:45:00', 'subject_id' => $this->fiqh]);
    $this->putJson("/api/lessons/{$this->class->id}", ['days' => ['tue'], 'start_time' => '17:00', 'end_time' => '18:00'])->assertJsonValidationErrors('days');
    expect($this->getJson("/api/lessons/{$this->class->id}")->json('data.schedule.editable'))->toBeFalse();
    // Other fields still save.
    $this->putJson("/api/lessons/{$this->class->id}", ['capacity' => 20])->assertOk();
});

it('previews the sync without writing, and the daily command applies it', function () {
    $this->artisan('sessions:sync --dry-run')->expectsOutputToContain('DRY RUN')->assertSuccessful();
    expect(LessonSession::where('lesson_id', $this->class->id)->count())->toBe(0);

    $this->artisan('lesson-sessions:generate', ['--weeks' => 2])->assertSuccessful();
    expect(LessonSession::where('lesson_id', $this->class->id)->count())->toBeGreaterThan(0);
});

it('checks rooms per period: a class period clashes, a whole-level period never clashes with its own classes', function () {
    $sister = Lesson::factory()->create(['package_id' => $this->package->id, 'level_id' => $this->level->id, 'location_id' => Location::factory()->create()->id,
        'days' => [], 'start_date' => today()->toDateString(), 'end_date' => null]);
    $hall = Location::factory()->create();
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'weekday' => 'tue', 'start_time' => '18:00:00', 'end_time' => '19:00:00', 'subject_id' => $this->fiqh, 'location_id' => $hall->id]);
    $detector = app(LocationConflictDetector::class);

    // Someone else wanting that room on Tuesday 18:30 clashes with the level period (through its classes).
    expect($detector->forRecurring($hall->id, ['tue'], today(), today()->addMonth(), '18:30', '19:30'))->not->toBe([])
        // …but the level's own classes sharing that period do not clash with each other.
        ->and($detector->forLesson($this->class->fresh()))->toBe([])
        ->and($detector->forLesson($sister->fresh()))->toBe([]);

    // The class's own Saturday period is in its room.
    expect($detector->forRecurring($this->room->id, ['sat'], today(), today()->addMonth(), '16:30', '17:30'))->toHaveCount(1);
});

it('lets a subject teacher record attendance for their periods but evaluate only the subject they teach (D3)', function () {
    $fiqhTeacher = User::factory()->role('teacher')->create();
    $quranTeacher = User::factory()->role('teacher')->create();
    $stranger = User::factory()->role('teacher')->create();
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'weekday' => 'sat', 'start_time' => '17:00:00', 'end_time' => '17:45:00', 'subject_id' => $this->fiqh, 'teacher_id' => $fiqhTeacher->id]);
    TimetableSlot::where('lesson_id', $this->class->id)->update(['teacher_id' => $quranTeacher->id]);
    $this->sync->apply($this->class->fresh());
    $session = LessonSession::where('lesson_id', $this->class->id)->where('session_date', nextDay('sat'))->sole();

    $this->actingAs($fiqhTeacher, 'sanctum');
    $this->getJson("/api/sessions/{$session->id}/attendance")->assertOk();
    expect($this->getJson('/api/lessons')->json('data.*.id'))->toBe([$this->class->id]);
    $this->getJson("/api/sessions/{$session->id}/evaluations")->assertForbidden();

    $this->actingAs($quranTeacher, 'sanctum');
    $this->getJson("/api/sessions/{$session->id}/evaluations")->assertOk();

    $this->actingAs($stranger, 'sanctum');
    $this->getJson("/api/sessions/{$session->id}/attendance")->assertForbidden();
    expect($this->getJson('/api/lessons')->json('data'))->toBe([]);
});
