<?php

use App\Models\AuditLog;
use App\Models\Lesson;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Models\Student;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->package = Package::factory()->create(['min_age' => 7, 'max_age' => 12, 'gender' => 'male', 'seats' => 10, 'start_date' => now()->addWeeks(2)->toDateString()]);
    $this->circle = Lesson::factory()->create(['package_id' => $this->package->id, 'capacity' => 10]);
});

function cprEnrollPayload(array $overrides = []): array
{
    return $overrides + [
        'full_name' => 'حسين علي المحروس',
        'birth_date' => now()->subYears(10)->toDateString(),
        'gender' => 'male',
        'guardian_name' => 'علي حسن المحروس',
        'guardian_phone' => '36005001',
        'memorization_level' => 'juz_amma',
        'package_id' => test()->package->id,
        'lesson_id' => test()->circle->id,
    ];
}

it('stores the CPR on quick enrollment, accepting Arabic digits and spaces, and refuses the same CPR twice', function () {
    actingAsRole('super_admin');

    $id = $this->postJson('/api/enrollment', cprEnrollPayload(['cpr' => '٠١٠٥ ١٢٣٤٥']))->assertCreated()->json('student.id');
    expect(Student::find($id)->cpr)->toBe('010512345');

    $this->postJson('/api/enrollment', cprEnrollPayload(['cpr' => '010512345', 'full_name' => 'اسم آخر', 'guardian_phone' => '36005002', 'confirm_duplicate' => true]))
        ->assertStatus(422)->assertJsonValidationErrors('cpr');
    $this->postJson('/api/enrollment', cprEnrollPayload(['cpr' => '12345', 'guardian_phone' => '36005003']))->assertStatus(422)->assertJsonValidationErrors('cpr');
});

it('lets staff set a CPR on the profile, keeps it unique, audits it and finds the student by it', function () {
    actingAsRole('super_admin');
    $a = Student::factory()->create(['cpr' => '010100001']);
    $b = Student::factory()->create();

    $this->putJson("/api/students/{$b->id}", ['cpr' => '010100001'])->assertStatus(422)->assertJsonValidationErrors('cpr');
    $this->putJson("/api/students/{$b->id}", ['cpr' => '010100002'])->assertOk()->assertJsonPath('data.cpr', '010100002');
    $this->putJson("/api/students/{$a->id}", ['cpr' => '010100001'])->assertOk(); // its own CPR is not a duplicate

    expect(AuditLog::where('action', 'student.updated')->where('auditable_id', $b->id)->exists())->toBeTrue();
    expect(collect($this->getJson('/api/students?search=010100002')->json('data'))->pluck('id')->all())->toBe([$b->id]);
});

it('never shows the CPR to the student or guardian', function () {
    $guardian = \App\Models\User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    Student::factory()->create(['cpr' => '010100003', 'guardian_user_id' => $guardian->id]);
    $this->actingAs($guardian, 'sanctum');

    $this->getJson('/api/me/students')->assertOk()->assertJsonMissingPath('data.0.cpr');
});

it('lets staff correct a request from the ID card and re-checks the package', function () {
    actingAsRole('supervisor');
    $req = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'birth_date' => now()->subYears(10)->toDateString()]);

    $this->putJson("/api/registrations/{$req->id}", ['full_name' => 'محمد أحمد الستراوي', 'cpr' => '160112345'])->assertOk()
        ->assertJsonPath('data.cpr', '160112345')
        ->assertJsonPath('data.full_name', 'محمد أحمد الستراوي');

    // the card shows the child is too old for this package
    $this->putJson("/api/registrations/{$req->id}", ['birth_date' => now()->subYears(15)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('birth_date');

    Student::factory()->create(['cpr' => '160199999']);
    $this->putJson("/api/registrations/{$req->id}", ['cpr' => '160199999'])->assertStatus(422)->assertJsonValidationErrors('cpr');
});

it('attaches the card photo to a request and carries photo and CPR to the student on acceptance', function () {
    actingAsRole('supervisor');
    $req = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'cpr' => '160112346']);

    $this->post("/api/registrations/{$req->id}/photo", ['photo' => UploadedFile::fake()->image('card.jpg', 300, 400)], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.has_photo', true);

    $id = $this->postJson("/api/registrations/{$req->id}/accept", ['lesson_id' => $this->circle->id])->assertOk()->json('student.id');
    $student = Student::find($id);
    expect($student->cpr)->toBe('160112346')->and($student->photo_path)->not->toBeNull();

    // a second request for the same child cannot be accepted
    $again = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'cpr' => '160112346']);
    $this->postJson("/api/registrations/{$again->id}/accept", ['lesson_id' => $this->circle->id])->assertStatus(422)->assertJsonValidationErrors('cpr');
});

it('keeps the card address as one tidy line from enrollment, request correction and the profile, for staff only', function () {
    actingAsRole('super_admin');
    $padded = "Flat 12   Bldg 345    Road 678   Block 910 \n  SAAR  ";

    $id = $this->postJson('/api/enrollment', cprEnrollPayload(['address' => $padded]))->assertCreated()->json('student.id');
    expect(Student::find($id)->address)->toBe('Flat 12 Bldg 345 Road 678 Block 910 SAAR');

    $this->putJson("/api/students/{$id}", ['address' => 'Flat 1 Bldg 2 Road 3 Block 4 Hamad Town'])->assertOk()
        ->assertJsonPath('data.address', 'Flat 1 Bldg 2 Road 3 Block 4 Hamad Town');
    $this->putJson("/api/students/{$id}", ['address' => str_repeat('x', 501)])->assertStatus(422)->assertJsonValidationErrors('address');

    $req = RegistrationRequest::factory()->create(['package_id' => $this->package->id]);
    $this->putJson("/api/registrations/{$req->id}", ['address' => '  Flat 5  Road 6 '])->assertOk()->assertJsonPath('data.address', 'Flat 5 Road 6');
    $sid = $this->postJson("/api/registrations/{$req->id}/accept", ['lesson_id' => $this->circle->id])->assertOk()->json('student.id');
    expect(Student::find($sid)->address)->toBe('Flat 5 Road 6');

    // Not in list rows, and not for guardians.
    $guardian = \App\Models\User::factory()->withoutPassword()->create();
    $guardian->assignRole('guardian');
    Student::find($id)->update(['guardian_user_id' => $guardian->id]);
    $this->actingAs($guardian, 'sanctum');
    $this->getJson('/api/me/students')->assertOk()->assertJsonMissingPath('data.0.address');
});

it('accepts a public registration with a photo (it used to fail with a server error)', function () {
    $this->post('/api/public/registrations', [
        'package_id' => $this->package->id, 'full_name' => 'أحمد محمد الدوسري', 'birth_date' => now()->subYears(10)->toDateString(), 'gender' => 'male',
        'guardian_name' => 'محمد علي الدوسري', 'guardian_phone' => '36001001', 'memorization_level' => 'juz_amma',
        'photo' => UploadedFile::fake()->image('p.jpg', 600, 800),
    ], ['Accept' => 'application/json'])->assertCreated();

    expect(RegistrationRequest::latest('id')->first()->media()->where('collection', 'photo')->exists())->toBeTrue();
});
