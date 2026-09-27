<?php

use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;

function enrolledStudentUser(Lesson $lesson): array
{
    $user = User::factory()->withoutPassword()->create();
    $user->assignRole('student');
    $student = Student::factory()->create(['user_id' => $user->id, 'student_phone' => $user->phone]);
    LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $student->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);

    return [$user, $student];
}

function objectiveExam(array $state = []): Exam
{
    $exam = Exam::factory()->openNow()->create($state + ['total_marks' => 8, 'pass_mark' => 5, 'duration_minutes' => 30]);
    ExamQuestion::factory()->for($exam)->create(['marks' => 2, 'sort_order' => 1]);
    ExamQuestion::factory()->for($exam)->trueFalse()->create(['marks' => 2, 'sort_order' => 2]);
    ExamQuestion::factory()->for($exam)->completeVerse()->create(['marks' => 2, 'sort_order' => 3]);
    ExamQuestion::factory()->for($exam)->orderVerses()->create(['marks' => 2, 'sort_order' => 4]);

    return $exam;
}

it('enforces the exam window when starting', function () {
    $exam = Exam::factory()->published()->create(['opens_at' => now()->addHour(), 'closes_at' => now()->addHours(3)]);
    ExamQuestion::factory()->for($exam)->create();
    [$user] = enrolledStudentUser($exam->lesson);
    $this->actingAs($user, 'sanctum');

    // before
    $this->postJson("/api/me/exams/{$exam->id}/start")->assertStatus(422)->assertJsonPath('errors.exam.0', __('exams.window_closed'));

    // after
    $exam->update(['opens_at' => now()->subHours(3), 'closes_at' => now()->subHour()]);
    $this->postJson("/api/me/exams/{$exam->id}/start")->assertStatus(422);

    // inside
    $exam->update(['opens_at' => now()->subMinute(), 'closes_at' => now()->addHour()]);
    $this->postJson("/api/me/exams/{$exam->id}/start")->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonCount(1, 'data.questions')
        ->assertJsonMissingPath('data.questions.0.correct_answer');
});

it('refuses students who are not enrolled and non-published exams', function () {
    $exam = Exam::factory()->openNow()->create();
    ExamQuestion::factory()->for($exam)->create();
    $other = Lesson::factory()->create();
    [$user] = enrolledStudentUser($other);
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/me/exams/{$exam->id}/start")->assertStatus(422)->assertJsonPath('errors.exam.0', __('exams.not_eligible'));

    $draft = Exam::factory()->create(['opens_at' => now()->subMinute(), 'closes_at' => now()->addHour(), 'lesson_id' => $other->id]);
    $this->postJson("/api/me/exams/{$draft->id}/start")->assertStatus(422);
});

it('auto-grades mcq, true/false, complete-verse (ignoring diacritics) and order-verses', function () {
    $exam = objectiveExam();
    [$user] = enrolledStudentUser($exam->lesson);
    $this->actingAs($user, 'sanctum');

    $q = $exam->questions()->get()->keyBy('type');
    $this->postJson("/api/me/exams/{$exam->id}/start")->assertOk();

    $this->putJson("/api/me/exams/{$exam->id}/attempt/answers", ['answers' => [
        ['question_id' => $q['mcq']->id, 'answer' => ['key' => 'b']],
        ['question_id' => $q['true_false']->id, 'answer' => ['value' => true]],
        ['question_id' => $q['complete_verse']->id, 'answer' => ['text' => ' أَحَدٌ ']],
        ['question_id' => $q['order_verses']->id, 'answer' => ['order' => ['k1', 'k2', 'k3']]],
    ]])->assertOk();

    $this->postJson("/api/me/exams/{$exam->id}/attempt/submit")->assertOk()
        ->assertJsonPath('data.status', 'graded')
        ->assertJsonPath('data.auto_score', 8)
        ->assertJsonPath('data.total_score', 8)
        ->assertJsonPath('data.passed', true);
});

