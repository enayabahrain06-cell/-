<?php

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Models\Student;

beforeEach(function () {
    $this->package = Package::factory()->create(['min_age' => 7, 'max_age' => 12, 'gender' => 'male', 'seats' => 5, 'start_date' => now()->addDays(30)->toDateString()]);
    $this->circle = Lesson::factory()->create(['package_id' => $this->package->id, 'capacity' => 5]);
    // Five multiple-choice questions of 2 marks each, so every correct answer is worth 20%.
    $this->exam = Exam::factory()->placement($this->package)->create(['total_marks' => 10]);
    foreach (range(1, 5) as $i) {
        ExamQuestion::factory()->for($this->exam)->create(['marks' => 2, 'sort_order' => $i, 'prompt' => "سؤال $i"]);
    }
});

function placementPayload(array $overrides = []): array
{
    return $overrides + [
        'package_id' => test()->package->id,
        'full_name' => 'أحمد محمد الدوسري',
        'birth_date' => now()->subYears(10)->toDateString(),
        'gender' => 'male',
        'guardian_name' => 'محمد علي الدوسري',
        'guardian_phone' => '36001001',
        'memorization_level' => 'juz_amma',
        'locale' => 'ar',
    ];
}

/** Start an attempt and answer it: the first $correct questions right, the rest wrong. Returns the token. */
function takePlacement(int $correct, string $phone = '36001001', bool $submit = true): string
{
    $start = test()->postJson('/api/public/placement', ['package_id' => test()->package->id, 'full_name' => 'أحمد محمد', 'guardian_phone' => $phone])->assertCreated();
    $token = $start->json('data.token');
    $questions = collect($start->json('data.attempt.questions'));

    test()->putJson("/api/public/placement/$token/answers", ['answers' => $questions->values()->map(fn ($q, $i) => [
        'question_id' => $q['id'], 'answer' => ['key' => $i < $correct ? 'b' : 'a'],
    ])->all()])->assertOk();

    if ($submit) {
        test()->postJson("/api/public/placement/$token/submit")->assertOk();
    }

    return $token;
}

it('offers the test on the package, hides the answer key, scores from the real answers and recommends a level', function () {
    $this->getJson('/api/public/packages?gender=male')->assertOk()
        ->assertJsonPath('data.0.placement.questions_count', 5)
        ->assertJsonPath('data.0.placement.name', $this->exam->name);

    $start = $this->postJson('/api/public/placement', ['package_id' => $this->package->id, 'full_name' => 'أحمد محمد', 'guardian_phone' => '36001001'])->assertCreated();
    expect($start->json('data.token'))->toHaveLength(48)
        ->and($start->json('data.attempt.questions'))->toHaveCount(5)
        ->and(collect($start->json('data.attempt.questions'))->pluck('correct_answer')->filter())->toBeEmpty();

    $token = $start->json('data.token');
    $ids = collect($start->json('data.attempt.questions'))->pluck('id');
    $this->putJson("/api/public/placement/$token/answers", ['answers' => $ids->map(fn ($id, $i) => ['question_id' => $id, 'answer' => ['key' => $i < 4 ? 'b' : 'a']])->all()])->assertOk();

    $res = $this->postJson("/api/public/placement/$token/submit")->assertOk();
    expect($res->json('data.result'))->toMatchArray([
        'correct' => 4, 'incorrect' => 1, 'total_questions' => 5, 'score' => 8, 'total_marks' => 10, 'percent' => 80, 'recommended_level' => 'five_ajza', 'attempt_no' => 1,
    ])
        // right/wrong per question, never the key
        ->and($res->json('data.result.questions.4'))->toMatchArray(['position' => 5, 'is_correct' => false])
        ->and($res->json('data.result.questions.0'))->not->toHaveKey('correct_answer');
});

it('maps 0% and 100% to the lowest and highest bands', function () {
    $zero = takePlacement(0, '36001001');
    $full = takePlacement(5, '36001002');

    $this->getJson("/api/public/placement/$zero")->assertJsonPath('data.result.percent', 0)->assertJsonPath('data.result.recommended_level', 'none');
    $this->getJson("/api/public/placement/$full")->assertJsonPath('data.result.percent', 100)->assertJsonPath('data.result.recommended_level', 'ten_ajza');
});

