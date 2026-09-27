<?php

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Location;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\Payment;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallet\WalletService;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $start = now()->addWeeks(2)->toDateString();
    $this->boys = Package::factory()->create(['min_age' => 7, 'max_age' => 12, 'start_date' => $start, 'price_fils' => 20000, 'seats' => 30]);
    $this->girls = Package::factory()->girls()->create(['min_age' => 7, 'max_age' => 12, 'start_date' => $start, 'seats' => 30]);
    $this->early = Package::factory()->earlyYears()->create(['start_date' => $start, 'seats' => 30]);

    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->otherMaleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->femaleTeacher = User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female']);

    $hall = Location::factory()->create(['gender' => 'shared']);
    $this->boysCircle = Lesson::factory()->create(['name' => 'حلقة ١', 'package_id' => $this->boys->id, 'teacher_id' => $this->maleTeacher->id, 'location_id' => $hall->id]);
    $this->otherBoysCircle = Lesson::factory()->create(['name' => 'حلقة ٢', 'package_id' => $this->boys->id, 'teacher_id' => $this->otherMaleTeacher->id, 'location_id' => $hall->id]);
    $this->girlsCircle = Lesson::factory()->create(['name' => 'حلقة ٣', 'package_id' => $this->girls->id, 'teacher_id' => $this->femaleTeacher->id, 'location_id' => $hall->id]);
    $this->earlyCircle = Lesson::factory()->create(['name' => 'حلقة البراعم', 'package_id' => $this->early->id, 'teacher_id' => $this->femaleTeacher->id, 'location_id' => $hall->id]);
});

function quickPayload(array $overrides = []): array
{
    return $overrides + [
        'full_name' => 'حسين علي المحروس',
        'birth_date' => now()->subYears(10)->toDateString(),
        'gender' => 'male',
        'guardian_name' => 'علي حسن المحروس',
        'guardian_phone' => '36005001',
        'memorization_level' => 'juz_amma',
        'package_id' => test()->boys->id,
        'lesson_id' => test()->boysCircle->id,
    ];
}

function optionIds($response): array
{
    return collect($response->assertOk()->json('data'))->mapWithKeys(fn ($p) => [$p['id'] => collect($p['circles'])->pluck('id')->all()])->all();
}

it('lists only packages matching age and gender, and only circles with seats and a matching teacher', function () {
    actingAsRole('super_admin');

    $boy10 = optionIds($this->getJson('/api/enrollment/options?gender=male&birth_date='.now()->subYears(10)->toDateString()));
    expect(array_keys($boy10))->toBe([$this->boys->id])
        ->and($boy10[$this->boys->id])->toEqualCanonicalizing([$this->boysCircle->id, $this->otherBoysCircle->id]);

    $girl5 = optionIds($this->getJson('/api/enrollment/options?gender=female&birth_date='.now()->subYears(5)->toDateString()));
    expect(array_keys($girl5))->toBe([$this->early->id]);

    // A full circle and a circle whose teacher does not match the package gender drop out.
    $this->otherBoysCircle->update(['capacity' => 1]);
    LessonStudent::create(['lesson_id' => $this->otherBoysCircle->id, 'student_id' => Student::factory()->male()->create()->id, 'status' => 'active', 'joined_at' => today()]);
    Lesson::withoutEvents(fn () => $this->boysCircle->forceFill(['teacher_id' => $this->femaleTeacher->id])->save());
    $boy10 = optionIds($this->getJson('/api/enrollment/options?gender=male&birth_date='.now()->subYears(10)->toDateString()));
    expect($boy10[$this->boys->id])->toBe([]);
});

it('limits a teacher to their own circles and a supervisor to their track', function () {
    $this->actingAs($this->maleTeacher, 'sanctum');
    $options = optionIds($this->getJson('/api/enrollment/options?gender=male&birth_date='.now()->subYears(10)->toDateString()));
    expect($options)->toBe([$this->boys->id => [$this->boysCircle->id]]);

    $this->postJson('/api/enrollment', quickPayload(['lesson_id' => $this->otherBoysCircle->id]))
        ->assertStatus(422)->assertJsonValidationErrors('lesson_id');
    $this->postJson('/api/enrollment', quickPayload(['record_payment' => true, 'payment_amount_fils' => 20000]))
        ->assertStatus(422)->assertJsonValidationErrors('record_payment');
    $this->postJson('/api/enrollment', quickPayload())->assertCreated();

    actingAsRole('supervisor', ['gender' => 'female', 'track' => 'female']);
    $this->postJson('/api/enrollment', quickPayload(['full_name' => 'عباس علي المحروس', 'guardian_phone' => '36005002']))
        ->assertStatus(422)->assertJsonValidationErrors('package_id');
    // Mixed early-years circles belong to both tracks.
    $this->postJson('/api/enrollment', quickPayload(['full_name' => 'زينب علي المحروس', 'gender' => 'female', 'birth_date' => now()->subYears(5)->toDateString(), 'package_id' => $this->early->id, 'lesson_id' => $this->earlyCircle->id]))
        ->assertCreated();

    // Students and guardians have no access at all.
    actingAsRole('guardian');
    $this->getJson('/api/enrollment/options')->assertForbidden();
});