it('scores wrong objective answers as zero and fails below the pass mark', function () {
    $exam = objectiveExam();
    [$user] = enrolledStudentUser($exam->lesson);
    $this->actingAs($user, 'sanctum');
    $q = $exam->questions()->get()->keyBy('type');

    $this->postJson("/api/me/exams/{$exam->id}/start")->assertOk();
    $this->putJson("/api/me/exams/{$exam->id}/attempt/answers", ['answers' => [
        ['question_id' => $q['mcq']->id, 'answer' => ['key' => 'a']],
        ['question_id' => $q['true_false']->id, 'answer' => ['value' => false]],
        ['question_id' => $q['complete_verse']->id, 'answer' => ['text' => 'الصمد']],
        ['question_id' => $q['order_verses']->id, 'answer' => ['order' => ['k1', 'k2']]],
    ]])->assertOk();

    $this->postJson("/api/me/exams/{$exam->id}/attempt/submit")->assertOk()
        ->assertJsonPath('data.total_score', 0)
        ->assertJsonPath('data.passed', false);

    expect($exam->attempts()->first()->answers()->where('is_correct', false)->count())->toBe(4);
});

it('rejects answers after the server-side timer expires and auto-submits', function () {
    $exam = objectiveExam(['duration_minutes' => 10]);
    [$user] = enrolledStudentUser($exam->lesson);
    $this->actingAs($user, 'sanctum');
    $mcq = $exam->questions()->where('type', 'mcq')->first();

    $this->postJson("/api/me/exams/{$exam->id}/start")->assertOk()
        ->assertJsonPath('data.remaining_seconds', fn ($s) => $s > 590 && $s <= 600);

    $this->putJson("/api/me/exams/{$exam->id}/attempt/answers", ['answers' => [['question_id' => $mcq->id, 'answer' => ['key' => 'b']]]])->assertOk();

    Carbon::setTestNow(now()->addMinutes(11));

    $this->putJson("/api/me/exams/{$exam->id}/attempt/answers", ['answers' => [['question_id' => $mcq->id, 'answer' => ['key' => 'a']]]])
        ->assertStatus(422)->assertJsonPath('errors.attempt.0', __('exams.attempt_closed'));

    $attempt = ExamAttempt::first();
    expect($attempt->status->value)->toBe('graded')
        ->and($attempt->total_score)->toBe(2)       // the answer saved before expiry counts
        ->and($attempt->submitted_at->format('Y-m-d H:i:s'))->toBe($attempt->expires_at->format('Y-m-d H:i:s'));

    Carbon::setTestNow();
});

it('caps expiry at closes_at when the window ends before the duration', function () {
    $exam = Exam::factory()->create(['status' => 'published', 'opens_at' => now()->subMinute(), 'closes_at' => now()->addMinutes(5), 'duration_minutes' => 60]);
    ExamQuestion::factory()->for($exam)->create();
    [$user] = enrolledStudentUser($exam->lesson);
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/me/exams/{$exam->id}/start")->assertOk()->assertJsonPath('data.remaining_seconds', fn ($s) => $s <= 300);
});

it('keeps recitation attempts in submitted until manually graded, then computes the total', function () {
    $exam = Exam::factory()->openNow()->create(['total_marks' => 12, 'pass_mark' => 7]);
    $mcq = ExamQuestion::factory()->for($exam)->create(['marks' => 2]);
    $rec = ExamQuestion::factory()->for($exam)->recitation()->create(['marks' => 10, 'sort_order' => 2]);
    [$user, $student] = enrolledStudentUser($exam->lesson);
    $this->actingAs($user, 'sanctum');

    $this->postJson("/api/me/exams/{$exam->id}/start")->assertOk();
    $this->putJson("/api/me/exams/{$exam->id}/attempt/answers", ['answers' => [['question_id' => $mcq->id, 'answer' => ['key' => 'b']]]])->assertOk();
    $this->postJson("/api/me/exams/{$exam->id}/attempt/submit")->assertOk()
        ->assertJsonPath('data.status', 'submitted')
        ->assertJsonPath('data.auto_score', 2)
        ->assertJsonPath('data.total_score', null);

    $attempt = $exam->attempts()->first();
    $recAnswer = $attempt->answers()->where('exam_question_id', $rec->id)->first();

    // Teacher of this lesson grades the recitation.
    $this->actingAs($exam->lesson->teacher, 'sanctum');
    $this->putJson("/api/exams/{$exam->id}/attempts/{$attempt->id}/grade", ['answers' => [['answer_id' => $recAnswer->id, 'score' => 6, 'grader_note' => 'تلاوة جيدة']]])
        ->assertOk()
        ->assertJsonPath('data.status', 'graded')
        ->assertJsonPath('data.manual_score', 6)
        ->assertJsonPath('data.total_score', 8)
        ->assertJsonPath('data.passed', true);

    expect(\App\Models\AuditLog::where('action', 'exam.graded')->count())->toBe(1);
});

