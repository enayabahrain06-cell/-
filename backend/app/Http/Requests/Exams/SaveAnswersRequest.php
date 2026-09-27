<?php

namespace App\Http\Requests\Exams;

use Illuminate\Foundation\Http\FormRequest;

class SaveAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Students, or a guardian answering for their own child (the controller resolves and checks the child).
        return $this->user()?->student !== null || ($this->user()?->hasRole('guardian') ?? false);
    }

    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1', 'max:200'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.answer' => ['nullable'],
        ];
    }
}
