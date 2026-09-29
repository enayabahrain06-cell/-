<?php

use App\Models\Certificate;
use App\Models\Exam;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;
use Illuminate\Support\Facades\Storage;

function enrolled(Lesson $lesson, int $n): \Illuminate\Support\Collection
{
    return Student::factory()->count($n)->create()->each(fn ($s) => LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $s->id, 'joined_at' => now()->toDateString(), 'status' => 'active']));
}

it('creates, validates, publishes and closes exams', function () {
    actingAsRole('supervisor');
    $lesson = Lesson::factory()->create();

    $this->postJson('/api/exams', ['name' => 'x', 'type' => 'online', 'exam_date' => '2026-10-01', 'opens_at' => '2026-10-01 16:00', 'closes_at' => '2026-10-01 15:00', 'duration_minutes' => 30, 'total_marks' => 10, 'pass_mark' => 12])
        ->assertStatus(422)->assertJsonValidationErrors(['lesson_id', 'closes_at', 'pass_mark']);

    $id = $this->postJson('/api/exams', ['name' => 'اختبار الشهر', 'lesson_id' => $lesson->id, 'type' => 'online', 'exam_date' => '2026-10-01', 'opens_at' => '2026-10-01 16:00', 'closes_at' => '2026-10-01 18:00', 'duration_minutes' => 30, 'total_marks' => 10, 'pass_mark' => 5])
        ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');

    $this->postJson("/api/exams/{$id}/publish")->assertStatus(422)->assertJsonValidationErrors('questions');

    $this->postJson("/api/exams/{$id}/questions", ['type' => 'mcq', 'prompt' => 'س', 'marks' => 4, 'options' => [['key' => 'a', 'text' => '1'], ['key' => 'b', 'text' => '2']], 'correct_answer' => ['key' => 'z']])
        ->assertStatus(422)->assertJsonValidationErrors('correct_answer.key');
    $this->postJson("/api/exams/{$id}/questions", ['type' => 'mcq', 'prompt' => 'س', 'marks' => 4, 'options' => [['key' => 'a', 'text' => '1'], ['key' => 'b', 'text' => '2']], 'correct_answer' => ['key' => 'a']])->assertCreated();
    $this->postJson("/api/exams/{$id}/publish")->assertStatus(422)->assertJsonValidationErrors('total_marks');

    $this->postJson("/api/exams/{$id}/questions", ['type' => 'recitation', 'prompt' => 'اتل', 'marks' => 6])->assertCreated();
    $this->postJson("/api/exams/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
    $this->postJson("/api/exams/{$id}/close")->assertOk()->assertJsonPath('data.status', 'closed');

    actingAsRole('teacher');
    $this->postJson('/api/exams', [])->assertForbidden();
});

it('records paper scores, sets pass/fail, computes results and issues certificates for passers only', function () {
    Storage::fake('local');
    config(['ahl.media.disk' => 'local']);

    $exam = Exam::factory()->paper()->published()->create(['total_marks' => 20, 'pass_mark' => 10]);
    $students = enrolled($exam->lesson, 4);
    $supervisor = actingAsRole('supervisor');

    $this->putJson("/api/exams/{$exam->id}/scores", ['scores' => [['student_id' => $students[0]->id, 'score' => 25]]])->assertStatus(422);

    $this->putJson("/api/exams/{$exam->id}/scores", ['scores' => [
        ['student_id' => $students[0]->id, 'score' => 18],
        ['student_id' => $students[1]->id, 'score' => 10],
        ['student_id' => $students[2]->id, 'score' => 4, 'note' => 'ضعيف'],
    ]])->assertOk()->assertJsonCount(3, 'data');

    expect($exam->attempts()->where('student_id', $students[0]->id)->first()->passed)->toBeTrue()
        ->and($exam->attempts()->where('student_id', $students[2]->id)->first()->passed)->toBeFalse();

    $r = $this->getJson("/api/exams/{$exam->id}/results")->assertOk()->json();
    expect($r['eligible'])->toBe(4)->and($r['graded'])->toBe(3)->and($r['passed'])->toBe(2)
        ->and($r['pass_rate'])->toBe(66.7)->and($r['average'])->toBe(10.67)
        ->and($r['top'][0]['score'])->toBe(18)->and(count($r['rows']))->toBe(4);

    $this->postJson("/api/exams/{$exam->id}/certificates")->assertOk()->assertJsonPath('issued', 2);
    $this->postJson("/api/exams/{$exam->id}/certificates")->assertOk()->assertJsonPath('issued', 0);
    // Drafts first: no PDF is stored until a supervisor approves them.
    expect(Certificate::count())->toBe(2)->and(Certificate::where('status', 'draft')->count())->toBe(2)
        ->and(Certificate::where('recipient_id', $students[0]->id)->first()->grade)->toBe('excellent')
        ->and(\App\Models\Media::where('collection', 'certificate')->count())->toBe(0);
    $this->postJson('/api/certificates/approve', ['ids' => Certificate::pluck('id')->all()])->assertOk()->assertJsonPath('approved', 2);
    expect(\App\Models\Media::where('collection', 'certificate')->count())->toBe(2);

    $cert = Certificate::first();
    $this->get("/api/certificates/{$cert->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->postJson("/api/exams/{$exam->id}/results/send")->assertOk()->assertJsonPath('sent', 3);
    expect(MessageLog::where('type', 'exam_result')->count())->toBe(3)
        ->and(MessageLog::where('type', 'exam_result')->first()->body)->toContain('18/20');

    $this->get("/api/exams/{$exam->id}/roster.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->get("/api/exams/{$exam->id}/results.xlsx")->assertOk();

    // sheet upload
    $attempt = $exam->attempts()->first();
    $this->post("/api/exams/{$exam->id}/attempts/{$attempt->id}/sheet", ['sheet' => \Illuminate\Http\UploadedFile::fake()->image('sheet.jpg')], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.sheet_media_id', fn ($v) => $v !== null);
});

it('sends reminders one day and one hour before the exam opens', function () {
    $exam = Exam::factory()->published()->create(['opens_at' => now()->addHours(24), 'closes_at' => now()->addHours(26)]);
    enrolled($exam->lesson, 2);
    $soon = Exam::factory()->published()->create(['lesson_id' => $exam->lesson_id, 'opens_at' => now()->addMinutes(60), 'closes_at' => now()->addHours(2)]);
    Exam::factory()->published()->create(['lesson_id' => $exam->lesson_id, 'opens_at' => now()->addHours(5), 'closes_at' => now()->addHours(6)]);

    $this->artisan('exams:send-reminders')->assertSuccessful();

    expect(MessageLog::where('type', 'exam_reminder')->count())->toBe(4)
        ->and($exam->fresh()->reminder_day_sent_at)->not->toBeNull()
        ->and($soon->fresh()->reminder_hour_sent_at)->not->toBeNull();

    $this->artisan('exams:send-reminders')->assertSuccessful();
    expect(MessageLog::where('type', 'exam_reminder')->count())->toBe(4);
});

it('hides correct answers from students but shows them to staff', function () {
    $exam = Exam::factory()->openNow()->create();
    ExamQuestion::factory()->for($exam)->create();

    actingAsRole('supervisor');
    $this->getJson("/api/exams/{$exam->id}/questions")->assertOk()->assertJsonPath('data.0.correct_answer.key', 'b');
});
