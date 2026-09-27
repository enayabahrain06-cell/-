<?php

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;

class PaperScoresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('grade', $this->route('exam')) ?? false;
    }

    public function rules(): array
    {
        $max = (int) $this->route('exam')->total_marks;

        return [
            'scores' => ['required', 'array', 'min:1'],
            'scores.*.student_id' => ['required', 'integer', 'exists:students,id'],
            'scores.*.score' => ['required', 'integer', 'min:0', "max:{$max}"],
            'scores.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
