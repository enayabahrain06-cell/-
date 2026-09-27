<?php

namespace Database\Factories;

use App\Models\Lesson;
use App\Models\LessonSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LessonSession> */
class LessonSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lesson_id' => Lesson::factory(),
            'session_date' => now()->toDateString(),
            'start_time' => '16:00:00',
            'end_time' => '17:30:00',
            'location_id' => fn (array $attrs) => Lesson::find($attrs['lesson_id'])?->location_id,
            'status' => 'scheduled',
        ];
    }
}
