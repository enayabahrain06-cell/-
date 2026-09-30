<?php

use App\Models\AcademicTerm;
use App\Models\Division;
use App\Models\Evaluation;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationScore;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\LevelSubject;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => today()->subWeeks(3)->toDateString(), 'end_date' => today()->addMonths(3)->toDateString()]);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1']);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id]);
    $this->teacher = User::factory()->role('teacher')->create();
    $this->lesson = Lesson::factory()->create(['package_id' => $this->pkg->id, 'level_id' => $this->level->id, 'teacher_id' => $this->teacher->id]);
    $this->session = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->toDateString()]);
    $this->quran = Subject::quranId();
    $this->fiqh = Subject::create(['name_ar' => 'الفقه', 'name_en' => 'Fiqh', 'code' => 'fiqh'])->id;
    LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'subject_id' => $this->fiqh]);
    $this->students = Student::factory()->count(3)->create();
    foreach ($this->students as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
    }
});

function quranScores(int $m, int $t, int $r, int $b): array
{
    return ['memorization' => $m, 'tajweed' => $t, 'revision' => $r, 'behavior' => $b];
}

it('seeds the four Quran system criteria and keeps them renamable only', function () {
    $system = EvaluationCriterion::where('subject_id', $this->quran)->ordered()->get();
    expect($system->pluck('key')->all())->toBe(['memorization', 'tajweed', 'revision', 'behavior'])
        ->and($system->pluck('name_ar')->all())->toBe(['الحفظ', 'التجويد', 'المراجعة', 'السلوك'])
        ->and($system->every(fn ($c) => $c->is_system && $c->max_score === 10))->toBeTrue();

    actingAsRole('teacher');
    $this->postJson('/api/evaluation-criteria', ['subject_id' => $this->fiqh, 'name_ar' => 'الفهم', 'name_en' => 'Understanding'])->assertForbidden();

    actingAsRole('supervisor');
    $tajweed = $system[1];
    $this->putJson("/api/evaluation-criteria/{$tajweed->id}", ['name_ar' => 'أحكام التجويد', 'name_en' => 'Tajweed rules'])->assertOk();
    expect($tajweed->fresh()->name_ar)->toBe('أحكام التجويد');
    $this->putJson("/api/evaluation-criteria/{$tajweed->id}", ['is_active' => false])->assertJsonValidationErrors('is_active');
    $this->putJson("/api/evaluation-criteria/{$tajweed->id}", ['max_score' => 20])->assertJsonValidationErrors('max_score');
    $this->deleteJson("/api/evaluation-criteria/{$tajweed->id}")->assertJsonValidationErrors('criterion');

    $this->postJson('/api/evaluation-criteria/reorder', ['subject_id' => $this->quran, 'ids' => $system->pluck('id')->reverse()->values()->all()])->assertOk();
    expect(EvaluationCriterion::where('subject_id', $this->quran)->ordered()->first()->key)->toBe('behavior');

    $id = $this->postJson('/api/evaluation-criteria', ['subject_id' => $this->fiqh, 'name_ar' => 'الفهم', 'name_en' => 'Understanding', 'max_score' => 20, 'weight' => 2])
        ->assertCreated()->json('data.id');
    expect($this->getJson("/api/evaluation-criteria?subject_id={$this->fiqh}")->json('data.0'))->toMatchArray(['id' => $id, 'max_score' => 20, 'is_system' => false, 'in_use' => false]);
    $this->deleteJson("/api/evaluation-criteria/{$id}")->assertOk();
});