it('stores recorded recitation audio as media on the answer', function () {
    \Illuminate\Support\Facades\Storage::fake('local');
    config(['ahl.media.disk' => 'local']);

    $exam = Exam::factory()->openNow()->create(['total_marks' => 10, 'pass_mark' => 5]);
    $rec = ExamQuestion::factory()->for($exam)->recitation()->create(['marks' => 10]);
    [$user] = enrolledStudentUser($exam->lesson);
    $this->actingAs($user, 'sanctum');
    $this->postJson("/api/me/exams/{$exam->id}/start")->assertOk();

    $file = \Illuminate\Http\UploadedFile::fake()->create('recitation.webm', 200, 'audio/webm');
    $this->post("/api/me/exams/{$exam->id}/attempt/answers/{$rec->id}/audio", ['audio' => $file], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonStructure(['answer_id', 'audio_media_id']);

    expect(\App\Models\Media::where('collection', 'recitation')->count())->toBe(1);
});

it('does not let a student see another student\'s attempt or a teacher grade another teacher\'s exam', function () {
    $exam = objectiveExam();
    [$user1] = enrolledStudentUser($exam->lesson);
    [$user2] = enrolledStudentUser($exam->lesson);

    $this->actingAs($user1, 'sanctum')->postJson("/api/me/exams/{$exam->id}/start")->assertOk();
    $attempt = ExamAttempt::first();

    $this->actingAs($user2, 'sanctum')->getJson("/api/exams/{$exam->id}/attempts/{$attempt->id}")->assertForbidden();
    $this->actingAs($user1, 'sanctum')->getJson("/api/exams/{$exam->id}/attempts/{$attempt->id}")->assertOk();

    $otherTeacher = User::factory()->create();
    $otherTeacher->assignRole('teacher');
    $this->actingAs($otherTeacher, 'sanctum');
    $this->getJson("/api/exams/{$exam->id}")->assertForbidden();
    $this->putJson("/api/exams/{$exam->id}/attempts/{$attempt->id}/grade", ['answers' => [['answer_id' => 1, 'score' => 1]]])->assertForbidden();
    $this->getJson('/api/exams')->assertOk()->assertJsonCount(0, 'data');

    $this->actingAs($exam->lesson->teacher, 'sanctum')->getJson('/api/exams')->assertOk()->assertJsonCount(1, 'data');
});

it('lists the student\'s exams grouped by state', function () {
    $exam = objectiveExam();
    [$user] = enrolledStudentUser($exam->lesson);
    Exam::factory()->published()->create(['lesson_id' => $exam->lesson_id, 'opens_at' => now()->addDays(2), 'closes_at' => now()->addDays(2)->addHours(2)]);

    $this->actingAs($user, 'sanctum')->getJson('/api/me/exams')->assertOk()
        ->assertJsonCount(1, 'open')->assertJsonCount(1, 'upcoming')->assertJsonCount(0, 'finished');
});

it('lets a guardian sit the online exam for their own child, but never for another child', function () {
    $exam = objectiveExam();
    $guardian = User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    $child = Student::factory()->create(['guardian_user_id' => $guardian->id]);
    LessonStudent::create(['lesson_id' => $exam->lesson_id, 'student_id' => $child->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);
    $other = Student::factory()->create();
    LessonStudent::create(['lesson_id' => $exam->lesson_id, 'student_id' => $other->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);
    $this->actingAs($guardian, 'sanctum');

    $attempt = $this->postJson("/api/me/exams/{$exam->id}/start", ['student_id' => $child->id])->assertOk()->json('data');
    expect($attempt['student_id'])->toBe($child->id);
    $q = collect($attempt['questions'])->firstWhere('type', 'true_false');
    $this->putJson("/api/me/exams/{$exam->id}/attempt/answers", ['student_id' => $child->id, 'answers' => [['question_id' => $q['id'], 'answer' => ['value' => true]]]])->assertOk();
    $this->postJson("/api/me/exams/{$exam->id}/attempt/submit", ['student_id' => $child->id])->assertOk();

    $this->postJson("/api/me/exams/{$exam->id}/start", ['student_id' => $other->id])->assertForbidden();
    $this->postJson("/api/me/exams/{$exam->id}/start")->assertForbidden();
});
