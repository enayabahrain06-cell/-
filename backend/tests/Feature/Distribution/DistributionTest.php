<?php

use App\Models\AcademicTerm;
use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Services\Circles\CircleEnrollmentService;

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(4)->toDateString()]);
    $this->next = AcademicTerm::create(['name_ar' => 'الفصل الثاني', 'name_en' => 'Term 2', 'start_date' => now()->addMonths(5)->toDateString()]);
    $this->l1 = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1', 'sort' => 1, 'min_age' => 7, 'max_age' => 10]);
    $this->l2 = Level::create(['name_ar' => 'المستوى الثاني', 'name_en' => 'Level 2', 'sort' => 2, 'min_age' => 11, 'max_age' => 14]);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id, 'min_age' => 6, 'max_age' => 15]);
    $this->pkg2 = Package::factory()->create(['academic_term_id' => $this->next->id, 'min_age' => 6, 'max_age' => 15]);
    $this->a = Lesson::factory()->create(['name' => 'صف أ', 'package_id' => $this->pkg->id, 'level_id' => $this->l1->id, 'capacity' => 1]);
    $this->b = Lesson::factory()->create(['name' => 'صف ب', 'package_id' => $this->pkg->id, 'level_id' => $this->l1->id, 'capacity' => 3]);
    $this->c = Lesson::factory()->create(['name' => 'صف ج', 'package_id' => $this->pkg->id, 'level_id' => $this->l2->id, 'capacity' => 3]);
    $this->next2 = Lesson::factory()->create(['name' => 'صف الترفيع', 'package_id' => $this->pkg2->id, 'level_id' => $this->l2->id, 'capacity' => 5]);
    $this->nextRepeat = Lesson::factory()->create(['name' => 'صف الإعادة', 'package_id' => $this->pkg2->id, 'level_id' => $this->l1->id, 'capacity' => 5]);
    $this->kid = fn (int $age = 9) => Student::factory()->male()->create(['birth_date' => now()->subYears($age)->subMonth()->toDateString(), 'memorization_level' => 'juz_amma']);
});

it('lists the unplaced students with a suggested level and places them, one refusal at a time', function () {
    $s1 = ($this->kid)(9);
    $s2 = ($this->kid)(12);
    $placed = ($this->kid)(9);
    app(CircleEnrollmentService::class)->join($this->b, $placed);

    actingAsRole('teacher');
    $this->getJson('/api/distribution/students')->assertForbidden();

    actingAsRole('supervisor');
    $rows = collect($this->getJson('/api/distribution/students')->assertOk()->json('data'))->keyBy('id');
    expect($rows->has($placed->id))->toBeFalse()
        ->and($rows[$s1->id]['suggested_level']['id'])->toBe($this->l1->id)
        ->and($rows[$s2->id]['suggested_level']['id'])->toBe($this->l2->id);
    expect($this->getJson("/api/distribution/students?view=level&level_id={$this->l1->id}")->json('data.0.id'))->toBe($placed->id);

    // "auto": the class of the level with the most free seats (ب has 2, أ has 1).
    $res = $this->postJson('/api/distribution/place', ['academic_term_id' => $this->term->id, 'level_id' => $this->l1->id, 'lesson_id' => null, 'student_ids' => [$s1->id]])
        ->assertOk()->json('data.0');
    expect($res['ok'])->toBeTrue()->and($res['lesson']['id'])->toBe($this->b->id);

    // A full class refuses per student; the others still go in.
    $s3 = ($this->kid)(9);
    $s4 = ($this->kid)(9);
    $out = collect($this->postJson('/api/distribution/place', ['academic_term_id' => $this->term->id, 'level_id' => $this->l1->id, 'lesson_id' => $this->a->id, 'student_ids' => [$s3->id, $s4->id]])
        ->assertOk()->json('data'));
    expect($out->where('ok', true))->toHaveCount(1)->and($out->where('ok', false))->toHaveCount(1)
        ->and($this->a->activeStudentCount())->toBe(1);

    // A class of another level is refused.
    $this->postJson('/api/distribution/place', ['academic_term_id' => $this->term->id, 'level_id' => $this->l1->id, 'lesson_id' => $this->c->id, 'student_ids' => [$s2->id]])
        ->assertJsonValidationErrors('lesson_id');
});