it('refuses an incomplete submission while time remains, and resumes where the family left off', function () {
    $start = $this->postJson('/api/public/placement', ['package_id' => $this->package->id, 'full_name' => 'أحمد محمد', 'guardian_phone' => '36001001'])->assertCreated();
    $token = $start->json('data.token');
    $first = $start->json('data.attempt.questions.0.id');
    $this->putJson("/api/public/placement/$token/answers", ['answers' => [['question_id' => $first, 'answer' => ['key' => 'b']]]])->assertOk();

    $this->postJson("/api/public/placement/$token/submit")->assertStatus(422)->assertJsonValidationErrors('answers');

    // Closing the tab loses nothing: the same questions and the saved answer come back.
    $this->getJson("/api/public/placement/$token")->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.attempt.answers.0.question_id', $first)
        ->assertJsonPath('data.attempt.questions.0.id', $first);
});

it('scores an expired attempt as it stands', function () {
    $token = takePlacement(2, submit: false);
    $this->travel(31)->minutes(); // duration is 30

    $this->getJson("/api/public/placement/$token")->assertOk()
        ->assertJsonPath('data.status', 'graded')
        ->assertJsonPath('data.result.percent', 40)
        ->assertJsonPath('data.result.recommended_level', 'juz_amma');
});

it('treats a second submit as the same result', function () {
    $token = takePlacement(3);
    $again = $this->postJson("/api/public/placement/$token/submit")->assertOk();

    expect($again->json('data.result.percent'))->toEqual(60)
        ->and(ExamAttempt::where('exam_id', $this->exam->id)->count())->toBe(1);
});

it('numbers attempts per phone and stops at the configured limit', function () {
    config(['ahl.placement_max_attempts' => 2]);
    $a = takePlacement(1);
    $b = takePlacement(2);

    $this->getJson("/api/public/placement/$b")->assertJsonPath('data.result.attempt_no', 2);
    $this->postJson('/api/public/placement', ['package_id' => $this->package->id, 'full_name' => 'أحمد محمد', 'guardian_phone' => '36001001'])
        ->assertStatus(422)->assertJsonValidationErrors('exam');
    // another family is not affected
    $this->postJson('/api/public/placement', ['package_id' => $this->package->id, 'full_name' => 'بدر', 'guardian_phone' => '36009999'])->assertCreated();
});

it('requires a finished test to register, links it once, and refuses reuse', function () {
    $this->postJson('/api/public/registrations', placementPayload())->assertStatus(422)->assertJsonValidationErrors('placement_token');

    $unfinished = takePlacement(5, submit: false);
    $this->postJson('/api/public/registrations', placementPayload(['placement_token' => $unfinished]))->assertStatus(422)->assertJsonValidationErrors('placement_token');

    $token = takePlacement(4, '36001002');
    $res = $this->postJson('/api/public/registrations', placementPayload(['placement_token' => $token, 'guardian_phone' => '36001002']))->assertCreated();

    $req = RegistrationRequest::where('request_no', $res->json('request_no'))->first();
    expect($req->recommended_level->value)->toBe('five_ajza')
        ->and($req->memorization_level->value)->toBe('juz_amma') // what the family declared is kept
        ->and($req->placementAttempt->registration_request_id)->toBe($req->id)
        ->and($req->placementAttempt->access_token)->toBeNull();

    // the token is spent
    $this->postJson('/api/public/registrations', placementPayload(['placement_token' => $token, 'guardian_phone' => '36001003']))->assertStatus(422)->assertJsonValidationErrors('placement_token');
    $this->getJson("/api/public/placement/$token")->assertNotFound();
});

it('does not let autosaving use up the registration rate limit', function () {
    $token = takePlacement(3, submit: false);
    $q = $this->getJson("/api/public/placement/$token")->json('data.attempt.questions.0.id');
    foreach (range(1, 15) as $i) {
        $this->putJson("/api/public/placement/$token/answers", ['answers' => [['question_id' => $q, 'answer' => ['key' => 'b']]]])->assertOk();
    }
    $this->postJson("/api/public/placement/$token/submit")->assertOk();

    $this->postJson('/api/public/registrations', placementPayload(['placement_token' => $token]))->assertCreated();
});

