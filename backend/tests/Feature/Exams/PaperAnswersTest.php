<?php

use App\Models\Exam;
use App\Models\LessonStudent;
use App\Models\Student;

/** Paper exam (total 20, pass 12) with one question of every type: 4 + 4 + 4 + 4 + recitation 4. */
function paperExamWithQuestions(array $attributes = []): Exam
{
    $exam = Exam::factory()->paper()->create($attributes + ['total_marks' => 20, 'pass_mark' => 12]);
    $exam->questions()->createMany([
        ['type' => 'mcq', 'prompt' => 'كم عدد آيات سورة النبأ؟', 'options' => [['key' => 'a', 'text' => '٣٠'], ['key' => 'b', 'text' => '٤٠']], 'correct_answer' => ['key' => 'b'], 'marks' => 4, 'sort_order' => 1],
        ['type' => 'true_false', 'prompt' => 'سورة النبأ مكية', 'correct_answer' => ['value' => true], 'marks' => 4, 'sort_order' => 2],
        ['type' => 'complete_verse', 'prompt' => 'أكمل: عَمَّ يَتَسَاءَلُونَ', 'correct_answer' => ['text' => 'عن النبإ العظيم', 'alternatives' => []], 'marks' => 4, 'sort_order' => 3],
        ['type' => 'order_verses', 'prompt' => 'رتّب الآيات', 'options' => [['key' => 'a', 'text' => 'الأولى'], ['key' => 'b', 'text' => 'الثانية']], 'correct_answer' => ['order' => ['a', 'b']], 'marks' => 4, 'sort_order' => 4],
        ['type' => 'recitation', 'prompt' => 'اتلُ الآيات ١-٥', 'marks' => 4, 'sort_order' => 5],
    ]);

    return $exam->fresh();
}

function enrolStudent(Exam $exam): Student
{
    $student = Student::factory()->create();
    LessonStudent::create(['lesson_id' => $exam->lesson_id, 'student_id' => $student->id, 'joined_at' => now()->toDateString(), 'status' => 'active']);

    return $student;
}

/** @return array<int, array{question_id:int, answer?:array|null, score?:int}> answers for questions in sort order */
function answersFor(Exam $exam, array $byPosition): array
{
    return $exam->questions->values()->map(fn ($q, $i) => ['question_id' => $q->id] + ($byPosition[$i] ?? []))->all();
}

it('grades a paper exam from the answers the teacher enters, and regrades on re-entry', function () {
    $exam = paperExamWithQuestions();
    $student = enrolStudent($exam);
    actingAsRole('supervisor');

    // mcq right, t/f right, verse right after normalisation (tashkeel ignored), order wrong, recitation 3 of 4.
    $res = $this->putJson("/api/exams/{$exam->id}/students/{$student->id}/answers", ['answers' => answersFor($exam, [
        ['answer' => ['key' => 'b']],
        ['answer' => ['value' => true]],
        ['answer' => ['text' => 'عَنِ النَّبَإِ الْعَظِيمِ']],
        ['answer' => ['order' => ['b', 'a']]],
        ['score' => 3],
    ])])->assertOk()
        ->assertJsonPath('data.status', 'graded')
        ->assertJsonPath('data.auto_score', 12)
        ->assertJsonPath('data.manual_score', 3)
        ->assertJsonPath('data.total_score', 15)
        ->assertJsonPath('data.passed', true);

    $answers = collect($res->json('data.answers'))->keyBy('question_id');
    expect($answers)->toHaveCount(5)
        ->and($answers[$exam->questions[3]->id]['is_correct'])->toBeFalse()
        ->and($answers[$exam->questions[4]->id]['score'])->toBe(3);

    // Re-entry: the teacher corrects the order answer and leaves the MCQ blank; recitation score is capped at its marks.
    $this->putJson("/api/exams/{$exam->id}/students/{$student->id}/answers", ['answers' => answersFor($exam, [
        ['answer' => null],
        ['answer' => ['value' => true]],
        ['answer' => ['text' => 'عن النبإ العظيم']],
        ['answer' => ['order' => ['a', 'b']]],
        ['score' => 99],
    ])])->assertOk()
        ->assertJsonPath('data.auto_score', 12)
        ->assertJsonPath('data.manual_score', 4)
        ->assertJsonPath('data.total_score', 16);

    expect($exam->attempts()->count())->toBe(1);

    // Results and the overview pick up the computed score like any graded attempt.
    $this->getJson("/api/exams/{$exam->id}/results")->assertOk()->assertJsonPath('graded', 1)->assertJsonPath('rows.0.score', 16);
});

