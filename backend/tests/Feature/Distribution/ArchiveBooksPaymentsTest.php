<?php

use App\Models\AcademicTerm;
use App\Models\ArchiveRecord;
use App\Models\Book;
use App\Models\BookDelivery;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubjectLesson;
use App\Services\Circles\CircleEnrollmentService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

function sheetFile(string $name, array $rows): UploadedFile
{
    $raw = Excel::raw(new class($rows) implements FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, ExcelFormat::XLSX);

    return UploadedFile::fake()->createWithContent($name, $raw);
}

beforeEach(function () {
    $this->term = AcademicTerm::create(['name_ar' => 'الفصل الأول', 'name_en' => 'Term 1', 'is_current' => true, 'start_date' => now()->toDateString()]);
    $this->level = Level::create(['name_ar' => 'المستوى الأول', 'name_en' => 'Level 1', 'code' => 'L1']);
    $this->pkg = Package::factory()->create(['academic_term_id' => $this->term->id, 'min_age' => 6, 'max_age' => 15]);
    $this->class = Lesson::factory()->create(['name' => 'صف أ', 'package_id' => $this->pkg->id, 'level_id' => $this->level->id]);
    $this->other = Lesson::factory()->create(['name' => 'صف بلا مستوى', 'package_id' => $this->pkg->id]);
    $this->kid = function (Lesson $l, array $attrs = []) {
        $s = Student::factory()->male()->create($attrs + ['birth_date' => now()->subYears(9)->toDateString()]);
        app(CircleEnrollmentService::class)->join($l, $s);

        return $s;
    };
});

it('uploads the archive with a preview, matches students and deletes only the batch rows', function () {
    $known = Student::factory()->male()->create(['cpr' => '120512345', 'full_name' => 'علي حسن المحروس']);
    $byName = Student::factory()->male()->create(['full_name' => 'محمد جعفر الستري', 'birth_date' => '2012-01-02']);
    $file = sheetFile('archive.xlsx', [
        ['cpr', 'student_no', 'full_name', 'birth_date', 'academic_year', 'level_label', 'result'],
        ['120512345', '', 'علي حسن', '', '2023/2024', 'المستوى الثاني', 'ناجح'],
        ['', '', 'محمد جعفر الستري', '2012-01-02', '2023/2024', 'المستوى الأول', 'ناجح'],
        ['', '', 'طالب قديم', '', '2022/2023', '', 'راسب'],
        ['12', '', '', '', '', '', ''],
    ]);

    actingAsRole('teacher');
    $this->postJson('/api/archive/preview', ['file' => $file])->assertForbidden();
    $this->getJson('/api/archive/records')->assertForbidden();

    actingAsRole('supervisor');
    $this->get('/api/archive/template')->assertOk();
    $p = $this->postJson('/api/archive/preview', ['file' => $file])->assertOk()->assertJsonPath('valid', 3)->assertJsonPath('matched', 2)->assertJsonPath('invalid', 1);
    expect($p->json('rows.0.match.by'))->toBe('cpr')->and($p->json('rows.1.match.id'))->toBe($byName->id)
        ->and($p->json('rows.3.errors'))->toHaveKeys(['cpr', 'full_name', 'academic_year']);

    $batch = $this->postJson('/api/archive/batches', ['file_name' => 'archive.xlsx', 'rows' => $p->json('rows')])->assertCreated()->json('data');
    expect($batch['rows_count'])->toBe(3)->and($batch['matched_count'])->toBe(2);

    expect($this->getJson('/api/archive/records?search=120512345')->json('data'))->toHaveCount(1)
        ->and($this->getJson('/api/archive/records?academic_year=2022/2023')->json('meta.total'))->toBe(1)
        ->and($this->getJson("/api/students/{$known->id}/archive")->assertOk()->json('data.0.level_label'))->toBe('المستوى الثاني');

    $this->deleteJson("/api/archive/batches/{$batch['id']}")->assertOk();
    expect(ArchiveRecord::count())->toBe(0)->and(Student::whereKey([$known->id, $byName->id])->count())->toBe(2);
});

it('follows up the term fees per student and filters by status', function () {
    $paid = ($this->kid)($this->class);
    $none = ($this->kid)($this->class);
    $partial = ($this->kid)($this->other);
    $w = app(WalletService::class);
    $w->createInvoice($paid, null, 10000, now(), 'رسوم', null, null, $this->term->id);
    $w->recordPayment($paid, 10000, \App\Enums\PaymentMethod::Cash);
    $w->createInvoice($partial, null, 10000, now()->subDays(3), 'رسوم', null, null, $this->term->id);
    $w->recordPayment($partial, 4000, \App\Enums\PaymentMethod::Cash);

    actingAsRole('teacher');
    $this->getJson('/api/payment-followup')->assertForbidden();

    actingAsRole('supervisor');
    $r = $this->getJson('/api/payment-followup')->assertOk();
    $rows = collect($r->json('data'))->keyBy('student.id');
    expect($rows[$paid->id]['status'])->toBe('paid')->and($rows[$none->id]['status'])->toBe('none')
        ->and($rows[$partial->id]['status'])->toBe('partial')->and($rows[$partial->id]['remaining_fils'])->toBe(6000)
        ->and($rows[$partial->id]['overdue'])->toBeTrue()
        ->and($r->json('totals.remaining_fils'))->toBe(6000)->and($r->json('can_remind'))->toBeTrue();
    expect($this->getJson('/api/payment-followup?status=none')->json('data'))->toHaveCount(1)
        ->and($this->getJson("/api/payment-followup?level_id={$this->level->id}")->json('data'))->toHaveCount(2);
});

