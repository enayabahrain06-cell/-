<?php

use App\Models\AcademicTerm;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Division;
use App\Models\Evaluation;
use App\Models\IssueNote;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\LevelSubject;
use App\Models\Note;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Models\User;

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => today()->subWeeks(3)->toDateString(), 'end_date' => today()->addMonths(3)->toDateString()]);
    $this->l1 = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1']);
    $this->l2 = Level::create(['name_ar' => 'المستوى الثاني', 'name_en' => 'Level 2']);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id]);
    $this->teacher = User::factory()->role('teacher')->create();
    $this->mine = Lesson::factory()->create(['name' => 'صف النور', 'package_id' => $this->pkg->id, 'level_id' => $this->l1->id, 'teacher_id' => $this->teacher->id]);
    $this->other = Lesson::factory()->create(['name' => 'صف الهدى', 'package_id' => $this->pkg->id, 'level_id' => $this->l2->id]);
    $this->fiqh = Subject::create(['name_ar' => 'الفقه', 'name_en' => 'Fiqh', 'code' => 'fiqh'])->id;
    $this->ls1 = LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->l1->id, 'subject_id' => $this->fiqh]);
    $this->ls2 = LevelSubject::create(['academic_term_id' => $this->term->id, 'level_id' => $this->l2->id, 'subject_id' => $this->fiqh]);
    $this->enroll = function (Lesson $lesson, int $n = 1) {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $s = Student::factory()->create();
            LessonStudent::create(['lesson_id' => $lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
            $out[] = $s;
        }

        return $out;
    };
});

it('writes student notes within the teacher reach; authors edit their own, managers any', function () {
    [$a] = ($this->enroll)($this->mine);
    [$x] = ($this->enroll)($this->other);

    $this->actingAs($this->teacher, 'sanctum');
    $opts = $this->getJson('/api/notes/options')->assertOk();
    expect($opts->json('classes.*.id'))->toBe([$this->mine->id])->and($opts->json('levels.*.id'))->toBe([$this->l1->id])
        ->and($opts->json('level_subjects.*.id'))->toBe([$this->ls1->id]); // the class teacher teaches every subject of the class

    $id = $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'student', 'student_id' => $a->id, 'lesson_id' => $this->mine->id, 'body' => 'تحسّن في التلاوة'])
        ->assertCreated()->json('data.id');
    $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'student', 'student_id' => $x->id, 'body' => 'ليست من صفوفي'])->assertForbidden();
    $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'level', 'level_id' => $this->l2->id, 'body' => 'x'])->assertForbidden();
    expect($this->getJson("/api/notes/students?lesson_id={$this->mine->id}")->json('data.0.notes_count'))->toBe(1);
    $this->getJson("/api/notes/students?lesson_id={$this->other->id}")->assertForbidden();

    $supervisor = actingAsRole('supervisor');
    $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'student', 'student_id' => $x->id, 'body' => 'ملاحظة المشرف'])->assertCreated();
    expect($this->getJson('/api/notes?scope=student')->json('data'))->toHaveCount(2);
    $this->putJson("/api/notes/{$id}", ['pinned' => true])->assertOk()->assertJsonPath('data.pinned', true);

    // Another teacher cannot change it; the author can; a note of the supervisor stays out of the teacher's list.
    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->putJson("/api/notes/{$id}", ['body' => 'تعديل'])->assertForbidden();
    $this->actingAs($this->teacher, 'sanctum');
    expect($this->getJson('/api/notes?scope=student')->json('data.*.id'))->toBe([$id]);
    $this->putJson("/api/notes/{$id}", ['body' => 'تحسّن كبير'])->assertOk();
    $this->deleteJson("/api/notes/{$id}")->assertOk();
    expect(Note::find($id))->toBeNull()->and(AuditLog::where('action', 'note.deleted')->count())->toBe(1);

    actingAsRole('guardian');
    $this->getJson('/api/notes?scope=general')->assertForbidden();
});

it('keeps general, level and level-subject notes of the term, with filters', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'general', 'body' => 'اجتماع أولياء الأمور الخميس', 'pinned' => true])->assertCreated();
    $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'level', 'level_id' => $this->l1->id, 'body' => 'المستوى يحتاج مراجعة جزء عم'])->assertCreated();
    $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'level_subject', 'level_subject_id' => $this->ls1->id, 'body' => 'تأخر درس الطهارة'])->assertCreated();
    $this->postJson('/api/notes', ['academic_term_id' => $this->term->id, 'scope' => 'level_subject', 'level_subject_id' => $this->ls2->id, 'body' => 'x'])->assertForbidden();

    $old = AcademicTerm::create(['name_ar' => 'الفصل السابق', 'name_en' => 'Old']);
    Note::create(['academic_term_id' => $old->id, 'scope' => 'general', 'body' => 'قديمة']);

    expect($this->getJson('/api/notes?scope=general')->json('data.*.body'))->toBe(['اجتماع أولياء الأمور الخميس'])
        ->and($this->getJson("/api/notes?scope=general&term_id={$old->id}")->json('data.*.body'))->toBe(['قديمة'])
        ->and($this->getJson("/api/notes?scope=level&level_id={$this->l1->id}")->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/notes?scope=level_subject&level_id={$this->l1->id}")->json('data.0.level_subject.subject.name'))->toBe('الفقه')
        ->and($this->getJson('/api/notes?scope=level&q=جزء')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/notes?scope=level&q=غير')->json('data'))->toHaveCount(0);

    actingAsRole('supervisor');
    Note::create(['academic_term_id' => $this->term->id, 'scope' => 'level', 'level_id' => $this->l2->id, 'body' => 'مستوى ثان']);
    expect($this->getJson('/api/notes?scope=level')->json('data'))->toHaveCount(2);
    $this->actingAs($this->teacher, 'sanctum');
    expect($this->getJson('/api/notes?scope=level')->json('data'))->toHaveCount(1);
});

