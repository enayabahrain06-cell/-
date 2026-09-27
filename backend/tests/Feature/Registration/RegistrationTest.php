<?php

use App\Models\Alert;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\Package;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;
use App\Models\Wallet;

beforeEach(function () {
    // starts in 30 days; ages 7–12 boys
    $this->package = Package::factory()->create(['min_age' => 7, 'max_age' => 12, 'gender' => 'male', 'seats' => 2, 'start_date' => now()->addDays(30)->toDateString(), 'price_fils' => 20000]);
});

function registrationPayload(array $overrides = []): array
{
    return $overrides + [
        'package_id' => test()->package->id,
        'full_name' => 'أحمد محمد الدوسري',
        'birth_date' => now()->subYears(10)->toDateString(),
        'gender' => 'male',
        'guardian_name' => 'محمد علي الدوسري',
        'guardian_phone' => '36001001',
        'memorization_level' => 'juz_amma',
        'locale' => 'ar',
    ];
}

it('computes age at the package start date on the server and rejects out-of-range ages', function () {
    // 12 today, turns 13 before the start date (in 30 days) → rejected for max_age 12
    $birth = now()->addDays(10)->subYears(13)->toDateString();
    $this->postJson('/api/public/registrations', registrationPayload(['birth_date' => $birth]))
        ->assertStatus(422)->assertJsonValidationErrors('package_id');

    // 6 today, turns 7 before the start date → accepted for min_age 7
    $birth = now()->addDays(10)->subYears(7)->toDateString();
    $res = $this->postJson('/api/public/registrations', registrationPayload(['birth_date' => $birth, 'guardian_phone' => '36001002']))->assertCreated();
    expect(RegistrationRequest::where('request_no', $res->json('request_no'))->value('age_at_start'))->toBe(7);
});

it('rejects the wrong gender even when submitted manually', function () {
    $this->postJson('/api/public/registrations', registrationPayload(['gender' => 'female']))
        ->assertStatus(422)->assertJsonValidationErrors('package_id');
});

it('rejects a closed package', function () {
    $this->package->update(['status' => 'closed']);
    $this->postJson('/api/public/registrations', registrationPayload())->assertStatus(422);
});

it('creates a pending request with a number, sends a WhatsApp confirmation and raises the dashboard alert', function () {
    $res = $this->postJson('/api/public/registrations', registrationPayload())->assertCreated();

    expect($res->json('request_no'))->toStartWith('R')
        ->and($res->json('status'))->toBe('pending')
        ->and(MessageLog::where('type', 'registration_received')->where('recipient_phone', '+97336001001')->exists())->toBeTrue()
        ->and(Alert::where('type', 'registration_request')->where('status', 'open')->exists())->toBeTrue();

    // tracking requires the matching phone
    $this->getJson("/api/public/registrations/{$res->json('request_no')}?phone=36001001")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->getJson("/api/public/registrations/{$res->json('request_no')}?phone=39999999")->assertNotFound();
});

it('puts requests on the waitlist with an explicit position when the package is full', function () {
    actingAsRole('supervisor');
    // fill the 2 seats
    foreach ([1, 2] as $i) {
        $r = RegistrationRequest::factory()->create(['package_id' => $this->package->id]);
        $this->postJson("/api/registrations/{$r->id}/accept")->assertOk();
    }
    expect($this->package->fresh()->isFull())->toBeTrue();

    $this->app['auth']->forgetGuards();
    $a = $this->postJson('/api/public/registrations', registrationPayload(['guardian_phone' => '36001010']))->assertCreated();
    $b = $this->postJson('/api/public/registrations', registrationPayload(['guardian_phone' => '36001011']))->assertCreated();

    expect($a->json('status'))->toBe('waitlist')->and($a->json('waitlist_position'))->toBe(1)
        ->and($b->json('waitlist_position'))->toBe(2);

    // public packages endpoint shows it as full / waitlist
    $this->getJson('/api/public/packages?birth_date='.now()->subYears(10)->toDateString().'&gender=male')
        ->assertOk()->assertJsonPath('data.0.is_full', true)->assertJsonPath('data.0.suitability.suitable', true);
});

