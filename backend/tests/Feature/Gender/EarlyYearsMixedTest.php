<?php

use App\Models\Lesson;
use App\Models\Location;
use App\Models\Package;
use App\Models\Student;
use App\Models\User;

/*
 * Early-years exception to gender separation: a "mixed" package is allowed only for ages up to 6,
 * is taught by a female teacher in a girls or shared hall, and is visible to both tracks.
 */

function mixedPackagePayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'باقة البراعم', 'min_age' => 4, 'max_age' => 6, 'gender' => 'mixed', 'seats' => 20, 'price_fils' => 10000,
        'days' => ['sun', 'tue'], 'start_time' => '16:00', 'end_time' => '17:00', 'start_date' => now()->addMonth()->toDateString(),
    ];
}

beforeEach(function () {
    $this->mixed = Package::factory()->create(['gender' => 'mixed', 'min_age' => 4, 'max_age' => 6, 'start_date' => now()->addMonth()->toDateString()]);
    $this->maleTeacher = User::factory()->role('teacher')->create(['gender' => 'male', 'track' => 'male']);
    $this->femaleTeacher = User::factory()->role('teacher')->create(['gender' => 'female', 'track' => 'female']);
});

it('accepts a mixed package only for early years', function () {
    actingAsRole('super_admin');

    $this->postJson('/api/packages', mixedPackagePayload())->assertCreated()->assertJsonPath('data.gender', 'mixed');
    $this->postJson('/api/packages', mixedPackagePayload(['max_age' => 7]))->assertStatus(422)->assertJsonValidationErrors('gender');

    // Raising the age of an existing mixed package past 6 is refused as well.
    $this->putJson("/api/packages/{$this->mixed->id}", ['max_age' => 9])->assertStatus(422)->assertJsonValidationErrors('gender');
});

it('offers the mixed package to boys and girls and accepts both registrations', function () {
    foreach (['male', 'female'] as $gender) {
        $ids = collect($this->getJson("/api/public/packages?gender={$gender}")->assertOk()->json('data'))->pluck('id');
        expect($ids)->toContain($this->mixed->id);
    }

    foreach ([['male', 'حسين علي المحروس', '36002001'], ['female', 'زينب جعفر الستراوي', '36002002']] as [$gender, $name, $phone]) {
        $this->postJson('/api/public/registrations', [
            'package_id' => $this->mixed->id, 'full_name' => $name, 'birth_date' => now()->subYears(5)->toDateString(),
            'gender' => $gender, 'guardian_name' => 'جعفر المحروس', 'guardian_phone' => $phone,
            'memorization_level' => 'none', 'locale' => 'ar',
        ])->assertCreated();
    }
});

it('needs a female teacher and a girls or shared hall for a mixed circle, and takes boys and girls', function () {
    actingAsRole('super_admin');
    $boysHall = Location::factory()->create(['gender' => 'male']);
    $girlsHall = Location::factory()->create(['gender' => 'female']);
    $shared = Location::factory()->create(['gender' => 'shared']);
    $payload = ['name' => 'حلقة البراعم', 'package_id' => $this->mixed->id, 'days' => ['sun'], 'start_time' => '16:00', 'end_time' => '17:00', 'capacity' => 12, 'start_date' => today()->toDateString()];

    $this->postJson('/api/lessons', $payload + ['teacher_id' => $this->maleTeacher->id])->assertStatus(422)->assertJsonValidationErrors('teacher_id');
    $this->postJson('/api/lessons', $payload + ['teacher_id' => $this->femaleTeacher->id, 'location_id' => $boysHall->id])->assertStatus(422)->assertJsonValidationErrors('location_id');
    $this->postJson('/api/lessons', $payload + ['teacher_id' => $this->femaleTeacher->id, 'location_id' => $shared->id])->assertCreated();
    $id = $this->postJson('/api/lessons', $payload + ['name' => 'حلقة البراعم ٢', 'teacher_id' => $this->femaleTeacher->id, 'location_id' => $girlsHall->id])
        ->assertCreated()->assertJsonPath('data.gender', 'mixed')->json('data.id');

    $boy = Student::factory()->male()->create();
    $girl = Student::factory()->female()->create();
    $this->postJson("/api/lessons/{$id}/students", ['student_ids' => [$boy->id, $girl->id]])->assertSuccessful();

    // A girls hall with a mixed circle can no longer be limited to boys.
    $this->putJson("/api/locations/{$girlsHall->id}", ['gender' => 'male'])->assertStatus(422)->assertJsonValidationErrors('gender');
});

it('shows mixed packages and circles to supervisors of both tracks', function () {
    $circle = Lesson::factory()->create(['package_id' => $this->mixed->id, 'teacher_id' => $this->femaleTeacher->id]);
    expect($circle->fresh()->gender->value)->toBe('mixed');

    foreach (['male', 'female'] as $track) {
        actingAsRole('supervisor', ['gender' => $track, 'track' => $track]);
        expect(collect($this->getJson('/api/packages')->assertOk()->json('data'))->pluck('id'))->toContain($this->mixed->id);
        expect(collect($this->getJson('/api/lessons')->assertOk()->json('data'))->pluck('id'))->toContain($circle->id);
        $this->getJson("/api/lessons/{$circle->id}")->assertOk();
    }
});
