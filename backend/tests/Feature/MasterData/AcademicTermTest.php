<?php

use App\Models\AcademicTerm;
use App\Models\Exam;
use App\Models\Lesson;
use App\Models\Package;
use App\Models\Student;
use App\Services\Wallet\WalletService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function makeTerm(array $attrs = []): AcademicTerm
{
    static $n = 0;
    $n++;

    return AcademicTerm::create($attrs + ['name_ar' => "الفصل {$n}", 'name_en' => "Term {$n}"]);
}

it('lets the super admin manage terms and keeps exactly one current term', function () {
    actingAsRole('super_admin');

    $first = $this->postJson('/api/academic-terms', ['name_ar' => 'الفصل الدراسي الأول - 2026/2027', 'name_en' => 'Term 1 - 2026/2027', 'academic_year' => '2026/2027', 'start_date' => '2026-09-01', 'end_date' => '2027-01-15'])
        ->assertCreated()->json('data');
    expect($first['is_current'])->toBeTrue(); // the first term becomes current

    $second = $this->postJson('/api/academic-terms', ['name_ar' => 'الفصل الدراسي الثاني - 2026/2027', 'name_en' => 'Term 2 - 2026/2027', 'is_current' => true])->assertCreated()->json('data');
    expect(AcademicTerm::where('is_current', true)->pluck('id')->all())->toBe([$second['id']]);

    $this->postJson("/api/academic-terms/{$first['id']}/current")->assertOk();
    expect(AcademicTerm::where('is_current', true)->pluck('id')->all())->toBe([$first['id']]);

    // The current term cannot be switched off or deleted; the others can.
    $this->putJson("/api/academic-terms/{$first['id']}", ['name_ar' => 'الفصل الدراسي الأول - 2026/2027', 'name_en' => 'T1', 'is_current' => false])->assertUnprocessable()->assertJsonValidationErrors('is_current');
    $this->deleteJson("/api/academic-terms/{$first['id']}")->assertUnprocessable();
    $this->deleteJson("/api/academic-terms/{$second['id']}")->assertOk();

    $this->postJson('/api/academic-terms', ['name_ar' => 'x', 'name_en' => 'x', 'academic_year' => '2026-27'])->assertJsonValidationErrors('academic_year');
    $this->postJson('/api/academic-terms', ['name_ar' => 'الفصل الدراسي الأول - 2026/2027', 'name_en' => 'dup'])->assertJsonValidationErrors('name_ar');
});

it('refuses to delete a term that has packages', function () {
    actingAsRole('super_admin');
    $current = makeTerm(['is_current' => true]);
    $old = makeTerm();
    Package::factory()->create(['academic_term_id' => $old->id]);

    $this->deleteJson("/api/academic-terms/{$old->id}")->assertUnprocessable()->assertJsonValidationErrors('term');
});