it('accepting creates the guardian and student accounts, wallet, invoice and sends the login message', function () {
    $admin = actingAsRole('supervisor');
    $r = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'student_phone' => '+97336001020', 'guardian_phone' => '+97336001021']);

    $res = $this->postJson("/api/registrations/{$r->id}/accept")->assertOk();

    $student = Student::find($res->json('student.id'));
    expect($student)->not->toBeNull()
        ->and($student->guardian->phone)->toBe('+97336001021')
        ->and($student->guardian->hasRole('guardian'))->toBeTrue()
        ->and($student->user->phone)->toBe('+97336001020')
        ->and($student->user->hasRole('student'))->toBeTrue()
        ->and(Wallet::where('student_id', $student->id)->value('balance_fils'))->toBe(-20000)
        ->and(Invoice::where('student_id', $student->id)->where('amount_fils', 20000)->where('status', 'open')->exists())->toBeTrue()
        ->and($r->fresh()->status->value)->toBe('accepted')
        ->and($r->fresh()->decided_by)->toBe($admin->id)
        ->and(MessageLog::where('type', 'registration_accepted')->count())->toBe(2)
        ->and(\App\Models\AuditLog::where('action', 'registration.accepted')->exists())->toBeTrue();

    // siblings share the guardian login
    $r2 = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'guardian_phone' => '+97336001021']);
    $res2 = $this->postJson("/api/registrations/{$r2->id}/accept")->assertOk();
    expect(Student::find($res2->json('student.id'))->guardian_user_id)->toBe($student->guardian_user_id)
        ->and(User::where('phone', '+97336001021')->count())->toBe(1);

    // accepting twice fails; alert resolved when nothing is pending
    $this->postJson("/api/registrations/{$r->id}/accept")->assertStatus(422);
    expect(Alert::where('type', 'registration_request')->where('status', 'open')->exists())->toBeFalse();
});

it('re-packs waitlist positions on accept and reject', function () {
    actingAsRole('supervisor');
    $w1 = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'status' => 'waitlist', 'waitlist_position' => 1]);
    $w2 = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'status' => 'waitlist', 'waitlist_position' => 2]);
    $w3 = RegistrationRequest::factory()->create(['package_id' => $this->package->id, 'status' => 'waitlist', 'waitlist_position' => 3]);

    $this->postJson("/api/registrations/{$w1->id}/accept")->assertOk();
    expect($w2->fresh()->waitlist_position)->toBe(1)->and($w3->fresh()->waitlist_position)->toBe(2);

    $this->postJson("/api/registrations/{$w2->id}/reject", ['reason' => 'لم يحضر المقابلة'])->assertOk();
    expect($w3->fresh()->waitlist_position)->toBe(1)
        ->and(MessageLog::where('type', 'registration_rejected')->exists())->toBeTrue();

    $this->postJson("/api/registrations/{$w3->id}/waitlist")->assertOk(); // already waitlisted → no-op
    expect($w3->fresh()->waitlist_position)->toBe(1);
});

it('bulk-accepts matching requests while seats remain', function () {
    actingAsRole('supervisor');
    RegistrationRequest::factory()->count(3)->create(['package_id' => $this->package->id]);

    $res = $this->postJson('/api/registrations/bulk-accept', ['package_id' => $this->package->id])->assertOk();

    expect($res->json('accepted'))->toHaveCount(2)
        ->and($res->json('skipped'))->toHaveCount(1)
        ->and($res->json('skipped.0.reason'))->toBe('full')
        ->and(Student::count())->toBe(2);
});

it('requires a photo before acceptance when the setting is on', function () {
    actingAsRole('supervisor');
    app(\App\Services\SettingsService::class)->set('registration.photo_required', true, 'registration', 'bool');
    $r = RegistrationRequest::factory()->create(['package_id' => $this->package->id]);

    $this->postJson("/api/registrations/{$r->id}/accept")->assertStatus(422)->assertJsonValidationErrors('photo');
});

it('enforces permissions on packages and requests', function () {
    actingAsRole('teacher');
    $this->getJson('/api/registrations')->assertForbidden();
    $this->postJson('/api/packages', [])->assertForbidden();

    actingAsRole('supervisor');
    $this->postJson('/api/packages', [
        'name' => 'باقة التجويد', 'min_age' => 10, 'max_age' => 15, 'gender' => 'mixed', 'seats' => 20, 'price' => '15.500',
        'days' => ['sun', 'tue'], 'start_time' => '17:00', 'end_time' => '18:30', 'start_date' => now()->addMonth()->toDateString(), 'status' => 'open',
    ])->assertCreated()->assertJsonPath('data.price_fils', 15500)->assertJsonPath('data.seats_left', 20);

    $this->getJson('/api/packages')->assertOk();
});