it('manages the term books, delivers them with an optional invoice and undoes unpaid deliveries only', function () {
    $s1 = ($this->kid)($this->class);
    $s2 = ($this->kid)($this->class);
    $outside = ($this->kid)($this->other);

    actingAsRole('teacher');
    $this->getJson('/api/books')->assertOk();
    $this->postJson('/api/books', ['academic_term_id' => $this->term->id, 'title' => 'x'])->assertForbidden();

    actingAsRole('supervisor');
    $id = $this->postJson('/api/books', ['academic_term_id' => $this->term->id, 'title' => 'كتاب التجويد', 'level_id' => $this->level->id, 'price_fils' => 2500, 'stock' => 2])
        ->assertCreated()->json('data.id');
    $book = Book::find($id);
    $f = $this->getJson("/api/books/{$id}/followup")->assertOk();
    expect(collect($f->json('data'))->pluck('student.id')->all())->not->toContain($outside->id)->and($f->json('totals.expected'))->toBe(2);

    $this->postJson("/api/books/{$id}/deliveries", ['student_ids' => [$s1->id, $s2->id, $outside->id], 'charge' => true])->assertJsonValidationErrors('student_ids'); // stock 2
    $this->postJson("/api/books/{$id}/deliveries", ['student_ids' => [$s1->id, $s2->id], 'charge' => true])->assertOk()->assertJsonPath('delivered', 2);
    $inv = Invoice::find(BookDelivery::where('student_id', $s1->id)->value('invoice_id'));
    expect($inv->description)->toBe('كتاب: كتاب التجويد')->and($inv->academic_term_id)->toBe($this->term->id)->and($inv->amount_fils)->toBe(2500);

    app(WalletService::class)->recordPayment($s1, 2500, \App\Enums\PaymentMethod::Cash);
    $d1 = BookDelivery::where('student_id', $s1->id)->first();
    $d2 = BookDelivery::where('student_id', $s2->id)->first();
    $this->deleteJson("/api/books/{$id}/deliveries/{$d1->id}")->assertJsonValidationErrors('delivery');
    $this->deleteJson("/api/books/{$id}/deliveries/{$d2->id}")->assertOk();
    expect(Invoice::find($d2->invoice_id)->status->value)->toBe('cancelled')->and(BookDelivery::count())->toBe(1);

    $this->deleteJson("/api/books/{$id}")->assertJsonValidationErrors('book');
    expect($book->fresh())->not->toBeNull();
});

it('imports subject lessons from Excel with a per-row preview', function () {
    $quran = Subject::where('code', 'quran')->first();
    SubjectLesson::create(['subject_id' => $quran->id, 'level_id' => null, 'title' => 'سورة الفاتحة', 'sort' => 0, 'is_active' => true]);
    $file = sheetFile('lessons.xlsx', [
        ['subject', 'level', 'title', 'description', 'order'],
        ['quran', 'L1', 'سورة الناس', 'حفظ', 1],
        ['القرآن الكريم', '', 'سورة الفلق', '', 2],
        ['quran', '', 'سورة الفاتحة', '', 3],
        ['fiqh', '', 'الطهارة', '', 1],
        ['quran', 'L9', 'سورة الإخلاص', '', 1],
        ['quran', 'L1', 'سورة الناس', '', 4],
        ['quran', '', '', '', 1],
    ]);

    actingAsRole('teacher');
    $this->postJson('/api/term-setup/subject-lessons/import/preview', ['file' => $file])->assertForbidden();

    actingAsRole('supervisor');
    $this->get('/api/term-setup/subject-lessons/import/template')->assertOk();
    $p = $this->postJson('/api/term-setup/subject-lessons/import/preview', ['file' => $file])->assertOk()->assertJsonPath('valid', 2)->assertJsonPath('invalid', 5);
    expect($p->json('rows.2.errors'))->toHaveKey('title')->and($p->json('rows.3.errors'))->toHaveKey('subject')
        ->and($p->json('rows.4.errors'))->toHaveKey('level')->and($p->json('rows.5.errors'))->toHaveKey('title');

    $this->postJson('/api/term-setup/subject-lessons/import', ['rows' => $p->json('rows')])->assertOk()->assertJsonPath('created', 2);
    expect(SubjectLesson::where('title', 'سورة الناس')->value('level_id'))->toBe($this->level->id)->and(SubjectLesson::count())->toBe(3);
});
