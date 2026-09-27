<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDays(14)->startOfDay();

        return [
            'name' => 'باقة الحفظ — الفصل الأول',
            'name_ar' => 'باقة الحفظ — الفصل الأول',
            'name_en' => 'Memorization Package — Term 1',
            'description' => null,
            'min_age' => 7,
            'max_age' => 12,
            'gender' => 'male',
            'seats' => 30,
            'price_fils' => 20000,
            'days' => ['sat', 'mon', 'wed'],
            'start_time' => '16:00:00',
            'end_time' => '17:30:00',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addMonths(4)->toDateString(),
            'term' => '2026-2027 / 1',
            'plan_ayahs' => 600,
            'status' => 'open',
        ];
    }

    public function girls(): static
    {
        return $this->state(fn () => ['gender' => 'female', 'name' => 'باقة الحفظ — بنات', 'name_en' => 'Memorization Package — Girls']);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['status' => 'closed']);
    }
}