it('lists terms to every staff member but lets only terms.manage edit them', function () {
    makeTerm(['is_current' => true]);

    actingAsRole('teacher');
    $this->getJson('/api/academic-terms')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.packages_count', null);
    $this->postJson('/api/academic-terms', ['name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();

    actingAsRole('supervisor');
    $this->postJson('/api/academic-terms', ['name_ar' => 'x', 'name_en' => 'x'])->assertForbidden();

    actingAsRole('guardian');
    $this->getJson('/api/academic-terms')->assertForbidden();
});

it('scopes packages, circles, registrations, invoices, exams and evaluations by ?term_id and shows everything without it', function () {
    actingAsRole('super_admin');
    $now = makeTerm(['is_current' => true, 'start_date' => now()->subMonth()->toDateString(), 'end_date' => now()->addMonths(3)->toDateString()]);
    $old = makeTerm(['start_date' => now()->subYear()->toDateString(), 'end_date' => now()->subMonths(6)->toDateString()]);

    $pNow = Package::factory()->create(['academic_term_id' => $now->id]);
    $pOld = Package::factory()->create(['academic_term_id' => $old->id]);
    $lNow = Lesson::factory()->create(['package_id' => $pNow->id]);
    Lesson::factory()->create(['package_id' => $pOld->id]);
    $student = Student::factory()->create();
    $inv = app(WalletService::class)->createInvoice($student, $pNow, 1000, now(), 'fee');
    app(WalletService::class)->createInvoice($student, $pOld, 1000, now(), 'old fee');
    Exam::factory()->create(['lesson_id' => $lNow->id]);
    // Linked to neither a package nor a circle: follows the term dates.
    Exam::factory()->create(['lesson_id' => null, 'package_id' => null, 'exam_date' => now()->subYear()->addDay()->toDateString()]);

    expect($this->getJson('/api/packages')->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/packages?term_id=all')->json('meta.total'))->toBe(2)
        ->and($this->getJson("/api/packages?term_id={$now->id}")->json('data.*.id'))->toBe([$pNow->id])
        ->and($this->getJson("/api/lessons?term_id={$now->id}")->json('data.*.id'))->toBe([$lNow->id])
        ->and($this->getJson("/api/invoices?term_id={$now->id}")->json('data.*.id'))->toBe([$inv->id])
        ->and($this->getJson("/api/exams?term_id={$now->id}")->json('meta.total'))->toBe(1)
        ->and($this->getJson("/api/exams?term_id={$old->id}")->json('meta.total'))->toBe(1)
        ->and($this->getJson('/api/exams')->json('meta.total'))->toBe(2)
        ->and($this->getJson('/api/registrations?term_id='.$now->id)->assertOk()->json('meta.total'))->toBe(0);

    $this->getJson('/api/packages?term_id=abc')->assertUnprocessable();
});

it('puts new packages and invoices in the current term', function () {
    actingAsRole('super_admin');
    $term = makeTerm(['is_current' => true]);

    $payload = Package::factory()->make()->only(['name', 'min_age', 'max_age', 'gender', 'seats', 'price_fils', 'days', 'start_date', 'end_date']) + ['start_time' => '16:00', 'end_time' => '17:00'];
    $id = $this->postJson('/api/packages', $payload)->assertCreated()->json('data.id');
    expect(Package::find($id)->academic_term_id)->toBe($term->id);

    $invoice = app(WalletService::class)->createInvoice(Student::factory()->create(), null, 500, now(), 'manual');
    expect($invoice->academic_term_id)->toBe($term->id);
});

it('keeps the dashboard ?term= label filter and accepts ?term_id', function () {
    actingAsRole('super_admin');
    $term = makeTerm(['is_current' => true]);
    $pkg = Package::factory()->create(['term' => 'T-1', 'academic_term_id' => $term->id]);
    Lesson::factory()->create(['package_id' => $pkg->id]);

    $this->getJson('/api/dashboard/memorization?term=T-1')->assertOk();
    $this->getJson("/api/dashboard/memorization?term_id={$term->id}")->assertOk();
    $this->getJson("/api/dashboard/upcoming?term_id={$term->id}")->assertOk();
    $this->getJson("/api/alerts?term_id={$term->id}")->assertOk();
    $this->getJson("/api/reports/attendance?term_id={$term->id}")->assertOk();
    $this->getJson("/api/reports/finance?term_id={$term->id}")->assertOk();
});

it('migrates free-text terms into linked academic terms without changing the text', function () {
    Package::factory()->create(['term' => '2025-2026 / 2', 'start_date' => '2026-02-01', 'end_date' => '2026-06-01']);
    $a = Package::factory()->create(['term' => ' 2026-2027 / 1 ', 'start_date' => '2026-09-01', 'end_date' => '2027-01-01']);
    $b = Package::factory()->create(['term' => '2026-2027 / 1', 'start_date' => '2026-09-15', 'end_date' => '2027-01-10']);
    $none = Package::factory()->create(['term' => null]);
    $student = Student::factory()->create();
    DB::table('invoices')->insert(['invoice_no' => 'INV-X1', 'student_id' => $student->id, 'package_id' => null, 'term' => '2026-2027 / 1', 'description' => 'x', 'amount_fils' => 1, 'due_date' => '2026-10-01', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

    // SQLite rebuilds "packages" to add/drop the column. Inside the test's own transaction the foreign-key pragma
    // cannot be switched off, so ON DELETE SET NULL would clear invoices.package_id (a real migrate keeps it), hence
    // the invoice here carries its own label instead of a package.
    Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]);
    Artisan::call('migrate', ['--force' => true]);

    $terms = AcademicTerm::orderBy('id')->get();
    expect($terms->pluck('legacy_label')->sort()->values()->all())->toBe(['2025-2026 / 2', '2026-2027 / 1']);
    $t1 = $terms->firstWhere('legacy_label', '2026-2027 / 1');
    expect(DB::table('packages')->where('id', $a->id)->value('academic_term_id'))->toBe($t1->id)
        ->and(DB::table('packages')->where('id', $b->id)->value('academic_term_id'))->toBe($t1->id)
        ->and(DB::table('packages')->where('id', $a->id)->value('term'))->toBe(' 2026-2027 / 1 ') // text untouched
        ->and(DB::table('packages')->where('id', $none->id)->value('academic_term_id'))->toBeNull()
        ->and(DB::table('invoices')->where('invoice_no', 'INV-X1')->value('academic_term_id'))->toBe($t1->id)
        ->and($t1->start_date->toDateString())->toBe('2026-09-01')
        ->and(AcademicTerm::where('is_current', true)->count())->toBe(1)
        // Quran is seeded again as a system subject.
        ->and(DB::table('subjects')->where('code', 'quran')->value('is_system'))->toBeTruthy();
});

it('lets a manual invoice choose its term, defaulting to the package term or the current one', function () {
    actingAsRole('super_admin');
    makeTerm(['is_current' => true]);
    $other = makeTerm();
    $student = Student::factory()->create();

    $id = $this->postJson('/api/invoices', ['student_id' => $student->id, 'amount_fils' => 500, 'due_date' => '2026-10-01', 'description' => 'x', 'academic_term_id' => $other->id])
        ->assertCreated()->json('data.id');
    expect(\App\Models\Invoice::find($id)->academic_term_id)->toBe($other->id);
    $this->postJson('/api/invoices', ['student_id' => $student->id, 'amount_fils' => 500, 'due_date' => '2026-10-01', 'description' => 'x', 'academic_term_id' => 999])
        ->assertJsonValidationErrors('academic_term_id');
});

it('reports rows that would get no term and dry-runs the conversion without writing', function () {
    $current = makeTerm(['is_current' => true, 'legacy_label' => 'T-1', 'start_date' => '2026-09-01', 'end_date' => '2027-01-31']);
    Package::factory()->create(['term' => '   ', 'name' => 'Blank']);
    $new = Package::factory()->create(['term' => 'T-2', 'start_date' => '2027-02-01', 'end_date' => '2027-06-30']);
    Exam::factory()->create(['lesson_id' => null, 'package_id' => null, 'exam_date' => '2030-01-01']);

    $this->artisan('terms:check')
        ->expectsOutputToContain('Blank')
        ->expectsOutputToContain('outside every term')
        ->assertExitCode(1);

    $this->artisan('terms:convert --dry-run')->expectsOutputToContain('DRY RUN')->assertExitCode(0);
    expect(AcademicTerm::count())->toBe(1)->and($new->fresh()->academic_term_id)->toBeNull();

    $this->artisan('terms:convert')->assertExitCode(0);
    $t2 = AcademicTerm::where('legacy_label', 'T-2')->firstOrFail();
    expect($new->fresh()->academic_term_id)->toBe($t2->id)
        ->and(AcademicTerm::where('is_current', true)->pluck('id')->all())->toBe([$current->id]);
});
