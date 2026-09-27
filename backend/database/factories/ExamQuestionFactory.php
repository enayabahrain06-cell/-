<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\ExamQuestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExamQuestion> */
class ExamQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'type' => 'mcq',
            'prompt' => 'كم عدد آيات سورة الفاتحة؟',
            'options' => [['key' => 'a', 'text' => 'خمس'], ['key' => 'b', 'text' => 'سبع'], ['key' => 'c', 'text' => 'تسع']],
            'correct_answer' => ['key' => 'b'],
            'marks' => 2,
            'sort_order' => 1,
        ];
    }

    public function trueFalse(): static
    {
        return $this->state(fn () => ['type' => 'true_false', 'prompt' => 'سورة الإخلاص مكية.', 'options' => null, 'correct_answer' => ['value' => true]]);
    }

    public function completeVerse(): static
    {
        return $this->state(fn () => ['type' => 'complete_verse', 'prompt' => 'قُلْ هُوَ اللَّهُ ...', 'options' => null, 'correct_answer' => ['text' => 'أحد', 'alternatives' => ['أَحَدٌ']]]);
    }

    public function orderVerses(): static
    {
        return $this->state(fn () => [
            'type' => 'order_verses',
            'prompt' => 'رتّب آيات سورة الإخلاص',
            'options' => [['key' => 'k1', 'text' => 'قل هو الله أحد'], ['key' => 'k2', 'text' => 'الله الصمد'], ['key' => 'k3', 'text' => 'لم يلد ولم يولد']],
            'correct_answer' => ['order' => ['k1', 'k2', 'k3']],
        ]);
    }

    public function recitation(): static
    {
        return $this->state(fn () => ['type' => 'recitation', 'prompt' => 'اتلُ سورة الفلق', 'options' => null, 'correct_answer' => null]);
    }
}
