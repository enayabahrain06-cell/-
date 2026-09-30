<?php

use App\Models\AcademicTerm;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\GradeComponent;
use App\Models\GradeEntry;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\LevelSubject;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectLesson;
use App\Models\TimetableSlot;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

function gradesSheetFile(array $rows): UploadedFile
{
    $raw = Excel::raw(new class($rows) implements FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, ExcelFormat::XLSX);

    return UploadedFile::fake()->createWithContent('scores.xlsx', $raw);
}

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => today()->subWeeks(3)->toDateString(), 'end_date' => today()->addMonths(3)->toDateString()]);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1']);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id]);
    $this->classTeacher = User::factory()->role('teacher')->create();
    $this->fiqhTeacher = User::factory()->role('teacher')->create();
    $this->otherTeacher = User::factory()->role('teacher')->create();
    $this->lesson = Lesson::factory()->create(['name' => 'صف أ', 'package_id' => $this->pkg->id, 'level_id' => $this->level->id, 'teacher_id' => $this->classTeacher->id]);
    $this->fiqh = Subject::create(['name_ar' => 'الفقه', 'name_en' => 'Fiqh', 'code' => 'fiqh'])->id;
    $this->seerah = Subject::create(['name_ar' => 'السيرة', 'name_en' => 'Seerah', 'code' => 'seerah'])->id;
    $this->ls = LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'subject_id' => $this->fiqh]);
    $this->lsSeerah = LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'subject_id' => $this->seerah]);
    // The fiqh teacher teaches fiqh in the class; the other teacher only seerah.
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'lesson_id' => $this->lesson->id, 'weekday' => 'sat', 'start_time' => '16:00', 'end_time' => '17:00', 'subject_id' => $this->fiqh, 'teacher_id' => $this->fiqhTeacher->id]);
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->level->id, 'lesson_id' => $this->lesson->id, 'weekday' => 'sat', 'start_time' => '17:00', 'end_time' => '18:00', 'subject_id' => $this->seerah, 'teacher_id' => $this->otherTeacher->id]);
    $this->students = Student::factory()->count(3)->create();
    foreach ($this->students as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
    }
    $this->exam = Exam::factory()->paper()->create(['lesson_id' => $this->lesson->id, 'subject_id' => $this->fiqh, 'total_marks' => 20, 'pass_mark' => 10]);
});

function gradeComp(array $attrs = []): GradeComponent
{
    return GradeComponent::create($attrs + ['level_subject_id' => test()->ls->id, 'name_ar' => 'الواجبات', 'kind' => 'homework', 'max_marks' => 10, 'weight' => 40]);
}

it('defines the grade distribution of a level subject: weights up to 100, exam links, delete refused with grades', function () {
    actingAsRole('teacher');
    $this->getJson('/api/grade-components')->assertForbidden();

    actingAsRole('supervisor');
    $list = $this->getJson('/api/grade-components')->assertOk();
    expect(collect($list->json('level_subjects'))->pluck('id')->all())->toContain($this->ls->id);

    $hw = $this->postJson('/api/grade-components', ['level_subject_id' => $this->ls->id, 'name_ar' => 'الواجبات', 'name_en' => 'Homework', 'kind' => 'homework', 'max_marks' => 10, 'weight' => 40])
        ->assertCreated()->assertJsonPath('summary.weight_total', 40);
    expect($hw->json('summary.warning'))->not->toBeNull();

    // Weights may not pass 100.
    $this->postJson('/api/grade-components', ['level_subject_id' => $this->ls->id, 'name_ar' => 'المشاركة', 'kind' => 'participation', 'max_marks' => 10, 'weight' => 70])->assertJsonValidationErrors('weight');

    // An exam of another subject does not fit; the class's fiqh exam does and gives its total as full marks.
    $seerahExam = Exam::factory()->paper()->create(['lesson_id' => $this->lesson->id, 'subject_id' => $this->seerah]);
    $this->postJson('/api/grade-components', ['level_subject_id' => $this->ls->id, 'name_ar' => 'الامتحان النهائي', 'kind' => 'exam', 'weight' => 60, 'exam_id' => $seerahExam->id])->assertJsonValidationErrors('exam_id');
    $ex = $this->postJson('/api/grade-components', ['level_subject_id' => $this->ls->id, 'name_ar' => 'الامتحان النهائي', 'kind' => 'exam', 'weight' => 60, 'exam_id' => $this->exam->id])
        ->assertCreated()->assertJsonPath('data.max_marks', 20)->assertJsonPath('summary.weight_total', 100)->assertJsonPath('summary.warning', null);

    $detail = $this->getJson("/api/grade-components?level_subject_id={$this->ls->id}")->assertOk();
    expect($detail->json('components.*.id'))->toBe([$hw->json('data.id'), $ex->json('data.id')])
        ->and(collect($detail->json('exams'))->firstWhere('id', $this->exam->id)['component_id'])->toBe($ex->json('data.id'));

    // Reorder, then delete: refused while a student has a score.
    $this->postJson('/api/grade-components/reorder', ['level_subject_id' => $this->ls->id, 'ids' => [$ex->json('data.id'), $hw->json('data.id')]])->assertOk();
    expect(GradeComponent::where('level_subject_id', $this->ls->id)->ordered()->first()->id)->toBe($ex->json('data.id'));
    GradeEntry::create(['grade_component_id' => $hw->json('data.id'), 'student_id' => $this->students[0]->id, 'score' => 8]);
    $this->deleteJson('/api/grade-components/'.$hw->json('data.id'))->assertJsonValidationErrors('component');
    $this->putJson('/api/grade-components/'.$hw->json('data.id'), ['max_marks' => 5])->assertJsonValidationErrors('max_marks');
    $this->putJson('/api/grade-components/'.$hw->json('data.id'), ['kind' => 'exam'])->assertJsonValidationErrors('kind');
    $this->deleteJson('/api/grade-components/'.$ex->json('data.id'))->assertOk();
    expect(Exam::find($this->exam->id))->not->toBeNull();

    // The level subject cannot be deleted while it has a distribution.
    $this->deleteJson("/api/term-setup/level-subjects/{$this->ls->id}")->assertJsonValidationErrors('level_subject');
});