it('promotes, repeats and graduates, keeping the old term as history and recording each decision', function () {
    $up = ($this->kid)(9);
    $stay = ($this->kid)(9);
    $done = ($this->kid)(9);
    foreach ([$up, $stay] as $s) {
        app(CircleEnrollmentService::class)->join($this->b, $s);
    }
    app(CircleEnrollmentService::class)->join($this->a, $done);

    actingAsRole('supervisor');
    $view = $this->getJson("/api/distribution/promotion?from_term_id={$this->term->id}&from_level_id={$this->l1->id}&to_term_id={$this->next->id}")->assertOk();
    expect($view->json('data.next_level_id'))->toBe($this->l2->id)->and($view->json('data.students'))->toHaveCount(3)
        ->and($view->json('data.promote_classes.0.id'))->toBe($this->next2->id);

    $base = ['from_term_id' => $this->term->id, 'from_level_id' => $this->l1->id, 'to_term_id' => $this->next->id, 'to_level_id' => $this->l2->id];
    $res = collect($this->postJson('/api/distribution/promotion', $base + ['mark_graduated' => true, 'decisions' => [
        ['student_id' => $up->id, 'decision' => 'promote'],
        ['student_id' => $stay->id, 'decision' => 'repeat', 'reason' => 'لم يكمل الحفظ'],
        ['student_id' => $done->id, 'decision' => 'graduate'],
    ]])->assertOk()->json('data'));
    expect($res->every(fn ($r) => $r['ok']))->toBeTrue();

    expect(LessonStudent::where('student_id', $up->id)->where('lesson_id', $this->next2->id)->where('status', 'active')->exists())->toBeTrue()
        ->and(LessonStudent::where('student_id', $up->id)->where('lesson_id', $this->b->id)->value('status')->value)->toBe('left')
        ->and(LessonStudent::where('student_id', $stay->id)->where('lesson_id', $this->nextRepeat->id)->where('status', 'active')->exists())->toBeTrue()
        ->and($done->fresh()->status->value)->toBe('graduated')
        ->and(StudentPromotion::count())->toBe(3)
        ->and(StudentPromotion::where('student_id', $up->id)->value('to_lesson_id'))->toBe($this->next2->id)
        ->and(AuditLog::where('action', 'student.promotion')->count())->toBe(3);

    // One decision per student per term.
    $again = $this->postJson('/api/distribution/promotion', $base + ['decisions' => [['student_id' => $up->id, 'decision' => 'promote']]])->json('data.0');
    expect($again['ok'])->toBeFalse();
    // The target term must differ.
    $this->postJson('/api/distribution/promotion', ['to_term_id' => $this->term->id] + $base + ['decisions' => [['student_id' => $up->id, 'decision' => 'promote']]])
        ->assertJsonValidationErrors('to_term_id');
});

it('changes one student\'s level with the move logic and shows the level history', function () {
    $s = ($this->kid)(9);
    app(CircleEnrollmentService::class)->join($this->b, $s);

    actingAsRole('teacher');
    $this->getJson("/api/distribution/level/{$s->id}")->assertForbidden();

    actingAsRole('supervisor');
    expect($this->getJson("/api/distribution/level/{$s->id}")->assertOk()->json('data.current.level_id'))->toBe($this->l1->id);
    $this->postJson("/api/distribution/level/{$s->id}", ['academic_term_id' => $this->term->id, 'level_id' => $this->l1->id, 'reason' => 'x'])
        ->assertJsonValidationErrors('level_id');
    $this->postJson("/api/distribution/level/{$s->id}", ['academic_term_id' => $this->term->id, 'level_id' => $this->l2->id, 'reason' => 'تقدّم في الحفظ'])
        ->assertOk()->assertJsonPath('data.decision', 'level_change')->assertJsonPath('data.to_lesson', 'صف ج');

    expect(LessonStudent::where('student_id', $s->id)->where('lesson_id', $this->b->id)->first()->moved_to_lesson_id)->toBe($this->c->id)
        ->and($this->getJson("/api/distribution/level/{$s->id}")->json('data.history.0.decision'))->toBe('level_change')
        ->and($this->getJson("/api/distribution/level/{$s->id}")->json('data.current.lesson_id'))->toBe($this->c->id);
});

it('rolls its migrations back and forth', function () {
    for ($i = 0; $i < 40 && \Illuminate\Support\Facades\Schema::hasTable('student_promotions'); $i++) {
        \Illuminate\Support\Facades\Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    }
    expect(\Illuminate\Support\Facades\Schema::hasTable('books'))->toBeFalse()->and(\Illuminate\Support\Facades\Schema::hasTable('archive_records'))->toBeFalse();
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    expect(\Illuminate\Support\Facades\Schema::hasTable('book_deliveries'))->toBeTrue()->and(\Illuminate\Support\Facades\Schema::hasTable('student_promotions'))->toBeTrue();
});