it('saves and enrolls in one step: student, guardian, wallet, invoice, circle, source, message, audit and payment', function () {
    $admin = actingAsRole('super_admin');

    $res = $this->postJson('/api/enrollment', quickPayload(['record_payment' => true, 'payment_amount_fils' => 20000]))
        ->assertCreated()->assertJsonPath('status', 'enrolled');

    $student = Student::where('student_no', $res->json('student.student_no'))->firstOrFail();
    $guardian = User::where('phone', '+97336005001')->firstOrFail();
    expect($student->guardian_user_id)->toBe($guardian->id)
        ->and($guardian->hasRole('guardian'))->toBeTrue()
        ->and(Wallet::where('student_id', $student->id)->exists())->toBeTrue()
        ->and(Invoice::where('student_id', $student->id)->where('package_id', $this->boys->id)->value('status')->value)->toBe('paid')
        ->and(LessonStudent::where('lesson_id', $this->boysCircle->id)->where('student_id', $student->id)->first()->joined_at->toDateString())->toBe(today()->toDateString())
        ->and(RegistrationRequest::where('student_id', $student->id)->first()->only(['source', 'status']))->toMatchArray(['source' => 'staff'])
        ->and(RegistrationRequest::where('student_id', $student->id)->value('status')->value)->toBe('enrolled')
        ->and(Payment::where('student_id', $student->id)->value('method')->value)->toBe('cash')
        ->and(MessageLog::where('recipient_phone', '+97336005001')->pluck('type')->map->value->all())->toContain('registration_accepted', 'payment_receipt')
        ->and(AuditLog::where('action', 'enrollment.quick')->where('user_id', $admin->id)->exists())->toBeTrue();
});

it('reuses the guardian by phone and shows the siblings', function () {
    actingAsRole('super_admin');
    $this->postJson('/api/enrollment', quickPayload())->assertCreated();

    $lookup = $this->getJson('/api/enrollment/lookup?guardian_phone=36005001')->assertOk();
    expect($lookup->json('guardian.name'))->toBe('علي حسن المحروس')
        ->and(collect($lookup->json('guardian.children'))->pluck('full_name')->all())->toBe(['حسين علي المحروس']);

    $users = User::count();
    $this->postJson('/api/enrollment', quickPayload(['full_name' => 'زهراء علي المحروس', 'gender' => 'female', 'package_id' => $this->girls->id, 'lesson_id' => $this->girlsCircle->id]))->assertCreated();

    expect(User::count())->toBe($users)
        ->and(Student::where('guardian_user_id', User::where('phone', '+97336005001')->value('id'))->count())->toBe(2);
});

it('warns about duplicates and saves only after confirmation', function () {
    actingAsRole('super_admin');
    $this->postJson('/api/enrollment', quickPayload())->assertCreated();

    // Same name and birth date, different guardian.
    $this->postJson('/api/enrollment', quickPayload(['guardian_phone' => '36005009']))->assertStatus(422)->assertJsonValidationErrors('duplicate');
    // Same guardian with a child of the same name (different birth date), spelled with a different alef.
    $this->postJson('/api/enrollment', quickPayload(['full_name' => 'حسين  علي المحروس', 'birth_date' => now()->subYears(9)->toDateString()]))
        ->assertStatus(422)->assertJsonValidationErrors('duplicate');

    $lookup = $this->getJson('/api/enrollment/lookup?guardian_phone=36005001&full_name=حسين علي المحروس&birth_date='.now()->subYears(10)->toDateString());
    expect(collect($lookup->json('duplicates'))->pluck('reason')->all())->toBe(['same_name_birth_date']);

    $this->postJson('/api/enrollment', quickPayload(['guardian_phone' => '36005009', 'confirm_duplicate' => true]))->assertCreated();
});

