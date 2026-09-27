<?php

use App\Models\AgeGroup;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\AgeGroupSeeder::class);
    $this->g6 = AgeGroup::where('min_age', 6)->first();
    $this->g9 = AgeGroup::where('min_age', 9)->first();

    $start = now()->addDays(10)->toDateString();
    $this->boys = Package::factory()->create(['gender' => 'male', 'min_age' => 6, 'max_age' => 14, 'seats' => 50, 'start_date' => $start]);
    $this->girls = Package::factory()->create(['gender' => 'female', 'min_age' => 6, 'max_age' => 14, 'seats' => 50, 'start_date' => $start]);
    $female = User::factory()->create(['gender' => 'female']);
    $female->assignRole('teacher');

    // One package, one circle per age group.
    $this->boys6 = Lesson::factory()->create(['package_id' => $this->boys->id, 'name' => 'أشبال', 'age_group_id' => $this->g6->id, 'min_age' => 6, 'max_age' => 8, 'capacity' => 2]);
    $this->boys9a = Lesson::factory()->create(['package_id' => $this->boys->id, 'name' => 'براعم أ', 'age_group_id' => $this->g9->id, 'min_age' => 9, 'max_age' => 11, 'capacity' => 3]);
    $this->boys9b = Lesson::factory()->create(['package_id' => $this->boys->id, 'name' => 'براعم ب', 'age_group_id' => $this->g9->id, 'min_age' => 9, 'max_age' => 11, 'capacity' => 5]);
    $this->girls9 = Lesson::factory()->create(['package_id' => $this->girls->id, 'name' => 'زهرات', 'teacher_id' => $female->id, 'age_group_id' => $this->g9->id, 'min_age' => 9, 'max_age' => 11, 'capacity' => 5]);

    $this->birth10 = now()->addDays(10)->subYears(10)->toDateString(); // 10 on the package start date
});

it('lists circles filtered by gender, age group and free seats, grouped data included', function () {
    actingAsRole('supervisor');
    LessonStudent::create(['lesson_id' => $this->boys6->id, 'student_id' => Student::factory()->male()->create()->id, 'joined_at' => today(), 'status' => 'active']);
    LessonStudent::create(['lesson_id' => $this->boys6->id, 'student_id' => Student::factory()->male()->create()->id, 'joined_at' => today(), 'status' => 'active']);

    $ids = fn (string $q) => collect($this->getJson('/api/lessons?all=1&'.$q)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('gender=female'))->toBe([$this->girls9->id])
        ->and($ids('age_group_id='.$this->g9->id.'&gender=male'))->toBe(collect([$this->boys9a->id, $this->boys9b->id])->sort()->values()->all())
        ->and($ids('has_seats=1&gender=male'))->not->toContain($this->boys6->id)
        ->and($ids('age=7'))->toBe([$this->boys6->id]);

    $row = collect($this->getJson('/api/lessons?all=1')->json('data'))->firstWhere('id', $this->boys6->id);
    expect($row['age_group']['id'])->toBe($this->g6->id)->and($row['free_seats'])->toBe(0)->and($row['min_age'])->toBe(6);

    // Matching for a 10-year-old boy: only boys 9–11 with seats, most free seats first.
    $match = $this->getJson("/api/circles/match?gender=male&birth_date={$this->birth10}&fitting_only=1")->assertOk();
    expect(collect($match->json('data'))->pluck('id')->all())->toBe([$this->boys9b->id, $this->boys9a->id])
        ->and($match->json('recommended_id'))->toBe($this->boys9b->id);

    $all = collect($this->getJson("/api/circles/match?gender=male&birth_date={$this->birth10}")->json('data'))->keyBy('id');
    expect($all[$this->boys6->id]['reason'])->toBe('age')->and($all[$this->girls9->id]['reason'])->toBe('gender');
});

