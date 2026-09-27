<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\Location;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Lesson> */
class LessonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'حلقة الإمام نافع',
            'package_id' => Package::factory(),
            'teacher_id' => User::factory()->role('teacher'),
            'location_id' => Location::factory(),
            'days' => ['sat', 'mon', 'wed'],
            'start_time' => '16:00:00',
            'end_time' => '17:30:00',
            'capacity' => 15,
            'start_date' => now()->startOfDay()->toDateString(),
            'end_date' => now()->addMonths(4)->toDateString(),
            'status' => 'active',
        ];
    }
}
