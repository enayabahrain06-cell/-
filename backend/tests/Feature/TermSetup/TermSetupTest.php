<?php

use App\Models\AcademicTerm;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\LevelSubject;
use App\Models\Location;
use App\Models\Package;
use App\Models\Subject;
use App\Models\User;

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => '2026-09-05', 'end_date' => '2026-12-31']);
    $this->other = AcademicTerm::create(['name_ar' => 'الفصل الثاني', 'name_en' => 'Term 2']);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1']);
    $this->quran = Subject::where('code', 'quran')->first();
    $this->fiqh = Subject::create(['name_ar' => 'الفقه', 'name_en' => 'Fiqh', 'code' => 'fiqh']);
    $this->teacher = User::factory()->role('teacher')->create(['name' => 'المعلم أحمد']);
});

it('needs a term, and lets only term_setup.manage write', function () {
    actingAsRole('teacher');
    $this->getJson('/api/term-setup/level-subjects')->assertOk()->assertJsonPath('term.id', $this->term->id);
    $this->postJson('/api/term-setup/level-subjects', ['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'subject_id' => $this->fiqh->id])->assertForbidden();

    actingAsRole('guardian');
    $this->getJson('/api/term-setup/timetable')->assertForbidden();

    AcademicTerm::query()->update(['is_current' => false]);
    actingAsRole('supervisor');
    $this->getJson('/api/term-setup/level-subjects')->assertUnprocessable();
    $this->getJson("/api/term-setup/level-subjects?term_id={$this->other->id}")->assertOk()->assertJsonPath('term.id', $this->other->id);
});

it('assigns subjects to levels per term, once each, with a teacher-role teacher', function () {
    actingAsRole('supervisor');
    $base = ['academic_term_id' => $this->term->id, 'level_id' => $this->level->id];

    $id = $this->postJson('/api/term-setup/level-subjects', $base + ['subject_id' => $this->quran->id, 'teacher_id' => $this->teacher->id, 'weekly_sessions' => 3])
        ->assertCreated()->assertJsonPath('data.teacher.name', 'المعلم أحمد')->json('data.id');
    $this->postJson('/api/term-setup/level-subjects', $base + ['subject_id' => $this->quran->id])->assertJsonValidationErrors('subject_id');
    $this->postJson('/api/term-setup/level-subjects', $base + ['subject_id' => $this->fiqh->id, 'teacher_id' => User::factory()->create()->id])->assertJsonValidationErrors('teacher_id');
    // The same subject in another term is fine.
    $this->postJson('/api/term-setup/level-subjects', ['academic_term_id' => $this->other->id] + $base + ['subject_id' => $this->quran->id])->assertCreated();

    expect($this->getJson('/api/term-setup/level-subjects')->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/term-setup/level-subjects?term_id={$this->other->id}")->json('data'))->toHaveCount(1);

    $this->putJson("/api/term-setup/level-subjects/{$id}", ['weekly_sessions' => 4])->assertOk()->assertJsonPath('data.weekly_sessions', 4);

    // Levels, subjects and terms in use by the setup cannot be deleted.
    actingAsRole('super_admin');
    $this->deleteJson("/api/levels/{$this->level->id}")->assertJsonValidationErrors('level');
    $this->deleteJson("/api/subjects/{$this->fiqh->id}")->assertOk(); // not used yet
    $this->deleteJson("/api/academic-terms/{$this->other->id}")->assertJsonValidationErrors('term');
});

it('keeps a curriculum per subject and level and plans it by week', function () {
    actingAsRole('supervisor');
    $level2 = Level::create(['name_ar' => 'المستوى الثاني', 'name_en' => 'Level 2']);
    $all = $this->postJson('/api/term-setup/subject-lessons', ['subject_id' => $this->fiqh->id, 'title' => 'الطهارة', 'sort' => 1])->assertCreated()->json('data.id');
    $mine = $this->postJson('/api/term-setup/subject-lessons', ['subject_id' => $this->fiqh->id, 'level_id' => $this->level->id, 'title' => 'الوضوء', 'sort' => 2])->assertCreated()->json('data.id');
    $theirs = $this->postJson('/api/term-setup/subject-lessons', ['subject_id' => $this->fiqh->id, 'level_id' => $level2->id, 'title' => 'التيمم'])->assertCreated()->json('data.id');

    expect($this->getJson("/api/term-setup/subject-lessons?subject_id={$this->fiqh->id}&level_id={$this->level->id}")->json('data.*.title'))->toBe(['الطهارة', 'الوضوء']);

    $ls = LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'subject_id' => $this->fiqh->id]);
    $this->postJson('/api/term-setup/plan', ['level_subject_id' => $ls->id, 'week_no' => 1, 'subject_lesson_id' => $all])->assertCreated()->assertJsonPath('data.display_title', 'الطهارة');
    $this->postJson('/api/term-setup/plan', ['level_subject_id' => $ls->id, 'week_no' => 2, 'subject_lesson_id' => $mine])->assertCreated();
    $this->postJson('/api/term-setup/plan', ['level_subject_id' => $ls->id, 'week_no' => 2, 'subject_lesson_id' => $theirs])->assertJsonValidationErrors('subject_lesson_id');
    $this->postJson('/api/term-setup/plan', ['level_subject_id' => $ls->id, 'week_no' => 3])->assertJsonValidationErrors('title');
    $this->postJson('/api/term-setup/plan', ['level_subject_id' => $ls->id, 'week_no' => 3, 'title' => 'مراجعة'])->assertCreated();

    $view = $this->getJson("/api/term-setup/plan/view?level_id={$this->level->id}")->assertOk()->json();
    expect($view['weeks'])->toHaveCount(17) // 5 Sep – 31 Dec
        ->and($view['weeks'][1]['starts_on'])->toBe('2026-09-12')
        ->and($view['subjects'][0]['items'])->toHaveCount(3);

    // Deleting a curriculum lesson keeps its plan item under the old title.
    $this->deleteJson("/api/term-setup/subject-lessons/{$all}")->assertOk();
    expect($this->getJson("/api/term-setup/plan?level_subject_id={$ls->id}")->json('data.0.display_title'))->toBe('الطهارة');
});

it('assigns supervisors to nights', function () {
    actingAsRole('super_admin');
    $sup = User::factory()->create();
    $sup->assignRole('supervisor');

    $id = $this->postJson('/api/term-setup/night-supervisors', ['academic_term_id' => $this->term->id, 'weekday' => 'mon', 'user_id' => $sup->id])->assertCreated()->json('data.id');
    $this->postJson('/api/term-setup/night-supervisors', ['academic_term_id' => $this->term->id, 'weekday' => 'mon', 'user_id' => $sup->id])->assertJsonValidationErrors('user_id');
    $this->postJson('/api/term-setup/night-supervisors', ['academic_term_id' => $this->term->id, 'weekday' => 'mon', 'user_id' => $this->teacher->id])->assertJsonValidationErrors('user_id');
    $this->postJson('/api/term-setup/night-supervisors', ['academic_term_id' => $this->term->id, 'weekday' => 'sat', 'user_id' => $sup->id])->assertCreated();

    expect($this->getJson('/api/term-setup/night-supervisors')->json('data.*.weekday'))->toBe(['sat', 'mon']);
    $this->deleteJson("/api/term-setup/night-supervisors/{$id}")->assertOk();
});

it('blocks timetable clashes inside a level and warns about a busy teacher or room', function () {
    actingAsRole('super_admin');
    $room = Location::factory()->create(['name' => 'قاعة 1']);
    $level2 = Level::create(['name_ar' => 'المستوى الثاني', 'name_en' => 'Level 2']);
    LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'subject_id' => $this->quran->id, 'teacher_id' => $this->teacher->id]);
    $slot = ['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'weekday' => 'sun', 'start_time' => '16:00', 'end_time' => '17:00', 'subject_id' => $this->quran->id, 'location_id' => $room->id];

    $first = $this->postJson('/api/term-setup/timetable', $slot)->assertCreated()->assertJsonPath('warnings', [])->json('data');
    expect($first['teacher']['id'])->toBe($this->teacher->id); // default from the level subject

    $this->postJson('/api/term-setup/timetable', ['start_time' => '16:30', 'end_time' => '17:30', 'subject_id' => $this->fiqh->id] + $slot)->assertJsonValidationErrors('start_time');
    // Back to back is not a clash.
    $this->postJson('/api/term-setup/timetable', ['start_time' => '17:00', 'end_time' => '18:00', 'subject_id' => $this->fiqh->id, 'location_id' => null] + $slot)->assertCreated();

    // A room is taken by classes that meet in it: give level 1 a class, then level 2 at the same time warns twice.
    Lesson::factory()->create(['level_id' => $this->level->id, 'location_id' => null, 'start_date' => today()->toDateString(), 'end_date' => null,
        'package_id' => Package::factory()->create(['academic_term_id' => $this->term->id])->id]);
    $warn = $this->postJson('/api/term-setup/timetable', ['level_id' => $level2->id, 'teacher_id' => $this->teacher->id] + $slot)->assertCreated()->json('warnings');
    expect($warn)->toHaveCount(2);

    // A circle's period must be one of the level's circles.
    $circle = Lesson::factory()->create(['package_id' => Package::factory()->create(['academic_term_id' => $this->term->id])->id]);
    $this->postJson('/api/term-setup/timetable', ['lesson_id' => $circle->id, 'weekday' => 'tue'] + $slot)->assertJsonValidationErrors('lesson_id');
    $circle->update(['level_id' => $this->level->id]);
    $this->postJson('/api/term-setup/timetable', ['lesson_id' => $circle->id, 'weekday' => 'tue'] + $slot)->assertCreated();
    $this->postJson('/api/term-setup/timetable', ['weekday' => 'tue'] + $slot)->assertJsonValidationErrors('start_time'); // level-wide clashes with the circle's

    expect($this->getJson("/api/term-setup/timetable?level_id={$this->level->id}")->json('data.*.weekday'))->toBe(['sun', 'sun', 'tue'])
        ->and($this->getJson('/api/term-setup/options')->json('data.circles.*.id'))->toContain($circle->id);

    $this->putJson("/api/term-setup/timetable/{$first['id']}", ['end_time' => '16:45'])->assertOk()->assertJsonPath('data.end_time', '16:45');
});

