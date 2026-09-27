<?php

use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\User;
use App\Services\Lessons\SessionGenerator;

it('manages halls and their calendar', function () {
    actingAsRole('supervisor');

    $id = $this->postJson('/api/locations', ['name' => 'قاعة الفاتحة', 'code' => 'F1', 'capacity' => 25, 'map_link' => 'https://maps.google.com/?q=1,2'])
        ->assertCreated()->json('data.id');

    $this->putJson("/api/locations/{$id}", ['capacity' => 30])->assertOk()->assertJsonPath('data.capacity', 30);
    $this->getJson('/api/locations?all=1')->assertOk()->assertJsonCount(1, 'data');

    $lesson = Lesson::factory()->create(['location_id' => $id, 'days' => ['sun'], 'start_date' => today()->toDateString()]);
    app(SessionGenerator::class)->generateFor($lesson, today()->addWeeks(2));

    $res = $this->getJson("/api/locations/{$id}/calendar?from=".today()->toDateString().'&to='.today()->addWeeks(2)->toDateString())->assertOk();
    expect($res->json('items'))->not->toBeEmpty()->and($res->json('items.0.kind'))->toBe('session');

    $this->deleteJson("/api/locations/{$id}")->assertStatus(422);

    actingAsRole('teacher');
    $this->postJson('/api/locations', ['name' => 'x'])->assertForbidden();
});

it('creates, lists and scopes lessons per teacher', function () {
    $t1 = User::factory()->role('teacher')->create();
    $t2 = User::factory()->role('teacher')->create();
    Lesson::factory()->create(['teacher_id' => $t1->id, 'name' => 'حلقة الأولى']);
    $l2 = Lesson::factory()->create(['teacher_id' => $t2->id, 'name' => 'حلقة الثانية']);

    actingAsRole('supervisor');
    $this->getJson('/api/lessons')->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($t1, 'sanctum');
    $this->getJson('/api/lessons')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'حلقة الأولى');
    $this->getJson("/api/lessons/{$l2->id}")->assertForbidden();
    $this->postJson('/api/lessons', [])->assertForbidden();
});

