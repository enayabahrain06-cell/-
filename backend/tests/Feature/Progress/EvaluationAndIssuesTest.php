<?php

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\MessageLog;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\SettingsService;

beforeEach(function () {
    $this->teacher = User::factory()->role('teacher')->create(['name' => 'الشيخ يوسف']);
    $this->lesson = Lesson::factory()->create(['teacher_id' => $this->teacher->id, 'name' => 'حلقة النور']);
    $this->session = LessonSession::factory()->create(['lesson_id' => $this->lesson->id, 'session_date' => today()->toDateString()]);
    $this->students = Student::factory()->count(3)->create(['locale' => 'ar']);
    foreach ($this->students as $s) {
        LessonStudent::create(['lesson_id' => $this->lesson->id, 'student_id' => $s->id, 'joined_at' => today()->subMonth(), 'status' => 'active']);
    }
    $this->guardian = User::factory()->withoutPassword()->create();
    $this->guardian->assignRole('guardian');
    $this->students[0]->update(['guardian_user_id' => $this->guardian->id]);
});

function scores(int $m, int $t, int $r, int $b, array $extra = []): array
{
    return ['memorization' => $m, 'tajweed' => $t, 'revision' => $r, 'behavior' => $b] + $extra;
}

it('saves daily scores, appends the ledger and suggests a difficulty for scores below 6', function () {
    $this->actingAs($this->teacher, 'sanctum');
    [$a, $b, $c] = $this->students;

    $res = $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [
        ['student_id' => $a->id] + scores(9, 4, 8, 10, ['note' => 'يحتاج إلى ضبط المدود', 'progress' => [['type' => 'memorized', 'surah_number' => 78, 'from_ayah' => 1, 'to_ayah' => 16]]]),
        ['student_id' => $b->id] + scores(8, 8, 8, 8),
        ['student_id' => $c->id] + scores(5, 9, 9, 9),
    ]])->assertOk();

    expect($res->json('data'))->toHaveCount(3)
        ->and(Evaluation::where('lesson_session_id', $this->session->id)->count())->toBe(3)
        ->and(StudentProgress::where('student_id', $a->id)->count())->toBe(1);

    $suggestions = collect($res->json('suggested_issues'));
    expect($suggestions)->toHaveCount(2)
        ->and($suggestions->firstWhere('student_id', $a->id))->toMatchArray(['criterion' => 'tajweed', 'score' => 4, 'category' => 'tajweed'])
        ->and($suggestions->firstWhere('student_id', $c->id))->toMatchArray(['criterion' => 'memorization', 'category' => 'weak_memorization']);

    // Grades are audited.
    expect(AuditLog::where('action', 'evaluation.created')->count())->toBe(3);

    // Re-saving is an upsert; an open issue in the same category suppresses the suggestion.
    StudentIssue::create(['student_id' => $a->id, 'category' => 'tajweed', 'description' => 'مدود', 'severity' => 'medium', 'status' => 'open', 'opened_at' => now()]);
    $res = $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $a->id] + scores(9, 3, 8, 10)]])->assertOk();
    expect($res->json('suggested_issues'))->toBe([])
        ->and(Evaluation::where('lesson_session_id', $this->session->id)->count())->toBe(3)
        ->and(AuditLog::where('action', 'evaluation.updated')->first()->old_values)->toBe(['tajweed' => 4, 'note' => 'يحتاج إلى ضبط المدود']);
});

it('validates scores and refuses teachers of other circles and students outside the circle', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $this->students[0]->id] + scores(11, 5, 5, 5)]])
        ->assertStatus(422)->assertJsonValidationErrors('entries.0.memorization');

    $outsider = Student::factory()->create();
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $outsider->id] + scores(5, 5, 5, 5)]])
        ->assertStatus(422);

    $this->actingAs(User::factory()->role('teacher')->create(), 'sanctum');
    $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $this->students[0]->id] + scores(5, 5, 5, 5)]])
        ->assertForbidden();
});

