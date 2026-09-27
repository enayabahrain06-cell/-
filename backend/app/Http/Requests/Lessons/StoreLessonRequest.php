<?php

namespace App\Http\Requests\Lessons;

use App\Enums\LessonStatus;
use App\Enums\WeekDay;
use App\Models\Lesson;
use App\Support\WeekDays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $lesson = $this->route('lesson');

        return $lesson instanceof Lesson
            ? ($this->user()?->can('update', $lesson) ?? false)
            : ($this->user()?->can('create', Lesson::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        foreach (['start_time', 'end_time'] as $k) {
            if ($this->filled($k)) {
                $this->merge([$k => WeekDays::time($this->input($k))]);
            }
        }
    }

    public function rules(): array
    {
        $req = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:150'],
            'package_id' => [$req, 'integer', 'exists:packages,id'],
            'teacher_id' => [$req, 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'days' => [$req, 'array', 'min:1'],
            'days.*' => ['string', 'distinct', WeekDay::rule()],
            'start_time' => [$req, 'date_format:H:i:s'],
            'end_time' => [$req, 'date_format:H:i:s', 'after:start_time'],
            'capacity' => [$req, 'integer', 'min:1', 'max:500'],
            'start_date' => [$req, 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'status' => ['nullable', LessonStatus::rule()],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $teacherId = $this->input('teacher_id');
            if ($teacherId && ! \App\Models\User::whereKey($teacherId)->role('teacher')->exists()) {
                $v->errors()->add('teacher_id', __('lessons.teacher_role_required'));
            }

            // Gender separation: the circle takes its package gender; teacher and hall must match it.
            $lesson = $this->route('lesson');
            $packageId = $this->input('package_id') ?? $lesson?->package_id;
            $gender = \App\Support\GenderRules::packageGender($packageId ? (int) $packageId : null);
            \App\Support\GenderRules::check(
                $v,
                $this->user(),
                $gender,
                (int) ($teacherId ?? $lesson?->teacher_id) ?: null,
                (int) ($this->input('location_id') ?? $lesson?->location_id) ?: null,
            );
            if ($lesson && $gender && $lesson->gender && $lesson->gender->value !== $gender) {
                $v->errors()->add('package_id', __('gender.package_mismatch'));
            }
        });
    }
}
