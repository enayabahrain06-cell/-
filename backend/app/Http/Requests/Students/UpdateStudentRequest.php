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

    protected function prepareForValidation(): void
    {
        if ($this->exists('cpr')) {
            $this->merge(['cpr' => \App\Support\Cpr::normalize($this->input('cpr'))]);
        }
        if ($this->exists('address')) {
            $this->merge(['address' => \App\Support\Cpr::address($this->input('address'))]);
        }
    }

    public function rules(): array
    {
        return [
            'cpr' => \App\Support\Cpr::rules(),
            'address' => \App\Support\Cpr::addressRules(),
            'full_name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'birth_date' => ['sometimes', 'date', 'before:today'],
            'gender' => ['sometimes', Gender::rule()],
            'guardian_name' => ['sometimes', 'string', 'min:3', 'max:150'],
            'memorization_level' => ['sometimes', MemorizationLevel::rule()],
            'locale' => ['sometimes', Locale::rule()],
            'status' => ['sometimes', StudentStatus::rule()],
            'yearly_target_ayahs' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'photo_consent_withheld' => ['sometimes', 'boolean'], // عدم الموافقة على التصوير
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            if ($holder = \App\Support\Cpr::holder($this->input('cpr'), $this->route('student')->id)) {
                $v->errors()->add('cpr', \App\Support\Cpr::takenMessage($holder));
            }
        });
    }
}