it('builds the evaluation summary: latest scores, monthly averages, 8-week trend, rank and latest note', function () {
    [$a, $b, $c] = $this->students;
    $make = fn ($student, $date, $s, $note = null) => Evaluation::create(['student_id' => $student->id, 'lesson_id' => $this->lesson->id, 'type' => 'daily', 'evaluated_on' => $date, 'evaluated_by' => $this->teacher->id, 'note' => $note] + $s);
    $make($a, today()->subDays(2)->toDateString(), scores(8, 8, 8, 8));
    $make($a, today()->subDay()->toDateString(), scores(10, 10, 10, 10), 'ممتاز، واصل');
    $make($b, today()->subDay()->toDateString(), scores(10, 10, 10, 10));
    $make($c, today()->subDay()->toDateString(), scores(5, 5, 5, 5));

    $this->actingAs($this->teacher, 'sanctum');
    $this->postJson("/api/lessons/{$this->lesson->id}/evaluations/monthly", ['period' => now()->format('Y-m'), 'entries' => [['student_id' => $a->id] + scores(9, 9, 9, 9)]])->assertOk();

    $ev = $this->getJson("/api/students/{$a->id}/profile")->assertOk()->json('data.evaluation');

    expect($ev['latest_daily'][0]['total'])->toBe(40)
        ->and($ev['latest_note'])->toMatchArray(['note' => 'ممتاز، واصل', 'teacher' => 'الشيخ يوسف'])
        ->and($ev['trend'])->toHaveCount(8)
        ->and($ev['rank'])->toMatchArray(['rank' => 2, 'of' => 3, 'average' => 36.0]);

    $current = collect($ev['monthly_averages'])->firstWhere('period', today()->subDay()->format('Y-m'));
    expect($current['total'])->toBeGreaterThan(30.0);
});

it('sends an evaluation to the guardian in the student language', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $a = $this->students[0];
    $id = $this->postJson("/api/sessions/{$this->session->id}/evaluations", ['entries' => [['student_id' => $a->id] + scores(9, 8, 7, 10)]])->json('data.0.id');

    $this->postJson("/api/evaluations/{$id}/send")->assertOk();

    $log = MessageLog::where('type', 'evaluation_result')->where('recipient_phone', $a->guardian_phone)->first();
    expect($log)->not->toBeNull()->and($log->body)->toContain('التجويد 8/10')
        ->and(Evaluation::find($id)->sent_to_guardian_at)->not->toBeNull();
});

it('tracks difficulties with action plans and follow-up notes; guardians can read but not edit', function () {
    $a = $this->students[0];
    $this->actingAs($this->teacher, 'sanctum');

    $this->postJson("/api/students/{$a->id}/issues", ['category' => 'weak_revision', 'subcategory' => 'madd', 'description' => 'ضعف في المراجعة', 'severity' => 'low'])
        ->assertStatus(422)->assertJsonValidationErrors('subcategory');

    $issueId = $this->postJson("/api/students/{$a->id}/issues", [
        'category' => 'tajweed',
        'subcategory' => 'madd',
        'description' => 'لا يضبط المد المتصل',
        'action_plan' => 'تدريب يومي خمس دقائق على المدود مع ولي الأمر',
        'severity' => 'high',
        'next_follow_up_date' => today()->addWeek()->toDateString(),
    ])->assertCreated()->assertJsonPath('data.lesson_id', $this->lesson->id)->json('data.id');

    $this->postJson("/api/issues/{$issueId}/notes", ['note' => 'تحسن ملحوظ', 'status' => 'improving'])->assertCreated()
        ->assertJsonPath('data.status', 'improving')->assertJsonPath('data.notes.0.note', 'تحسن ملحوظ');
    $this->postJson("/api/issues/{$issueId}/notes", ['note' => 'أتقن المد', 'status' => 'resolved'])->assertCreated();
    expect(StudentIssue::find($issueId)->resolved_at)->not->toBeNull();

    $this->putJson("/api/issues/{$issueId}", ['status' => 'open'])->assertOk();
    expect(StudentIssue::find($issueId)->resolved_at)->toBeNull();

    // Guardian: reads the issue and its action plan in the profile, cannot write.
    $this->actingAs($this->guardian, 'sanctum');
    $profile = $this->getJson("/api/students/{$a->id}/profile")->assertOk()->json('data');
    expect($profile['header']['open_issues'])->toMatchArray(['total' => 1, 'by_severity' => ['low' => 0, 'medium' => 0, 'high' => 1]])
        ->and($profile['issues'][0]['action_plan'])->toContain('تدريب يومي');
    $this->getJson("/api/students/{$a->id}/issues")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson("/api/issues/{$issueId}")->assertOk();
    $this->postJson("/api/students/{$a->id}/issues", ['category' => 'behavior', 'description' => 'xyz', 'severity' => 'low'])->assertForbidden();
    $this->putJson("/api/issues/{$issueId}", ['status' => 'resolved'])->assertForbidden();
    $this->postJson("/api/issues/{$issueId}/notes", ['note' => 'شكراً'])->assertForbidden();

    // Another guardian sees nothing.
    $other = User::factory()->withoutPassword()->create();
    $other->assignRole('guardian');
    $this->actingAs($other, 'sanctum');
    $this->getJson("/api/issues/{$issueId}")->assertForbidden();
    $this->getJson("/api/students/{$a->id}/profile")->assertForbidden();
});

