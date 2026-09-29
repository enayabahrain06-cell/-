<?php

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;

/** One student's written answers on a paper exam, entered by the teacher for automatic grading. */
class PaperAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('grade', $this->route('exam')) ?? false;
    }

    public function rules(): array
    {
        return [
            'answers' => ['present', 'array'],
            'answers.*.question_id' => ['required', 'integer'],
            // Same shapes as the online player: {key} | {value} | {text} | {order: [...]}; null = left blank.
            'answers.*.answer' => ['nullable', 'array'],
            'answers.*.answer.key' => ['nullable', 'string', 'max:10'],
            'answers.*.answer.value' => ['nullable', 'boolean'],
            'answers.*.answer.text' => ['nullable', 'string', 'max:2000'],
            'answers.*.answer.order' => ['nullable', 'array', 'max:10'],
            'answers.*.answer.order.*' => ['string', 'max:10'],
            // Recitation only: the teacher's score (capped at the question's marks).
            'answers.*.score' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