it('merges the student timeline: notes, difficulties, attendance and evaluation notes', function () {
    [$a] = ($this->enroll)($this->mine);
    $session = LessonSession::factory()->create(['lesson_id' => $this->mine->id, 'session_date' => today()->subDay()->toDateString()]);
    Note::create(['academic_term_id' => $this->term->id, 'scope' => 'student', 'student_id' => $a->id, 'body' => 'ملاحظة الطالب', 'author_id' => $this->teacher->id]);
    $issue = StudentIssue::create(['student_id' => $a->id, 'category' => 'tajweed', 'description' => 'المدود', 'severity' => 'medium', 'status' => 'open', 'opened_at' => now()]);
    IssueNote::create(['student_issue_id' => $issue->id, 'note' => 'تحسن بسيط', 'noted_on' => today()->toDateString()]);
    Attendance::create(['lesson_session_id' => $session->id, 'student_id' => $a->id, 'status' => 'late', 'note' => 'تأخر بسبب المواصلات']);
    Evaluation::create(['student_id' => $a->id, 'lesson_id' => $this->mine->id, 'type' => 'daily', 'evaluated_on' => today()->toDateString(), 'memorization' => 8, 'tajweed' => 8, 'revision' => 8, 'behavior' => 8, 'note' => 'أحسنت']);

    $this->actingAs($this->teacher, 'sanctum');
    $kinds = collect($this->getJson("/api/students/{$a->id}/timeline")->assertOk()->json('data'))->pluck('kind')->sort()->values()->all();
    expect($kinds)->toBe(['attendance', 'evaluation', 'issue', 'issue_note', 'note']);
    expect(Note::count())->toBe(1); // read through, nothing copied

    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->getJson("/api/students/{$a->id}/timeline")->assertForbidden();
});

it('manages divisions of a class: active students only, one division per class', function () {
    [$a, $b, $c] = ($this->enroll)($this->mine, 3);
    [$x] = ($this->enroll)($this->other);

    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->postJson('/api/divisions', ['academic_term_id' => $this->term->id, 'lesson_id' => $this->mine->id, 'name' => 'أ'])->assertForbidden();

    $this->actingAs($this->teacher, 'sanctum');
    $first = $this->postJson('/api/divisions', ['academic_term_id' => $this->term->id, 'lesson_id' => $this->mine->id, 'name' => 'المجموعة الأولى', 'teacher_id' => $this->teacher->id])
        ->assertCreated()->json('data.id');
    $second = $this->postJson('/api/divisions', ['academic_term_id' => $this->term->id, 'lesson_id' => $this->mine->id, 'name' => 'المجموعة الثانية'])->assertCreated()->json('data.id');
    $this->postJson('/api/divisions', ['academic_term_id' => $this->term->id, 'lesson_id' => $this->other->id, 'name' => 'x'])->assertForbidden();

    $this->putJson("/api/divisions/{$first}/students", ['student_ids' => [$a->id, $b->id]])->assertOk();
    $this->putJson("/api/divisions/{$second}/students", ['student_ids' => [$b->id, $c->id]])->assertJsonValidationErrors('student_ids');
    $this->putJson("/api/divisions/{$second}/students", ['student_ids' => [$x->id]])->assertJsonValidationErrors('student_ids');
    $this->putJson("/api/divisions/{$second}/students", ['student_ids' => [$c->id]])->assertOk();

    $res = $this->getJson("/api/divisions?lesson_id={$this->mine->id}")->assertOk();
    expect($res->json('divisions'))->toHaveCount(2)->and($res->json('can_manage'))->toBeTrue()
        ->and(collect($res->json('students'))->firstWhere('id', $b->id)['division_id'])->toBe($first);
    $this->getJson("/api/divisions?lesson_id={$this->other->id}")->assertForbidden();

    $this->putJson("/api/divisions/{$first}", ['name' => 'المتقدمون'])->assertOk()->assertJsonPath('data.name', 'المتقدمون');
    $this->deleteJson("/api/divisions/{$second}")->assertOk();
    expect(Division::count())->toBe(1)->and(\Illuminate\Support\Facades\DB::table('division_students')->count())->toBe(2);
});