it('keeps the Quran sheet as before and dual-writes the scores; readers only average Quran', function () {
    $this->actingAs($this->teacher, 'sanctum');
    [$a, $b] = $this->students;
    $sheet = $this->getJson("/api/sessions/{$this->session->id}/evaluations")->assertOk();
    expect($sheet->json('subject.id'))->toBe($this->quran)
        ->and($sheet->json('criteria.*.key'))->toBe(['memorization', 'tajweed', 'revision', 'behavior'])
        ->and(collect($sheet->json('subjects'))->pluck('id')->all())->toContain($this->quran, $this->fiqh); // the class teacher reaches every subject

    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $a->id] + quranScores(9, 8, 7, 10)]])->assertOk();
    $e = Evaluation::where('student_id', $a->id)->sole();
    $byKey = EvaluationScore::where('evaluation_id', $e->id)->with('criterion')->get()->mapWithKeys(fn ($s) => [$s->criterion->key => $s->score])->all();
    expect($e->memorization)->toBe(9)->and($byKey)->toBe(['memorization' => 9, 'tajweed' => 8, 'revision' => 7, 'behavior' => 10]);

    // A custom Quran criterion is stored in scores only.
    $voice = EvaluationCriterion::create(['subject_id' => $this->quran, 'name_ar' => 'الصوت', 'name_en' => 'Voice', 'max_score' => 5, 'sort' => 9]);
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $a->id] + quranScores(9, 8, 7, 10) + ['scores' => [$voice->id => 6]]]])
        ->assertJsonValidationErrors("entries.0.scores.{$voice->id}");
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $a->id] + quranScores(9, 8, 7, 10) + ['scores' => [$voice->id => 4]]]])->assertOk();
    expect(EvaluationScore::where('evaluation_id', $e->id)->where('criterion_id', $voice->id)->value('score'))->toBe(4);

    // A fiqh evaluation: scores only, every active criterion required, no Quran columns, no ledger, no difficulty suggestion.
    $understanding = EvaluationCriterion::create(['subject_id' => $this->fiqh, 'name_ar' => 'الفهم', 'name_en' => 'Understanding', 'max_score' => 20]);
    $homework = EvaluationCriterion::create(['subject_id' => $this->fiqh, 'name_ar' => 'الواجب', 'name_en' => 'Homework', 'max_score' => 10]);
    $fiqhSheet = $this->getJson("/api/sessions/{$this->session->id}/evaluations?subject_id={$this->fiqh}")->assertOk();
    expect($fiqhSheet->json('criteria.*.id'))->toBe([$understanding->id, $homework->id]);
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['subject_id' => $this->fiqh, 'entries' => [['student_id' => $a->id, 'scores' => [$understanding->id => 18]]]])
        ->assertJsonValidationErrors("entries.0.scores.{$homework->id}");
    $res = $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['subject_id' => $this->fiqh, 'entries' => [
        ['student_id' => $a->id, 'scores' => [$understanding->id => 2, $homework->id => 1]],
        ['student_id' => $b->id, 'scores' => [$understanding->id => 20, $homework->id => 10], 'note' => 'ممتاز'],
    ]])->assertOk();
    expect($res->json('suggested_issues'))->toBe([]);
    $fiqhEval = Evaluation::where('student_id', $a->id)->where('subject_id', $this->fiqh)->sole();
    expect($fiqhEval->memorization)->toBeNull()
        ->and(Evaluation::where('student_id', $a->id)->count())->toBe(2) // the Quran one is untouched
        ->and(collect($this->getJson("/api/sessions/{$this->session->id}/evaluations?subject_id={$this->fiqh}")->json('data'))->firstWhere('student.id', $a->id)['evaluation']['scores'])
        ->toEqual([$understanding->id => 2, $homework->id => 1]);

    // Quran readers: the list, the profile summary and the honor board ignore the fiqh row.
    expect($this->getJson("/api/evaluations?student_id={$a->id}")->json('data.*.id'))->toBe([$e->id])
        ->and($this->getJson("/api/evaluations?student_id={$a->id}&subject_id={$this->fiqh}")->json('data.*.id'))->toBe([$fiqhEval->id]);
    $summary = app(\App\Services\Evaluation\EvaluationService::class)->summary($a->fresh());
    expect($summary['latest_daily'])->toHaveCount(1)->and($summary['latest_daily'][0]['total'])->toBe(34);
    expect(StudentIssue::count())->toBe(0);
});

