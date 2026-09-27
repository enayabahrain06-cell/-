<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\RegistrationRequest;
use Database\Factories\Support\BahrainiNames;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegistrationRequest> */
class RegistrationRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'request_no' => 'R'.now()->format('ym').fake()->unique()->numerify('####'),
            'package_id' => Package::factory(),
            'full_name' => BahrainiNames::full(),
            'birth_date' => now()->subYears(10)->toDateString(),
            'gender' => 'male',
            'student_phone' => null,
            'guardian_name' => BahrainiNames::full(),
            'guardian_phone' => '+9733'.fake()->unique()->numerify('#######'),
            'memorization_level' => 'juz_amma',
            'locale' => 'ar',
            'age_at_start' => 10,
            'status' => 'pending',
        ];
    }
}
