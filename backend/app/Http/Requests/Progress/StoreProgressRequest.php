<?php

namespace App\Http\Requests\Progress;

use Illuminate\Foundation\Http\FormRequest;

class StoreProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recordProgress', $this->route('student')) ?? false;
    }

    public function rules(): array
    {
        return ProgressRules::for() + [
            'recorded_on' => ['nullable', 'date', 'before_or_equal:today'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
        ];
    }
}
