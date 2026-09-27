<?php

namespace App\Http\Requests\Students;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\MemorizationLevel;
use App\Enums\StudentStatus;
use Illuminate\Foundation\Http\FormRequest;

/** Phones are NOT editable here: they belong to the linked users (guardian / student) and sync automatically. */
class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student')) ?? false;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'birth_date' => ['sometimes', 'date', 'before:today'],
            'gender' => ['sometimes', Gender::rule()],
            'guardian_name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'memorization_level' => ['sometimes', MemorizationLevel::rule()],
            'locale' => ['sometimes', Locale::rule()],
            'status' => ['sometimes', StudentStatus::rule()],
            'yearly_target_ayahs' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