it('lets a subject teacher evaluate only the subject they teach and refuses subjects outside the class level', function () {
    $fiqhTeacher = User::factory()->role('teacher')->create();
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'lesson_id' => $this->lesson->id, 'weekday' => 'sat', 'start_time' => '17:00:00', 'end_time' => '17:45:00', 'subject_id' => $this->fiqh, 'teacher_id' => $fiqhTeacher->id]);
    $c = EvaluationCriterion::create(['subject_id' => $this->fiqh, 'name_ar' => 'الفهم', 'name_en' => 'Understanding']);

    $this->actingAs($fiqhTeacher, 'sanctum');
    $this->getJson("/api/sessions/{$this->session->id}/evaluations")->assertForbidden();
    expect($this->getJson("/api/sessions/{$this->session->id}/evaluation-subjects")->assertOk()->json('data.*.id'))->toBe([$this->fiqh]);
    $sheet = $this->getJson("/api/sessions/{$this->session->id}/evaluations?subject_id={$this->fiqh}")->assertOk();
    expect($sheet->json('subjects.*.id'))->toBe([$this->fiqh]);
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['subject_id' => $this->fiqh, 'entries' => [['student_id' => $this->students[0]->id, 'scores' => [$c->id => 7]]]])->assertOk();
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $this->students[0]->id] + quranScores(5, 5, 5, 5)]])->assertForbidden();

    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->getJson("/api/sessions/{$this->session->id}/evaluation-subjects")->assertForbidden();

    $art = Subject::create(['name_ar' => 'الخط', 'name_en' => 'Calligraphy', 'code' => 'art'])->id;
    actingAsRole('supervisor');
    $this->getJson("/api/sessions/{$this->session->id}/evaluations?subject_id={$art}")->assertStatus(422);
    $this->postJson("/api/lessons/{$this->lesson->id}/evaluations/monthly", ['subject_id' => $art, 'period' => now()->format('Y-m'), 'entries' => [['student_id' => $this->students[0]->id, 'scores' => []]]])
        ->assertStatus(422);
});

it('evaluates the students of one division and shows the division results', function () {
    [$a, $b, $c] = $this->students;
    $division = Division::create(['academic_term_id' => $this->term->id, 'lesson_id' => $this->lesson->id, 'name' => 'المجموعة أ']);
    $division->students()->sync([$a->id, $b->id]);

    $this->actingAs($this->teacher, 'sanctum');
    $sheet = $this->getJson("/api/sessions/{$this->session->id}/evaluations?division_id={$division->id}")->assertOk();
    expect(collect($sheet->json('data'))->pluck('student.id')->sort()->values()->all())->toBe([$a->id, $b->id])
        ->and($sheet->json('division.name'))->toBe('المجموعة أ');

    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['division_id' => $division->id, 'entries' => [['student_id' => $c->id] + quranScores(8, 8, 8, 8)]])
        ->assertJsonValidationErrors('entries');
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['division_id' => $division->id, 'entries' => [
        ['student_id' => $a->id] + quranScores(10, 8, 6, 10),
        ['student_id' => $b->id] + quranScores(6, 6, 6, 6),
    ]])->assertOk();
    expect(Evaluation::where('division_id', $division->id)->count())->toBe(2);

    $res = $this->getJson("/api/divisions/{$division->id}/evaluations")->assertOk();
    $tajweed = EvaluationCriterion::where('subject_id', $this->quran)->where('key', 'tajweed')->value('id');
    $rowA = collect($res->json('data'))->firstWhere('student.id', $a->id);
    expect($rowA['count'])->toBe(1)->and($rowA['averages'][$tajweed])->toEqual(8)
        ->and($res->json("averages.{$tajweed}"))->toEqual(7)
        ->and($res->json('criteria'))->toHaveCount(4);

    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->getJson("/api/divisions/{$division->id}/evaluations")->assertForbidden();
});

it('copies existing scores and rolls its migrations back and forth', function () {
    $e = Evaluation::create(['student_id' => $this->students[0]->id, 'lesson_id' => $this->lesson->id, 'type' => 'daily', 'evaluated_on' => today()->toDateString()] + quranScores(7, 6, 5, 4));
    $this->students[1]->update(['notes' => 'يحتاج متابعة في المراجعة']);

    for ($i = 0; $i < 40 && Schema::hasTable('notes'); $i++) {
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    }
    expect(Schema::hasTable('evaluation_scores'))->toBeFalse()->and(Schema::hasColumn('evaluations', 'division_id'))->toBeFalse()
        ->and(Schema::hasTable('divisions'))->toBeFalse()->and(Schema::hasTable('subject_lesson_progress'))->toBeFalse();
    Artisan::call('migrate', ['--force' => true]);

    expect(EvaluationScore::where('evaluation_id', $e->id)->count())->toBe(4)
        ->and(\App\Models\Note::where('student_id', $this->students[1]->id)->where('scope', 'student')->value('body'))->toBe('يحتاج متابعة في المراجعة')
        ->and($this->students[1]->fresh()->notes)->toBe('يحتاج متابعة في المراجعة')
        ->and(Schema::hasColumn('evaluations', 'division_id'))->toBeTrue();
});
