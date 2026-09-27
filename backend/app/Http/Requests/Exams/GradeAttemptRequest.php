<?php

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;

class GradeAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('grade', $this->route('exam')) ?? false;
    }

    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.answer_id' => ['required', 'integer', 'exists:exam_answers,id'],
            'answers.*.score' => ['required', 'integer', 'min:0', 'max:1000'],
            'answers.*.grader_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