it('copies a term setup into another term without duplicating', function () {
    actingAsRole('super_admin');
    $sup = User::factory()->create();
    $sup->assignRole('supervisor');
    $ls = LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'subject_id' => $this->quran->id]);
    $ls->planItems()->create(['week_no' => 1, 'title' => 'الفاتحة']);
    \App\Models\NightSupervisor::create(['academic_term_id' => $this->term->id, 'weekday' => 'mon', 'user_id' => $sup->id]);
    \App\Models\TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'weekday' => 'mon', 'start_time' => '16:00:00', 'end_time' => '17:00:00', 'subject_id' => $this->quran->id]);
    \App\Models\LevelRoom::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'location_id' => Location::factory()->create()->id]);
    $body = ['from_term_id' => $this->term->id, 'to_term_id' => $this->other->id, 'parts' => ['level_rooms', 'level_subjects', 'night_supervisors', 'timetable']];
    $this->postJson('/api/term-setup/copy', $body)->assertOk()->assertJsonPath('data', ['rooms' => 1, 'level_subjects' => 1, 'plan_items' => 1, 'supervisors' => 1, 'slots' => 1]);
    $this->postJson('/api/term-setup/copy', $body)->assertOk()->assertJsonPath('data', ['rooms' => 0, 'level_subjects' => 0, 'plan_items' => 0, 'supervisors' => 0, 'slots' => 0]);
    $this->postJson('/api/term-setup/copy', ['to_term_id' => $this->term->id] + $body)->assertJsonValidationErrors('from_term_id');

    actingAsRole('teacher');
    $this->postJson('/api/term-setup/copy', $body)->assertForbidden();
});