it('links an exam to a component and its required lessons from the exam form (U10)', function () {
    $c = gradeComp(['kind' => 'exam', 'name_ar' => 'امتحان منتصف الفصل', 'weight' => 50, 'max_marks' => 20]);
    $l1 = SubjectLesson::create(['subject_id' => $this->fiqh, 'level_id' => $this->level->id, 'title' => 'الطهارة']);
    $l2 = SubjectLesson::create(['subject_id' => $this->fiqh, 'level_id' => null, 'title' => 'الصلاة']);
    $other = SubjectLesson::create(['subject_id' => $this->seerah, 'level_id' => null, 'title' => 'الهجرة']);
    actingAsRole('supervisor');

    $opts = $this->getJson("/api/grades/exam-options?lesson_id={$this->lesson->id}")->assertOk();
    expect($opts->json('components.*.id'))->toBe([$c->id])->and(collect($opts->json('lessons'))->pluck('id')->all())->toContain($l1->id, $l2->id, $other->id);

    $base = ['name' => 'امتحان الفقه', 'lesson_id' => $this->lesson->id, 'type' => 'paper', 'exam_date' => today()->toDateString(), 'opens_at' => now()->toIso8601String(),
        'closes_at' => now()->addHour()->toIso8601String(), 'duration_minutes' => 30, 'total_marks' => 25, 'pass_mark' => 12];
    // No subject sent: the component names it (fiqh), so a seerah lesson does not fit.
    $this->postJson('/api/exams', $base + ['grade_component_id' => $c->id, 'required_lesson_ids' => [$other->id]])->assertJsonValidationErrors('required_lesson_ids');
    expect(Exam::where('name', 'امتحان الفقه')->exists())->toBeFalse(); // rolled back

    $id = $this->postJson('/api/exams', $base + ['grade_component_id' => $c->id, 'required_lesson_ids' => [$l1->id, $l2->id]])->assertCreated()->json('data.id');
    $exam = Exam::find($id);
    expect($exam->subject_id)->toBe($this->fiqh)->and($c->fresh()->exam_id)->toBe($id)->and((float) $c->fresh()->max_marks)->toBe(25.0);
    $show = $this->getJson("/api/exams/{$id}")->assertOk();
    expect($show->json('data.grade_component.id'))->toBe($c->id)->and($show->json('data.required_lessons.*.id'))->toBe([$l1->id, $l2->id]);

    // The component already has an exam: another exam cannot take it.
    $this->postJson('/api/exams', $base + ['name' => 'آخر', 'grade_component_id' => $c->id])->assertJsonValidationErrors('grade_component_id');
    // Unlink from the form.
    $this->putJson("/api/exams/{$id}", ['grade_component_id' => null])->assertOk();
    expect($c->fresh()->exam_id)->toBeNull();

    // Placement tests take no links.
    $placement = Exam::factory()->placement($this->pkg)->create(['status' => 'draft']);
    $this->putJson("/api/exams/{$placement->id}", ['grade_component_id' => $c->id])->assertJsonValidationErrors('grade_component_id');
    $this->getJson("/api/exams/{$placement->id}/required-lessons")->assertNotFound();

    // الدروس المطلوبة screen: read, then save.
    $screen = $this->getJson("/api/exams/{$id}/required-lessons")->assertOk();
    expect($screen->json('available.*.id'))->toBe([$l1->id, $l2->id])->and($screen->json('selected'))->toBe([$l1->id, $l2->id]);
    $this->putJson("/api/exams/{$id}/required-lessons", ['ids' => [$l2->id]])->assertOk()->assertJsonPath('selected', [$l2->id]);
    $this->putJson("/api/exams/{$id}/required-lessons", ['ids' => [$other->id]])->assertJsonValidationErrors('required_lesson_ids');

    $this->actingAs($this->fiqhTeacher, 'sanctum');
    $this->getJson("/api/exams/{$id}/required-lessons")->assertOk()->assertJsonPath('can_manage', false);
    $this->putJson("/api/exams/{$id}/required-lessons", ['ids' => []])->assertForbidden();
});