it('accepting a request enrolls into the chosen circle immediately and pre-selects the best one', function () {
    actingAsRole('supervisor');
    $r = RegistrationRequest::factory()->create(['package_id' => $this->boys->id, 'birth_date' => $this->birth10, 'gender' => 'male']);

    $circles = $this->getJson("/api/registrations/{$r->id}/circles")->assertOk();
    expect($circles->json('recommended_id'))->toBe($this->boys9b->id);

    $res = $this->postJson("/api/registrations/{$r->id}/accept", ['lesson_id' => $this->boys9a->id])->assertOk();
    $studentId = $res->json('student.id');
    expect($r->fresh()->status->value)->toBe('enrolled')->and($r->fresh()->lesson_id)->toBe($this->boys9a->id)
        ->and(LessonStudent::where('student_id', $studentId)->where('lesson_id', $this->boys9a->id)->where('status', 'active')->count())->toBe(1);

    // Wrong age group or another package's circle is refused.
    $r2 = RegistrationRequest::factory()->create(['package_id' => $this->boys->id, 'birth_date' => $this->birth10, 'gender' => 'male']);
    $this->postJson("/api/registrations/{$r2->id}/accept", ['lesson_id' => $this->boys6->id])->assertStatus(422)->assertJsonValidationErrors('lesson_id');
    $this->postJson("/api/registrations/{$r2->id}/accept", ['lesson_id' => $this->girls9->id])->assertStatus(422)->assertJsonValidationErrors('lesson_id');
    $this->postJson("/api/registrations/{$r2->id}/accept", [])->assertStatus(422)->assertJsonValidationErrors('lesson_id');
    expect($r2->fresh()->status->value)->toBe('pending')->and(Student::count())->toBe(1);

    // The lottery path holds a seat without a circle.
    $this->postJson("/api/registrations/{$r2->id}/accept", ['lottery' => true])->assertOk();
    expect($r2->fresh()->status->value)->toBe('pending_lottery')
        ->and(LessonStudent::where('student_id', $r2->fresh()->student_id)->exists())->toBeFalse();
});

it('bulk accept places each student in the best circle and waitlists those with no fitting seat', function () {
    actingAsRole('supervisor');
    $tens = RegistrationRequest::factory()->count(2)->create(['package_id' => $this->boys->id, 'birth_date' => $this->birth10, 'gender' => 'male']);
    $thirteen = RegistrationRequest::factory()->create(['package_id' => $this->boys->id, 'birth_date' => now()->addDays(10)->subYears(13)->toDateString(), 'gender' => 'male']);

    $res = $this->postJson('/api/registrations/bulk-accept', ['package_id' => $this->boys->id])->assertOk();
    expect(collect($res->json('accepted'))->pluck('lesson_id')->unique()->all())->toBe([$this->boys9b->id])
        ->and(collect($res->json('waitlisted'))->pluck('id')->all())->toBe([$thirteen->id])
        ->and($thirteen->fresh()->status->value)->toBe('waitlist');
});

it('refuses a circle of another gender or age group from any entry point', function () {
    actingAsRole('supervisor');
    $boy10 = Student::factory()->male()->create(['birth_date' => $this->birth10]);
    $girl10 = Student::factory()->female()->create(['birth_date' => $this->birth10]);

    $this->postJson("/api/lessons/{$this->boys6->id}/students", ['student_ids' => [$boy10->id]])->assertStatus(422);
    $this->postJson("/api/lessons/{$this->boys9a->id}/students", ['student_ids' => [$girl10->id]])->assertStatus(422);
    $this->postJson("/api/lessons/{$this->boys9a->id}/students", ['student_ids' => [$boy10->id]])->assertSuccessful();
    expect(LessonStudent::where('student_id', $boy10->id)->where('status', 'active')->pluck('lesson_id')->all())->toBe([$this->boys9a->id]);

    $this->postJson("/api/students/{$boy10->id}/move", ['lesson_id' => $this->boys6->id])->assertStatus(422);
    $this->postJson("/api/students/{$boy10->id}/move", ['lesson_id' => $this->girls9->id])->assertStatus(422);
});