it('shows each level\'s rooms from assignments, circle halls and the timetable, and assigns rooms', function () {
    actingAsRole('supervisor');
    $a = Location::factory()->create(['name' => 'قاعة أ']);
    $b = Location::factory()->create(['name' => 'قاعة ب']);
    $c = Location::factory()->create(['name' => 'قاعة ج']);
    Lesson::factory()->create(['name' => 'حلقة 1', 'level_id' => $this->level->id, 'location_id' => $b->id,
        'package_id' => Package::factory()->create(['academic_term_id' => $this->term->id])->id]);
    // A circle of the level in another term does not count here.
    Lesson::factory()->create(['level_id' => $this->level->id, 'location_id' => $c->id,
        'package_id' => Package::factory()->create(['academic_term_id' => $this->other->id])->id]);
    \App\Models\TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'weekday' => 'sat', 'start_time' => '16:00:00', 'end_time' => '17:00:00', 'subject_id' => $this->quran->id, 'location_id' => $b->id]);

    $id = $this->postJson('/api/term-setup/level-rooms', ['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'location_id' => $a->id])->assertCreated()->json('data.id');
    $this->postJson('/api/term-setup/level-rooms', ['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'location_id' => $a->id])->assertJsonValidationErrors('location_id');

    $rooms = collect($this->getJson('/api/term-setup/level-rooms')->assertOk()->json('data'))->firstWhere('level.id', $this->level->id)['rooms'];
    expect(collect($rooms)->pluck('location.name')->all())->toBe(['قاعة أ', 'قاعة ب'])
        ->and($rooms[0]['sources'])->toBe(['assigned'])->and($rooms[0]['level_room_id'])->toBe($id)
        ->and($rooms[1]['sources'])->toBe(['circle', 'timetable'])->and($rooms[1]['circles'])->toBe(['حلقة 1'])
        ->and($this->getJson('/api/term-setup/options')->json('data.level_rooms.0.location_id'))->toBe($a->id);

    $this->deleteJson("/api/term-setup/level-rooms/{$id}")->assertOk();

    actingAsRole('teacher');
    $this->getJson('/api/term-setup/level-rooms')->assertOk();
    $this->postJson('/api/term-setup/level-rooms', ['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'location_id' => $a->id])->assertForbidden();
});

it('gives supervisors term_setup.manage and teachers only term_setup.view', function () {
    $supervisor = \Spatie\Permission\Models\Role::findByName('supervisor', 'web');
    $teacher = \Spatie\Permission\Models\Role::findByName('teacher', 'web');
    expect($supervisor->hasPermissionTo('term_setup.manage'))->toBeTrue()
        ->and($supervisor->hasPermissionTo('term_setup.view'))->toBeTrue()
        ->and($teacher->hasPermissionTo('term_setup.view'))->toBeTrue()
        ->and($teacher->hasPermissionTo('term_setup.manage'))->toBeFalse();
});
