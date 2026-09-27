<?php

namespace App\Http\Requests\Admin;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', \App\Models\User::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone')) ?? $this->input('phone')]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+\d{8,15}$/', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:200'],
            'gender' => ['nullable', Gender::rule()],
            'locale' => ['nullable', Locale::rule()],
            'is_active' => ['nullable', 'boolean'],
            'roles' => ['required', 'array', 'min:1'],
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