it('moves a student between circles in one action and keeps the history', function () {
    $supervisor = actingAsRole('supervisor');
    $boy = Student::factory()->male()->create(['birth_date' => $this->birth10]);
    $this->postJson("/api/lessons/{$this->boys9a->id}/students", ['student_ids' => [$boy->id]])->assertSuccessful();

    $options = $this->getJson("/api/students/{$boy->id}/move-options")->assertOk();
    expect($options->json('current.id'))->toBe($this->boys9a->id)->and($options->json('recommended_id'))->toBe($this->boys9b->id);

    $effective = today()->addDays(3)->toDateString();
    $this->postJson("/api/students/{$boy->id}/move", ['lesson_id' => $this->boys9b->id, 'effective_date' => $effective, 'reason' => 'تغيير موعد'])->assertOk();

    $old = LessonStudent::where('student_id', $boy->id)->where('lesson_id', $this->boys9a->id)->first();
    expect($old->status->value)->toBe('left')->and($old->left_at->toDateString())->toBe($effective)
        ->and($old->moved_by)->toBe($supervisor->id)->and($old->reason)->toBe('تغيير موعد')->and($old->moved_to_lesson_id)->toBe($this->boys9b->id)
        ->and(LessonStudent::where('student_id', $boy->id)->where('status', 'active')->pluck('lesson_id')->all())->toBe([$this->boys9b->id])
        ->and(AuditLog::where('action', 'student.circle_moved')->count())->toBe(1);

    // Moving back opens a new stay: history keeps all three rows.
    $this->postJson("/api/students/{$boy->id}/move", ['lesson_id' => $this->boys9a->id])->assertOk();
    $history = $this->getJson("/api/students/{$boy->id}/circles")->assertOk()->json('data');
    expect($history)->toHaveCount(3)
        ->and(collect($history)->where('status', 'active')->pluck('lesson.id')->all())->toBe([$this->boys9a->id]);

    // A teacher cannot move a student out of someone else's circle.
    actingAsRole('teacher');
    $this->postJson("/api/students/{$boy->id}/move", ['lesson_id' => $this->boys9b->id])->assertForbidden();
});

it('flags students who aged out of their circle with a suggested move, and manages age groups', function () {
    actingAsRole('supervisor');
    $boy = Student::factory()->male()->create(['birth_date' => now()->subYears(8)->toDateString()]);
    LessonStudent::create(['lesson_id' => $this->boys6->id, 'student_id' => $boy->id, 'joined_at' => today(), 'status' => 'active']);

    $this->getJson('/api/circles/aged-out')->assertOk()->assertJsonCount(0, 'data');
    $res = $this->getJson('/api/circles/aged-out?on='.now()->addYear()->toDateString())->assertOk();
    expect($res->json('data.0.student.id'))->toBe($boy->id)
        ->and($res->json('data.0.direction'))->toBe('older')
        ->and($res->json('data.0.suggested.id'))->toBeIn([$this->boys9a->id, $this->boys9b->id]);

    $id = $this->postJson('/api/age-groups', ['name_ar' => 'الروضة', 'name_en' => 'Early years', 'min_age' => 4, 'max_age' => 5, 'track' => 'mixed'])->assertCreated()->json('data.id');
    $this->postJson('/api/age-groups', ['name_ar' => 'x', 'name_en' => 'x', 'min_age' => 9, 'max_age' => 7])->assertStatus(422);
    $this->deleteJson("/api/age-groups/{$this->g6->id}")->assertStatus(422); // used by a circle
    $this->deleteJson("/api/age-groups/{$id}")->assertOk();

    // A new circle takes its age group's range.
    $lesson = $this->postJson('/api/lessons', [
        'name' => 'ناشئة', 'package_id' => $this->boys->id, 'teacher_id' => $this->boys6->teacher_id, 'days' => ['sat'],
        'start_time' => '16:00', 'end_time' => '17:00', 'capacity' => 10, 'start_date' => today()->toDateString(),
        'age_group_id' => AgeGroup::where('min_age', 12)->value('id'),
    ])->assertCreated()->json('data');
    expect($lesson['min_age'])->toBe(12)->and($lesson['max_age'])->toBe(14);

    actingAsRole('teacher');
    $this->postJson('/api/age-groups', ['name_ar' => 'x', 'name_en' => 'x', 'min_age' => 4])->assertForbidden();
    $this->getJson('/api/circles/aged-out')->assertForbidden();
});
