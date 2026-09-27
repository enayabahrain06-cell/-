<?php

use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Package;
use App\Models\Student;
use App\Models\User;

beforeEach(function () {
    $start = now()->addWeeks(2)->toDateString();
    $this->boys = Package::factory()->create(['min_age' => 7, 'max_age' => 12, 'start_date' => $start]);
    $this->girls = Package::factory()->girls()->create(['min_age' => 7, 'max_age' => 12, 'start_date' => $start]);

    $this->teacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->otherTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->circle = Lesson::factory()->create(['name' => 'حلقة نافع', 'package_id' => $this->boys->id, 'teacher_id' => $this->teacher->id, 'capacity' => 2]);
    $this->otherCircle = Lesson::factory()->create(['name' => 'حلقة ورش', 'package_id' => $this->boys->id, 'teacher_id' => $this->otherTeacher->id]);
    $this->girlsCircle = Lesson::factory()->create(['package_id' => $this->girls->id, 'teacher_id' => User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female'])->id]);
});

function addStudentBoy(array $attributes = []): Student
{
    return Student::factory()->male()->create($attributes + ['full_name' => 'حسن علي المرزوق', 'birth_date' => now()->subYears(10)->toDateString()]);
}

function addStudentCandidates(Lesson $lesson, string $search): \Illuminate\Support\Collection
{
    return collect(test()->getJson("/api/lessons/{$lesson->id}/candidates?search=".urlencode($search))->assertOk()->json('data'))->keyBy('id');
}

it('lets a teacher with quick enrollment add to their own circle only, and a supervisor within their track', function () {
    $student = addStudentBoy();

    $this->actingAs($this->teacher, 'sanctum');
    $this->getJson("/api/lessons/{$this->circle->id}")->assertOk()->assertJsonPath('data.can_add_students', true);
    $this->postJson("/api/lessons/{$this->otherCircle->id}/students", ['student_ids' => [$student->id]])->assertForbidden();
    $this->getJson("/api/lessons/{$this->otherCircle->id}/candidates?search=حسن")->assertForbidden();
    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [$student->id]])->assertOk()->assertJsonPath('added', [$student->id]);
    // Removing students stays with lessons.manage.
    $this->deleteJson("/api/lessons/{$this->circle->id}/students/{$student->id}")->assertForbidden();

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->postJson("/api/lessons/{$this->otherCircle->id}/students", ['student_ids' => [addStudentBoy()->id]])->assertForbidden();

    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $this->getJson("/api/lessons/{$this->otherCircle->id}")->assertJsonPath('data.can_add_students', true);

    expect(AuditLog::where('action', 'lesson.students_added')->where('auditable_id', $this->circle->id)->exists())->toBeTrue();
});

it('refuses students of the wrong gender, age, status or track', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $tooYoung = addStudentBoy(['birth_date' => now()->subYears(5)->toDateString()]);
    $suspended = addStudentBoy(['status' => 'suspended']);
    $girl = Student::factory()->female()->create(['birth_date' => now()->subYears(10)->toDateString()]);

    foreach ([$tooYoung, $suspended, $girl] as $s) {
        $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [$s->id]])->assertStatus(422)->assertJsonValidationErrors('student_ids');
    }
    // A paused circle takes no one.
    $this->circle->update(['status' => 'paused']);
    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [addStudentBoy()->id]])->assertStatus(422);
    expect(LessonStudent::where('lesson_id', $this->circle->id)->count())->toBe(0);
});

it('searches candidates with the reason each one can or cannot be added', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $ok = addStudentBoy(['guardian_phone' => '+97336009001']);
    $young = addStudentBoy(['birth_date' => now()->subYears(4)->toDateString()]);
    $elsewhere = addStudentBoy();
    LessonStudent::create(['lesson_id' => $this->otherCircle->id, 'student_id' => $elsewhere->id, 'status' => 'active', 'joined_at' => today()]);
    $girl = Student::factory()->female()->create(['full_name' => 'حسن زينب', 'birth_date' => now()->subYears(10)->toDateString()]);

    $rows = addStudentCandidates($this->circle, 'حسن');
    expect($rows)->not->toHaveKey($girl->id) // other track: never listed
        ->and($rows[$ok->id])->toMatchArray(['reason' => null, 'action' => 'add', 'age_at_start' => 10])
        ->and($rows[$young->id])->toMatchArray(['reason' => 'age', 'action' => null])
        ->and($rows[$elsewhere->id]['action'])->toBe('move')
        ->and($rows[$elsewhere->id]['circles'][0]['name'])->toBe('حلقة ورش');

    // By guardian phone, typed with spaces.
    expect(addStudentCandidates($this->circle, '3600 9001')->keys()->all())->toBe([$ok->id]);

    // Full circle: every otherwise-eligible candidate says so.
    $this->circle->update(['capacity' => 0]);
    expect(addStudentCandidates($this->circle, 'حسن')[$ok->id])->toMatchArray(['reason' => 'full', 'action' => null]);
});

it('does not double-enrol: a student in another circle is moved only on confirmation', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $student = addStudentBoy();
    LessonStudent::create(['lesson_id' => $this->otherCircle->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => today()->subMonth()]);

    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [$student->id]])->assertStatus(422)->assertJsonValidationErrors('student_ids');
    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [$student->id], 'move' => true])
        ->assertOk()->assertJsonPath('moved.0.from_lesson_id', $this->otherCircle->id);

    $old = LessonStudent::where('lesson_id', $this->otherCircle->id)->where('student_id', $student->id)->first();
    expect($old->status->value)->toBe('left')
        ->and($old->left_at->toDateString())->toBe(today()->toDateString())
        ->and(LessonStudent::where('student_id', $student->id)->where('status', 'active')->pluck('lesson_id')->all())->toBe([$this->circle->id]);
});

it('lets a teacher move a student only out of their own circles', function () {
    $student = addStudentBoy();
    LessonStudent::create(['lesson_id' => $this->otherCircle->id, 'student_id' => $student->id, 'status' => 'active', 'joined_at' => today()]);
    $this->actingAs($this->teacher, 'sanctum');

    expect(addStudentCandidates($this->circle, 'حسن')[$student->id])->toMatchArray(['reason' => 'other_circle_locked', 'action' => null]);
    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [$student->id], 'move' => true])->assertStatus(422);
    expect(LessonStudent::where('lesson_id', $this->otherCircle->id)->first()->status->value)->toBe('active');
});

it('keeps to the seat limit', function () {
    actingAsRole('supervisor', ['gender' => 'male', 'track' => 'male']);
    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [addStudentBoy()->id, addStudentBoy()->id, addStudentBoy()->id]])->assertStatus(422)->assertJsonValidationErrors('student_ids');
    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [addStudentBoy()->id, addStudentBoy()->id]])->assertOk();
    $this->postJson("/api/lessons/{$this->circle->id}/students", ['student_ids' => [addStudentBoy()->id]])->assertStatus(422);
    expect($this->circle->activeStudentCount())->toBe(2);
});