it('reports students by juz, high-severity issues, common categories and issues resolved per month', function () {
    [$a, $b, $c] = $this->students;
    $a->update(['progress_juz' => 30, 'progress_surah' => 78, 'progress_ayah' => 10]);
    $b->update(['progress_juz' => 30, 'progress_surah' => 80, 'progress_ayah' => 1]);
    $mk = fn ($s, $cat, $sev, $status = 'open', $resolvedAt = null) => StudentIssue::create(['student_id' => $s->id, 'lesson_id' => $this->lesson->id, 'category' => $cat, 'description' => 'd', 'severity' => $sev, 'status' => $status, 'opened_at' => now()->subDays(3), 'resolved_at' => $resolvedAt]);
    $mk($a, 'tajweed', 'high');
    $mk($b, 'tajweed', 'medium');
    $mk($c, 'concentration', 'high');
    $mk($c, 'behavior', 'low', 'resolved', now());

    $this->actingAs($this->teacher, 'sanctum');
    $this->getJson('/api/reports/progress/by-juz')->assertForbidden();

    actingAsRole('supervisor');
    $byJuz = $this->getJson('/api/reports/progress/by-juz?juz=30')->assertOk();
    expect(collect($byJuz->json('data'))->firstWhere('juz', 30)['students'])->toBe(2)
        ->and(collect($byJuz->json('data'))->firstWhere('juz', null)['students'])->toBe(1)
        ->and($byJuz->json('students'))->toHaveCount(2);

    $high = $this->getJson('/api/reports/issues/high-severity')->assertOk();
    expect($high->json('total'))->toBe(2)->and($high->json('data.0.teacher'))->toBe('الشيخ يوسف');

    $cats = $this->getJson('/api/reports/issues/categories')->assertOk();
    expect($cats->json('overall.0'))->toMatchArray(['category' => 'tajweed', 'count' => 2])
        ->and($cats->json('by_teacher.0.total'))->toBe(3)
        ->and($cats->json('by_lesson.0.lesson'))->toBe('حلقة النور');

    $monthly = collect($this->getJson('/api/reports/issues/resolved-monthly?months=3')->assertOk()->json('data'));
    expect($monthly)->toHaveCount(3)->and($monthly->last()['resolved'])->toBe(1);
});

it('sends the optional monthly progress update to guardians in their language, once per month', function () {
    $a = $this->students[0];
    $this->guardian->update(['locale' => 'en']);
    StudentProgress::create(['student_id' => $a->id, 'type' => 'memorized', 'surah_number' => 114, 'from_ayah' => 1, 'to_ayah' => 6, 'ayah_count' => 6, 'recorded_on' => today()]);
    StudentIssue::create(['student_id' => $a->id, 'category' => 'mutashabihat', 'description' => 'd', 'severity' => 'medium', 'status' => 'open', 'opened_at' => now()]);

    $this->artisan('progress:send-monthly-updates')->assertSuccessful();
    expect(MessageLog::where('type', 'student_progress_update')->count())->toBe(0); // disabled by default

    app(SettingsService::class)->set('messages.progress_update_enabled', true, 'progress', 'bool');
    app(SettingsService::class)->set('messages.progress_update_day', (int) now(config('ahl.display_timezone'))->day, 'progress', 'int');
    $this->artisan('progress:send-monthly-updates')->assertSuccessful();

    $log = MessageLog::where('type', 'student_progress_update')->where('student_id', $a->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->locale)->toBe('en')
        ->and($log->body)->toContain('Surah An-Nas, ayah 6 (juz 30)')->toContain('Mixing similar verses');
    expect(MessageLog::where('type', 'student_progress_update')->count())->toBe(3);

    $this->artisan('progress:send-monthly-updates')->assertSuccessful();
    expect(MessageLog::where('type', 'student_progress_update')->count())->toBe(3);
});

it('issues a completion certificate PDF', function () {
    $this->actingAs($this->teacher, 'sanctum');
    $res = $this->postJson("/api/students/{$this->students[0]->id}/certificates/completion", ['achievement' => 'جزء عمّ'])->assertCreated();

    $cert = Certificate::find($res->json('data.id'));
    expect($cert->type->value)->toBe('completion')
        ->and($cert->title)->toContain('جزء عمّ')
        ->and($cert->mediaIn(\App\Enums\MediaCollection::Certificate))->not->toBeNull();
});