it('validates lesson input', function () {
    actingAsRole('supervisor');
    $pkg = \App\Models\Package::factory()->create();
    $notTeacher = User::factory()->role('supervisor')->create();

    $this->postJson('/api/lessons', [
        'name' => 'x', 'package_id' => $pkg->id, 'teacher_id' => $notTeacher->id, 'days' => ['xyz'],
        'start_time' => '17:00', 'end_time' => '16:00', 'capacity' => 0, 'start_date' => today()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors(['days.0', 'end_time', 'capacity', 'teacher_id']);
});

it('enrolls and unenrolls students respecting capacity', function () {
    actingAsRole('supervisor');
    $lesson = Lesson::factory()->create(['capacity' => 2]);
    $students = Student::factory()->count(3)->create(['birth_date' => now()->subYears(10)->toDateString()]);

    $this->postJson("/api/lessons/{$lesson->id}/students", ['student_ids' => [$students[0]->id, $students[1]->id]])->assertOk();
    $this->postJson("/api/lessons/{$lesson->id}/students", ['student_ids' => [$students[2]->id]])->assertStatus(422)->assertJsonValidationErrors('student_ids');

    $this->deleteJson("/api/lessons/{$lesson->id}/students/{$students[0]->id}")->assertOk();
    expect(LessonStudent::where('lesson_id', $lesson->id)->where('student_id', $students[0]->id)->first()->status->value)->toBe('left');

    $this->postJson("/api/lessons/{$lesson->id}/students", ['student_ids' => [$students[2]->id]])->assertOk();
    expect($lesson->activeStudentCount())->toBe(2);
});

it('changes the hall for one day with an override and notifies students and guardians', function () {
    actingAsRole('supervisor');
    $lesson = Lesson::factory()->create(['days' => ['sun', 'tue'], 'start_date' => today()->toDateString()]);
    app(SessionGenerator::class)->generateFor($lesson, today()->addWeeks(2));
    $session = LessonSession::where('lesson_id', $lesson->id)->where('session_date', '>', today())->orderBy('session_date')->first();
    $newHall = Location::factory()->create(['map_link' => 'https://maps.google.com/?q=9,9']);

    $s1 = Student::factory()->create(['student_phone' => '+97336100001', 'guardian_phone' => '+97336100002', 'birth_date' => today()->subYears(14)->toDateString()]); // 12+: gets a copy (section 23)
    $s2 = Student::factory()->create(['student_phone' => null, 'guardian_phone' => '+97336100003']);
    $lesson->lessonStudents()->createMany([
        ['student_id' => $s1->id, 'joined_at' => today(), 'status' => 'active'],
        ['student_id' => $s2->id, 'joined_at' => today(), 'status' => 'active'],
    ]);

    $this->postJson("/api/lessons/{$lesson->id}/change-location", [
        'mode' => 'one_day', 'date' => $session->session_date->toDateString(), 'location_id' => $newHall->id, 'notify' => true,
    ])->assertOk()->assertJsonPath('notified', 3);

    expect($session->fresh()->location_id)->toBe($newHall->id)
        ->and($lesson->fresh()->location_id)->not->toBe($newHall->id)
        ->and(LessonLocationOverride::where('lesson_id', $lesson->id)->first()->notified_at)->not->toBeNull();

    $logs = MessageLog::where('type', 'location_change')->get();
    expect($logs)->toHaveCount(3)
        ->and($logs->pluck('recipient_phone')->sort()->values()->all())->toBe(['+97336100001', '+97336100002', '+97336100003'])
        ->and($logs->first()->body)->toContain($newHall->name)->toContain('https://maps.google.com/?q=9,9');
});

it('changes the hall for all upcoming sessions and refuses a busy hall', function () {
    actingAsRole('supervisor');
    $lesson = Lesson::factory()->create(['days' => ['sun'], 'start_time' => '16:00:00', 'end_time' => '17:00:00', 'start_date' => today()->toDateString()]);
    app(SessionGenerator::class)->generateFor($lesson, today()->addWeeks(3));
    $newHall = Location::factory()->create();
    $busyHall = Location::factory()->create();
    Lesson::factory()->create(['location_id' => $busyHall->id, 'days' => ['sun'], 'start_time' => '16:30:00', 'end_time' => '18:00:00', 'start_date' => today()->toDateString()]);

    $this->postJson("/api/lessons/{$lesson->id}/change-location", ['mode' => 'all_upcoming', 'location_id' => $busyHall->id])
        ->assertStatus(422)->assertJsonValidationErrors('location_id');

    $this->postJson("/api/lessons/{$lesson->id}/change-location", ['mode' => 'all_upcoming', 'location_id' => $newHall->id, 'notify' => false])->assertOk();

    expect($lesson->fresh()->location_id)->toBe($newHall->id)
        ->and(LessonSession::where('lesson_id', $lesson->id)->where('session_date', '>=', today())->get()->every(fn ($s) => $s->location_id === $newHall->id))->toBeTrue()
        ->and(MessageLog::where('type', 'location_change')->count())->toBe(0);
});

it('lists only free halls for a slot', function () {
    actingAsRole('supervisor');
    $free = Location::factory()->create(['name' => 'حرة']);
    $busy = Location::factory()->create(['name' => 'مشغولة']);
    $inactive = Location::factory()->create(['is_active' => false]);
    $sunday = today()->next(\Carbon\Carbon::SUNDAY);
    Lesson::factory()->create(['location_id' => $busy->id, 'days' => ['sun'], 'start_time' => '16:00:00', 'end_time' => '17:00:00', 'start_date' => today()->toDateString()]);

    $res = $this->getJson('/api/locations/free?date='.$sunday->toDateString().'&start_time=16:30&end_time=17:30')->assertOk();
    $ids = collect($res->json('data'))->pluck('id')->all();

    expect($ids)->toContain($free->id)->not->toContain($busy->id)->not->toContain($inactive->id);
});

it('lists today sessions for the dashboard and lets a teacher cancel their own session only', function () {
    $t1 = User::factory()->role('teacher')->create();
    $t2 = User::factory()->role('teacher')->create();
    $s1 = LessonSession::factory()->create(['lesson_id' => Lesson::factory()->create(['teacher_id' => $t1->id])->id]);
    $s2 = LessonSession::factory()->create(['lesson_id' => Lesson::factory()->create(['teacher_id' => $t2->id])->id]);

    actingAsRole('supervisor');
    $this->getJson('/api/sessions/today')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.attendance_taken', false);

    $this->actingAs($t1, 'sanctum');
    $this->getJson('/api/sessions/today')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson("/api/sessions/{$s1->id}", ['status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->patchJson("/api/sessions/{$s2->id}", ['status' => 'cancelled'])->assertForbidden();
});
