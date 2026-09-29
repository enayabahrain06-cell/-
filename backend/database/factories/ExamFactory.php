<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Exam> */
class ExamFactory extends Factory
{
    public function definition(): array
    {
        $opens = now()->addDay()->setTime(16, 0);

        return [
            'name' => 'اختبار جزء عمّ',
            'lesson_id' => Lesson::factory(),
            'package_id' => null,
            'type' => 'online',
            'exam_date' => $opens->toDateString(),
            'opens_at' => $opens,
            'closes_at' => $opens->copy()->addHours(3),
            'duration_minutes' => 30,
            'total_marks' => 10,
            'pass_mark' => 5,
            'syllabus' => 'من سورة النبأ إلى سورة الناس',
            'randomize' => false,
            'status' => 'draft',
        ];
    }

    public function paper(): static
    {
        return $this->state(fn () => ['type' => 'paper']);
    }

    /** A placement test for a package, open now, with bands: 0–39 none, 40–69 juz amma, 70–89 five ajza, 90+ ten ajza. */
    public function placement(\App\Models\Package $package): static
    {
        return $this->openNow()->state(fn () => [
            'type' => 'placement',
            'package_id' => $package->id,
            'lesson_id' => null,
            'pass_mark' => 0,
            'level_bands' => [
                ['min' => 90, 'level' => 'ten_ajza'],
                ['min' => 70, 'level' => 'five_ajza'],
                ['min' => 40, 'level' => 'juz_amma'],
                ['min' => 0, 'level' => 'none'],
            ],
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => 'published']);
    }

    public function openNow(): static
    {
        return $this->state(fn () => [
            'status' => 'published',
            'exam_date' => now()->toDateString(),
            'opens_at' => now()->subMinutes(10),
            'closes_at' => now()->addHours(2),
        ]);
    }
}
