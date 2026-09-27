<?php

namespace App\Http\Requests\Admin;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone')) ?? $this->input('phone')]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'string', 'regex:/^\+\d{8,15}$/', Rule::unique('users', 'phone')->ignore($id)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($id)],
            'password' => ['nullable', 'string', 'min:8', 'max:200'],
            'gender' => ['nullable', Gender::rule()],
            'locale' => ['nullable', Locale::rule()],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(config('ahl.staff_roles'))],
            'teacher.gender' => ['nullable', Gender::rule()],
            'teacher.specialization' => ['nullable', 'string', 'max:120'],
            'track' => ['nullable', \App\Enums\Track::rule()],
        ];
    }

    /** Teachers must have a gender (teacher.gender or gender): it decides which track they may teach. */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $existing = $this->route('user');
            $roles = $this->input('roles', $existing?->roles->pluck('name')->all() ?? []);
            if (! in_array('teacher', $roles, true)) {
                return;
            }
            $gender = $this->input('teacher.gender') ?? $this->input('gender') ?? $existing?->teacher?->gender?->value ?? $existing?->gender;
            if (! $gender) {
                $v->errors()->add('teacher.gender', __('gender.teacher_gender_required'));
            }
        });
    }
}
