<?php

namespace Database\Factories;

use App\Models\Student;
use Database\Factories\Support\BahrainiNames;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        $gender = 'male'; // deterministic: gender separation rules depend on it; use ->female() for the girls track
        $first = fake()->randomElement(BahrainiNames::MALE);
        $father = fake()->randomElement(BahrainiNames::MALE);
        $family = fake()->randomElement(BahrainiNames::FAMILY);

        return [
            'student_no' => 'S26'.fake()->unique()->numerify('#####'),
            'full_name' => "{$first} {$father} {$family}",
            'birth_date' => fake()->dateTimeBetween('-16 years', '-6 years')->format('Y-m-d'),
            'gender' => $gender,
            'student_phone' => null,
            'guardian_name' => "{$father} ".fake()->randomElement(BahrainiNames::MALE)." {$family}",
            'guardian_phone' => '+9733'.fake()->unique()->numerify('#######'),
            'memorization_level' => fake()->randomElement(['none', 'juz_amma', 'juz_tabarak', 'five_ajza']),
            'locale' => 'ar',
            'status' => 'active',
        ];
    }

    public function male(): static
    {
        return $this->state(fn () => ['gender' => 'male', 'full_name' => BahrainiNames::full('male')]);
    }

    public function female(): static
    {
        return $this->state(fn () => ['gender' => 'female', 'full_name' => BahrainiNames::full('female')]);
    }
}