it('uploads paper exam scores from Excel: template, preview with validation, commit through the scores endpoint', function () {
    [$a, $b, $c] = $this->students;
    $outsider = Student::factory()->create();
    $this->actingAs($this->otherTeacher, 'sanctum'); // seerah only: not the exam's subject
    $this->get("/api/exams/{$this->exam->id}/scores-template.xlsx")->assertForbidden();

    $this->actingAs($this->fiqhTeacher, 'sanctum');
    $this->get("/api/exams/{$this->exam->id}/scores-template.xlsx")->assertOk()->assertHeader('content-disposition');

    $file = gradesSheetFile([
        ['student_no', 'name', 'score'],
        [$a->student_no, $a->full_name, 18],
        [$b->student_no, $b->full_name, 25],
        ['NOPE', 'y', 'abc'],
        [$outsider->student_no, 'x', 10],
        [$a->student_no, $a->full_name, 12],
        ['', '', ''],
        [$c->student_no, $c->full_name, ''],
    ]);
    $p = $this->postJson("/api/exams/{$this->exam->id}/scores-preview", ['file' => $file])->assertOk()
        ->assertJsonPath('valid', 1)->assertJsonPath('invalid', 4)->assertJsonPath('empty', 1);
    $rows = collect($p->json('rows'));
    expect($rows[0])->toMatchArray(['student_id' => $a->id, 'score' => 18, 'status' => 'ok'])
        ->and($rows[1]['errors'])->toHaveKey('score')
        ->and($rows[2]['errors'])->toHaveKeys(['student_no', 'score'])
        ->and($rows[3]['errors'])->toHaveKey('student_no')
        ->and($rows[4]['errors']['student_no'])->toContain('2');

    // Commit the valid rows through the existing paper scores logic.
    $this->putJson("/api/exams/{$this->exam->id}/scores", ['scores' => [['student_id' => $a->id, 'score' => 18]]])->assertOk();
    expect(ExamAttempt::where('exam_id', $this->exam->id)->where('student_id', $a->id)->value('total_score'))->toBe(18);

    // An online exam has no template.
    $online = Exam::factory()->create(['lesson_id' => $this->lesson->id, 'subject_id' => $this->fiqh]);
    $this->get("/api/exams/{$online->id}/scores-template.xlsx")->assertStatus(422);
});