it('rolls everything back if any step fails', function () {
    actingAsRole('super_admin');
    $this->mock(WalletService::class, function ($mock) {
        $mock->shouldReceive('ensure')->andReturnUsing(fn ($s) => Wallet::firstOrCreate(['student_id' => $s->id], ['balance_fils' => 0]));
        $mock->shouldReceive('createInvoice')->andThrow(new RuntimeException('invoice failed'));
    });
    $counts = fn () => [Student::count(), User::count(), RegistrationRequest::count(), LessonStudent::count(), Wallet::count(), MessageLog::count()];
    $before = $counts();

    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson('/api/enrollment', quickPayload()))->toThrow(RuntimeException::class);

    expect($counts())->toBe($before);
});

it('puts the student on the waitlist when the package is full', function () {
    actingAsRole('super_admin');
    $this->boys->update(['seats' => 1]);
    $this->postJson('/api/enrollment', quickPayload())->assertCreated();

    $payload = quickPayload(['full_name' => 'سجاد جعفر الستراوي', 'guardian_phone' => '36005003']);
    $this->postJson('/api/enrollment', $payload)->assertStatus(422)->assertJsonValidationErrors('package_id');
    $this->getJson('/api/enrollment/options?gender=male&birth_date='.now()->subYears(10)->toDateString())->assertJsonPath('data.0.is_full', true);

    $res = $this->postJson('/api/enrollment', ['waitlist' => true, 'lesson_id' => null] + $payload)->assertCreated()->assertJsonPath('status', 'waitlist');
    expect($res->json('student'))->toBeNull()
        ->and(RegistrationRequest::where('request_no', $res->json('request_no'))->first()->only(['source', 'waitlist_position']))->toBe(['source' => 'staff', 'waitlist_position' => 1]);
});

it('previews an Excel sheet with row errors and enrolls the valid rows', function () {
    actingAsRole('super_admin');
    Student::factory()->male()->create(['full_name' => 'كاظم حسن الدرازي', 'birth_date' => now()->subYears(9)->toDateString()]);

    $rows = [
        ['full_name', 'birth_date', 'gender', 'guardian_name', 'guardian_phone', 'student_phone', 'memorization_level', 'circle_id'],
        ['مجتبى جعفر الستراوي', now()->subYears(9)->toDateString(), 'ذكر', 'جعفر محمد الستراوي', '36005010', '', 'juz_amma', $this->boysCircle->id],
        ['رقية مهدي السماهيجي', now()->subYears(5)->toDateString(), 'أنثى', 'مهدي رضا السماهيجي', '36005011', '', 'none', $this->earlyCircle->id],
        ['فاطمة حسن آل عباس', now()->subYears(10)->toDateString(), 'female', 'حسن كاظم آل عباس', '36005012', '', 'none', $this->boysCircle->id], // girl in a boys circle
        ['كاظم حسن الدرازي', now()->subYears(9)->toDateString(), 'male', 'حسن محمد الدرازي', '36005013', '', 'none', $this->boysCircle->id], // duplicate
        ['', '', '', '', '', '', '', ''],
    ];
    $raw = Excel::raw(new class($rows) implements FromArray
    {
        public function __construct(private array $rows) {}

        public function array(): array
        {
            return $this->rows;
        }
    }, ExcelFormat::XLSX);
    $file = UploadedFile::fake()->createWithContent('students.xlsx', $raw);

    $preview = $this->postJson('/api/enrollment/import/preview', ['file' => $file])->assertOk();
    expect($preview->json('valid'))->toBe(2)->and($preview->json('invalid'))->toBe(1)->and($preview->json('warnings'))->toBe(1)
        ->and($preview->json('rows.2.errors'))->toHaveKey('package_id')
        ->and($preview->json('rows.3.warnings'))->toHaveCount(1);

    $commit = $this->postJson('/api/enrollment/import', ['rows' => $preview->json('rows')])->assertOk();
    expect(collect($commit->json('enrolled'))->pluck('full_name')->all())->toBe(['مجتبى جعفر الستراوي', 'رقية مهدي السماهيجي'])
        ->and(collect($commit->json('skipped'))->pluck('row')->all())->toBe([4, 5])
        ->and(RegistrationRequest::where('source', 'staff')->count())->toBe(2);

    $this->get('/api/enrollment/import/template')->assertOk()->assertDownload('quick-enrollment-template.xlsx');
});