it('lets staff confirm or change the level on acceptance and shows the result on the profile', function () {
    $token = takePlacement(4);
    $no = $this->postJson('/api/public/registrations', placementPayload(['placement_token' => $token]))->assertCreated()->json('request_no');
    $req = RegistrationRequest::where('request_no', $no)->first();

    $admin = actingAsRole('supervisor');
    $this->getJson('/api/registrations?status=pending')->assertOk()
        ->assertJsonPath('data.0.recommended_level', 'five_ajza')
        ->assertJsonPath('data.0.placement.percent', 80)
        ->assertJsonPath('data.0.placement.correct', 4);

    // staff override: ten ajza instead of the recommended five
    $studentId = $this->postJson("/api/registrations/{$req->id}/accept", ['lesson_id' => $this->circle->id, 'final_level' => 'ten_ajza'])->assertOk()->json('student.id');

    $student = Student::find($studentId);
    expect($student->memorization_level->value)->toBe('ten_ajza')
        ->and($req->fresh()->level_confirmed_by)->toBe($admin->id)
        ->and($req->fresh()->placementAttempt->student_id)->toBe($student->id);

    $profile = $this->getJson("/api/students/{$student->id}/placement")->assertOk();
    expect($profile->json('data.0'))->toMatchArray([
        'percent' => 80, 'recommended_level' => 'five_ajza', 'final_level' => 'ten_ajza', 'declared_level' => 'juz_amma', 'attempt_no' => 1, 'level_confirmed_by' => $admin->name,
    ])->and($profile->json('data.0.answers'))->toHaveCount(5)
        ->and($profile->json('data.0.answers.0.correct_answer'))->toBe(['key' => 'b']);
});

it('keeps the declared level when staff accept without confirming one', function () {
    $token = takePlacement(5);
    $no = $this->postJson('/api/public/registrations', placementPayload(['placement_token' => $token]))->assertCreated()->json('request_no');
    $req = RegistrationRequest::where('request_no', $no)->first();

    actingAsRole('supervisor');
    $id = $this->postJson("/api/registrations/{$req->id}/accept", ['lesson_id' => $this->circle->id])->assertOk()->json('student.id');

    expect(Student::find($id)->memorization_level->value)->toBe('juz_amma') // recommendation (ten ajza) never applied on its own
        ->and($req->fresh()->final_level)->toBeNull();
});

it('leaves packages without a test, and registration for them, unchanged', function () {
    $this->exam->update(['status' => 'draft']);

    $this->getJson('/api/public/packages?gender=male')->assertOk()->assertJsonPath('data.0.placement', null);
    $this->postJson('/api/public/registrations', placementPayload())->assertCreated();
    $this->postJson('/api/public/placement', ['package_id' => $this->package->id, 'full_name' => 'أحمد محمد', 'guardian_phone' => '36001001'])->assertNotFound();
});

it('keeps placement tests away from enrolled students', function () {
    $user = \App\Models\User::factory()->withoutPassword()->create();
    $user->assignRole('student');
    $student = Student::factory()->create(['user_id' => $user->id]);
    \App\Models\LessonStudent::create(['lesson_id' => $this->circle->id, 'student_id' => $student->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);
    $this->actingAs($user, 'sanctum');

    $exams = collect($this->getJson('/api/me/exams')->assertOk()->json('data'))->flatten(1)->pluck('id');
    expect($exams)->not->toContain($this->exam->id);
    $this->postJson("/api/me/exams/{$this->exam->id}/start")->assertStatus(422);
});

it('validates placement tests: package only, bands from 0%, no recitation', function () {
    actingAsRole('supervisor');
    $base = ['name' => 'تحديد المستوى', 'type' => 'placement', 'exam_date' => now()->toDateString(), 'opens_at' => now()->toDateTimeString(), 'closes_at' => now()->addMonth()->toDateTimeString(),
        'duration_minutes' => 20, 'total_marks' => 10, 'pass_mark' => 0];

    $this->postJson('/api/exams', $base + ['lesson_id' => $this->circle->id, 'level_bands' => [['min' => 0, 'level' => 'none']]])
        ->assertStatus(422)->assertJsonValidationErrors(['package_id', 'lesson_id']);
    $this->postJson('/api/exams', $base + ['package_id' => $this->package->id, 'level_bands' => [['min' => 50, 'level' => 'hafiz']]])
        ->assertStatus(422)->assertJsonValidationErrors('level_bands');

    $id = $this->postJson('/api/exams', $base + ['package_id' => $this->package->id, 'level_bands' => [['min' => 0, 'level' => 'none'], ['min' => 60, 'level' => 'hafiz']]])
        ->assertCreated()->assertJsonPath('data.level_bands.0.min', 60)->json('data.id'); // stored highest first

    $this->postJson("/api/exams/$id/questions", ['type' => 'recitation', 'prompt' => 'اتلُ', 'marks' => 10])->assertStatus(422)->assertJsonValidationErrors('type');
});