it('keeps the gradebook: entries for non-exam components, exam scores read from attempts, weighted totals', function () {
    [$a, $b, $c] = $this->students;
    $hw = gradeComp(['weight' => 40, 'max_marks' => 10]);
    $ex = gradeComp(['kind' => 'exam', 'name_ar' => 'الامتحان', 'weight' => 60, 'max_marks' => 20, 'exam_id' => $this->exam->id]);
    ExamAttempt::create(['exam_id' => $this->exam->id, 'student_id' => $a->id, 'status' => 'graded', 'auto_score' => 0, 'manual_score' => 15, 'total_score' => 15, 'passed' => true]);

    // A teacher of another subject cannot record fiqh; the fiqh teacher can.
    $this->actingAs($this->otherTeacher, 'sanctum');
    $this->putJson('/api/grades/book', ['lesson_id' => $this->lesson->id, 'grade_component_id' => $hw->id, 'entries' => [['student_id' => $a->id, 'score' => 8]]])->assertForbidden();
    $book = $this->getJson("/api/grades/book?lesson_id={$this->lesson->id}&mode=record")->assertOk();
    expect($book->json('subjects.*.id'))->toBe([$this->seerah]);

    $this->actingAs($this->fiqhTeacher, 'sanctum');
    $this->putJson('/api/grades/book', ['lesson_id' => $this->lesson->id, 'grade_component_id' => $hw->id, 'entries' => [['student_id' => $a->id, 'score' => 11]]])->assertJsonValidationErrors('entries.0.score');
    $this->putJson('/api/grades/book', ['lesson_id' => $this->lesson->id, 'grade_component_id' => $ex->id, 'entries' => [['student_id' => $a->id, 'score' => 5]]])->assertJsonValidationErrors('grade_component_id');
    $outsider = Student::factory()->create();
    $this->putJson('/api/grades/book', ['lesson_id' => $this->lesson->id, 'grade_component_id' => $hw->id, 'entries' => [['student_id' => $outsider->id, 'score' => 5]]])->assertJsonValidationErrors('entries.0.student_id');

    $saved = $this->putJson('/api/grades/book', ['lesson_id' => $this->lesson->id, 'grade_component_id' => $hw->id, 'entries' => [
        ['student_id' => $a->id, 'score' => 8], ['student_id' => $b->id, 'score' => 5, 'notes' => 'متأخر'], ['student_id' => $c->id, 'score' => null],
    ]])->assertOk()->assertJsonPath('saved', 2);
    expect(GradeEntry::where('grade_component_id', $hw->id)->count())->toBe(2)
        ->and(GradeEntry::where('student_id', $a->id)->value('entered_by'))->toBe($this->fiqhTeacher->id);

    $view = $this->getJson("/api/grades/book?lesson_id={$this->lesson->id}&subject_id={$this->fiqh}")->assertOk()->assertJsonPath('can_record', true);
    $byStudent = collect($view->json('students'))->keyBy('student.id');
    // a: 8/10 × 40 + 15/20 × 60 = 32 + 45 = 77; b: 5/10 × 40 = 20; c: 0
    expect($byStudent[$a->id]['total'])->toEqual(77)->and($byStudent[$a->id]['complete'])->toBeTrue()
        ->and($byStudent[$a->id]['cells'][$ex->id])->toMatchArray(['score' => 15, 'max' => 20, 'source' => 'exam'])
        ->and($byStudent[$b->id]['total'])->toEqual(20)->and($byStudent[$b->id]['complete'])->toBeFalse()
        ->and($byStudent[$c->id]['total'])->toEqual(0)
        ->and($view->json("averages.components.{$hw->id}"))->toEqual(6.5)
        ->and($view->json('averages.total'))->toEqual(32.33);

    // The exam score is read, never copied: changing the attempt changes the book.
    ExamAttempt::where('student_id', $a->id)->update(['total_score' => 20]);
    $again = collect($this->getJson("/api/grades/book?lesson_id={$this->lesson->id}&subject_id={$this->fiqh}")->json('students'))->keyBy('student.id');
    expect($again[$a->id]['total'])->toEqual(92)->and(GradeEntry::where('grade_component_id', $ex->id)->exists())->toBeFalse();

    // Per-student view and the Excel downloads (one subject, the whole class).
    $one = $this->getJson("/api/grades/students/{$a->id}")->assertOk();
    expect(collect($one->json('subjects'))->firstWhere('subject.id', $this->fiqh)['total'])->toEqual(92);
    $this->get("/api/grades/book.xlsx?lesson_id={$this->lesson->id}&subject_id={$this->fiqh}")->assertOk();
    $this->get("/api/grades/book.xlsx?lesson_id={$this->lesson->id}")->assertOk();

    // A student or a user without grades.view is refused.
    actingAsRole('student');
    $this->getJson("/api/grades/book?lesson_id={$this->lesson->id}")->assertForbidden();
});