it('keeps the typed-total entry for paper exams without questions and refuses it once there is a question paper', function () {
    $exam = paperExamWithQuestions();
    $student = enrolStudent($exam);
    actingAsRole('supervisor');

    $this->putJson("/api/exams/{$exam->id}/scores", ['scores' => [['student_id' => $student->id, 'score' => 10]]])
        ->assertStatus(422)->assertJsonValidationErrors('scores');

    $plain = Exam::factory()->paper()->create(['total_marks' => 20, 'pass_mark' => 10]);
    $other = enrolStudent($plain);
    $this->putJson("/api/exams/{$plain->id}/scores", ['scores' => [['student_id' => $other->id, 'score' => 10]]])->assertOk();
    // …and answer entry needs questions.
    $this->putJson("/api/exams/{$plain->id}/students/{$other->id}/answers", ['answers' => []])
        ->assertStatus(422)->assertJsonValidationErrors('answers');
});

it('refuses answer entry for online exams, students outside the circle, and staff who cannot grade the exam', function () {
    $exam = paperExamWithQuestions();
    $student = enrolStudent($exam);
    $outsider = Student::factory()->create();
    actingAsRole('supervisor');

    $this->putJson("/api/exams/{$exam->id}/students/{$outsider->id}/answers", ['answers' => answersFor($exam, [])])
        ->assertStatus(422)->assertJsonValidationErrors('student');

    $online = Exam::factory()->create();
    $this->putJson("/api/exams/{$online->id}/students/{$student->id}/answers", ['answers' => []])
        ->assertStatus(422)->assertJsonValidationErrors('exam');

    actingAsRole('teacher'); // not this circle's teacher
    $this->putJson("/api/exams/{$exam->id}/students/{$student->id}/answers", ['answers' => answersFor($exam, [])])->assertForbidden();
    $this->get("/api/exams/{$exam->id}/papers.pdf")->assertForbidden();
});

it('publishes a paper exam with a question paper only when its question marks add up to the total', function () {
    $exam = paperExamWithQuestions(['total_marks' => 25, 'pass_mark' => 12]);
    actingAsRole('supervisor');

    $this->postJson("/api/exams/{$exam->id}/publish")->assertStatus(422)->assertJsonValidationErrors('total_marks');

    $exam->update(['total_marks' => 20]);
    $this->postJson("/api/exams/{$exam->id}/publish")->assertOk()->assertJsonPath('data.status', 'published');

    // A paper exam without questions still publishes as before (typed totals).
    $plain = Exam::factory()->paper()->create();
    $this->postJson("/api/exams/{$plain->id}/publish")->assertOk();
});

it('prints the question paper, one student paper and every student paper', function () {
    $exam = paperExamWithQuestions();
    $a = enrolStudent($exam);
    $b = enrolStudent($exam);
    actingAsRole('supervisor');

    $this->get("/api/exams/{$exam->id}/question-paper.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    // No graded attempts yet: nothing to print.
    $this->get("/api/exams/{$exam->id}/papers.pdf")->assertNotFound();

    foreach ([$a, $b] as $s) {
        $this->putJson("/api/exams/{$exam->id}/students/{$s->id}/answers", ['answers' => answersFor($exam, [['answer' => ['key' => 'a']]])])->assertOk();
    }
    $attempt = $exam->attempts()->where('student_id', $a->id)->first();

    $this->get("/api/exams/{$exam->id}/attempts/{$attempt->id}/paper.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $all = $this->get("/api/exams/{$exam->id}/papers.pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($all->getContent(), 0, 4))->toBe('%PDF');

    // A different exam's attempt is not reachable through this exam.
    $other = paperExamWithQuestions();
    $this->get("/api/exams/{$other->id}/attempts/{$attempt->id}/paper.pdf")->assertNotFound();
    // Online exams without questions have no question paper.
    $this->get('/api/exams/'.Exam::factory()->create()->id.'/question-paper.pdf')->assertNotFound();
});
