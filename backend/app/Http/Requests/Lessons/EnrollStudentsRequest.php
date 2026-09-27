<?php

namespace App\Http\Requests\Lessons;

use Illuminate\Foundation\Http\FormRequest;

class EnrollStudentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('enroll', $this->route('lesson')) ?? false;
    }

    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
        ];
    }

    /** Boys only in boys circles, girls only in girls circles. */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $gender = $this->route('lesson')?->gender?->value;
            $ids = array_filter((array) $this->input('student_ids'), 'is_numeric');
            if ($gender && $ids && \App\Models\Student::whereIn('id', $ids)->where('gender', '!=', $gender)->exists()) {
                $v->errors()->add('student_ids', __('gender.student_mismatch'));
            }
        });
    }
}