it('lists the Quran ledger of the term read-only', function () {
    [$a] = ($this->enroll)($this->mine);
    [$x] = ($this->enroll)($this->other);
    $ledger = app(\App\Services\Progress\ProgressService::class);
    $ledger->append($a, ['type' => 'memorized', 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 10, 'lesson_id' => $this->mine->id, 'recorded_on' => today()->toDateString()], $this->teacher->id);
    $ledger->append($a, ['type' => 'revised', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6, 'lesson_id' => $this->mine->id, 'recorded_on' => today()->subDays(2)->toDateString()], $this->teacher->id);
    $ledger->append($x, ['type' => 'memorized', 'surah_number' => 113, 'from_ayah' => 1, 'to_ayah' => 5, 'lesson_id' => $this->other->id, 'recorded_on' => today()->toDateString()], null);

    $this->actingAs($this->teacher, 'sanctum');
    $res = $this->getJson('/api/quran-lessons')->assertOk();
    expect($res->json('data'))->toHaveCount(2)->and($res->json('classes.*.id'))->toBe([$this->mine->id])
        ->and($res->json('data.0'))->toMatchArray(['type' => 'memorized', 'surah_number' => 78, 'ayah_count' => 10, 'recorded_by' => $this->teacher->name])
        ->and($res->json('meta.ayahs'))->toBe(16);
    expect($this->getJson('/api/quran-lessons?type=revised')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/quran-lessons?from='.today()->subDay()->toDateString())->json('data'))->toHaveCount(1);

    actingAsRole('supervisor');
    expect($this->getJson('/api/quran-lessons')->json('data'))->toHaveCount(3);
    actingAsRole('guardian');
    $this->getJson('/api/quran-lessons')->assertForbidden();
});

it('records subject lessons taught against the plan: on time, late, overdue', function () {
    $w1 = \App\Models\PlanItem::create(['level_subject_id' => $this->ls1->id, 'week_no' => 1, 'title' => 'الطهارة']);
    $w2 = \App\Models\PlanItem::create(['level_subject_id' => $this->ls1->id, 'week_no' => 2, 'title' => 'الوضوء']);
    $w3 = \App\Models\PlanItem::create(['level_subject_id' => $this->ls1->id, 'week_no' => 3, 'title' => 'التيمم']);
    $w9 = \App\Models\PlanItem::create(['level_subject_id' => $this->ls1->id, 'week_no' => 9, 'title' => 'الصلاة']);
    $session = LessonSession::factory()->create(['lesson_id' => $this->mine->id, 'session_date' => today()->subWeeks(3)->toDateString()]);
    $start = today()->subWeeks(3);

    // A fiqh teacher of another class of the level cannot record here; a stranger sees no class.
    $fiqhTeacher = User::factory()->role('teacher')->create();
    TimetableSlot::create(['academic_term_id' => $this->term->id, 'level_id' => $this->l2->id, 'lesson_id' => $this->other->id, 'weekday' => 'sat', 'start_time' => '17:00:00', 'end_time' => '17:45:00', 'subject_id' => $this->fiqh, 'teacher_id' => $fiqhTeacher->id]);
    $this->actingAs($fiqhTeacher, 'sanctum');
    $this->getJson("/api/subject-progress?lesson_id={$this->mine->id}")->assertForbidden();
    $this->postJson('/api/subject-progress', ['lesson_id' => $this->mine->id, 'plan_item_id' => $w1->id, 'taught_on' => today()->toDateString()])->assertForbidden();

    $this->actingAs($this->teacher, 'sanctum');
    $this->postJson('/api/subject-progress', ['lesson_id' => $this->mine->id, 'plan_item_id' => $w1->id, 'taught_on' => $start->toDateString(), 'lesson_session_id' => $session->id])->assertOk();
    $this->postJson('/api/subject-progress', ['lesson_id' => $this->mine->id, 'plan_item_id' => $w2->id, 'taught_on' => today()->toDateString(), 'notes' => 'تأخر بسبب الإجازة'])->assertOk();
    $this->postJson('/api/subject-progress', ['lesson_id' => $this->other->id, 'plan_item_id' => $w1->id, 'taught_on' => today()->toDateString()])->assertJsonValidationErrors('plan_item_id');
    $this->postJson('/api/subject-progress', ['lesson_id' => $this->mine->id, 'plan_item_id' => $w1->id, 'taught_on' => today()->addDays(3)->toDateString()])->assertJsonValidationErrors('taught_on');

    $res = $this->getJson("/api/subject-progress?lesson_id={$this->mine->id}&subject_id={$this->fiqh}")->assertOk();
    $status = collect($res->json('items'))->pluck('status', 'plan_item_id')->all();
    expect($status)->toBe([$w1->id => 'on_time', $w2->id => 'late', $w3->id => 'overdue', $w9->id => 'upcoming'])
        ->and($res->json('summary'))->toMatchArray(['total' => 4, 'taught' => 2, 'late' => 1])
        ->and($res->json('items.0.progress.session_date'))->toBe($session->session_date->toDateString());

    $pid = $res->json('items.1.progress.id');
    $this->deleteJson("/api/subject-progress/{$pid}")->assertOk();
    expect(\App\Models\SubjectLessonProgress::count())->toBe(1);
});