it('monitors grade submission per class, subject and component with the responsible teachers', function () {
    [$a] = $this->students;
    $hw = gradeComp(['weight' => 40]);
    gradeComp(['kind' => 'exam', 'name_ar' => 'الامتحان', 'weight' => 60, 'max_marks' => 20, 'exam_id' => $this->exam->id]);
    GradeEntry::create(['grade_component_id' => $hw->id, 'student_id' => $a->id, 'score' => 7]);

    $this->actingAs($this->fiqhTeacher, 'sanctum');
    $this->getJson('/api/grades/monitor')->assertForbidden();

    actingAsRole('supervisor');
    $r = $this->getJson('/api/grades/monitor')->assertOk();
    $rows = collect($r->json('rows'));
    $hwRow = $rows->firstWhere('component.id', $hw->id);
    expect($hwRow['missing'])->toBe(2)->and($hwRow['students'])->toBe(3)->and(collect($hwRow['teachers'])->pluck('id')->all())->toBe([$this->fiqhTeacher->id]);
    $examRow = $rows->first(fn ($x) => ($x['component']['kind'] ?? null) === 'exam');
    expect($examRow['missing'])->toBe(3)->and($examRow['exam_covers_class'])->toBeTrue();
    // Seerah has no distribution yet: listed with every student missing.
    expect($rows->first(fn ($x) => $x['subject']['id'] === $this->seerah && $x['component'] === null)['missing'])->toBe(3);

    $filtered = $this->getJson("/api/grades/monitor?teacher_id={$this->otherTeacher->id}")->assertOk();
    expect(collect($filtered->json('rows'))->pluck('subject.id')->unique()->values()->all())->toBe([$this->seerah]);
    expect($this->getJson('/api/grades/monitor?only_missing=1')->json('summary.incomplete'))->toBe(3);
});

it('ranks the top students by weighted grade totals on the honor board, with ties', function () {
    [$a, $b, $c] = $this->students;
    $hw = gradeComp(['weight' => 100, 'max_marks' => 10]);
    GradeEntry::create(['grade_component_id' => $hw->id, 'student_id' => $a->id, 'score' => 9]);
    GradeEntry::create(['grade_component_id' => $hw->id, 'student_id' => $b->id, 'score' => 9]);
    GradeEntry::create(['grade_component_id' => $hw->id, 'student_id' => $c->id, 'score' => 6]);
    $sc = GradeComponent::create(['level_subject_id' => $this->lsSeerah->id, 'name_ar' => 'المشاركة', 'kind' => 'participation', 'max_marks' => 10, 'weight' => 100]);
    GradeEntry::create(['grade_component_id' => $sc->id, 'student_id' => $c->id, 'score' => 10]);

    actingAsRole('guardian');
    $this->getJson('/api/honor/top-students')->assertForbidden();

    actingAsRole('supervisor');
    $r = $this->getJson('/api/honor/top-students?subject_id='.$this->fiqh)->assertOk();
    expect(collect($r->json('rows'))->mapWithKeys(fn ($x) => [$x['student']['id'] => [$x['rank'], $x['score']]])->all())
        ->toEqual([$a->id => [1, 90], $b->id => [1, 90], $c->id => [3, 60]])->and($r->json('rows.2.student.id'))->toBe($c->id);
    // Every subject: c averages (60 + 100) / 2 = 80.
    $all = collect($this->getJson("/api/honor/top-students?level_id={$this->level->id}")->json('rows'));
    expect($all->firstWhere('student.id', $c->id)['score'])->toEqual(80);
    // The limit keeps everyone tied with the last place shown.
    expect(count($this->getJson('/api/honor/top-students?subject_id='.$this->fiqh.'&limit=1')->json('rows')))->toBe(2);
    // One board per gender track; a student with no recorded score is not ranked.
    $girl = Student::factory()->female()->create();
    $unscored = Student::factory()->create();
    foreach ([$girl, $unscored] as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
    }
    GradeEntry::create(['grade_component_id' => $hw->id, 'student_id' => $girl->id, 'score' => 10]);
    $boys = collect($this->getJson('/api/honor/top-students?subject_id='.$this->fiqh.'&gender=male')->json('rows'))->pluck('student.id');
    expect($boys->all())->not->toContain($girl->id)->not->toContain($unscored->id);
    expect($this->getJson('/api/honor/top-students?subject_id='.$this->fiqh.'&gender=female')->json('rows.*.student.id'))->toBe([$girl->id]);

    // A teacher sees the classes they teach the subject in.
    $this->actingAs($this->otherTeacher, 'sanctum');
    expect($this->getJson('/api/honor/top-students?subject_id='.$this->fiqh)->json('rows'))->toBe([]);
    $this->actingAs($this->fiqhTeacher, 'sanctum');
    expect(count($this->getJson('/api/honor/top-students?subject_id='.$this->fiqh)->json('rows')))->toBe(3);
});

it('creates and rolls back the grades tables', function () {
    expect(Schema::hasTable('grade_components') && Schema::hasTable('grade_entries') && Schema::hasTable('exam_required_lessons'))->toBeTrue();
    $guard = 0;
    while (Schema::hasTable('grade_components') && $guard++ < 50) {
        Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    }
    expect(Schema::hasTable('grade_components') || Schema::hasTable('grade_entries') || Schema::hasTable('exam_required_lessons'))->toBeFalse();
    Artisan::call('migrate', ['--force' => true]);
    expect(Schema::hasTable('grade_components'))->toBeTrue();
});
