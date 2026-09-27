<?php

namespace App\Http\Requests\Exams;

use App\Enums\ExamType;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;

class StoreExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Exam::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'package_id' => ['nullable', 'required_without:lesson_id', 'exists:packages,id'],
            'lesson_id' => ['nullable', 'required_without:package_id', 'exists:lessons,id'],
            'type' => ['required', ExamType::rule()],
            'exam_date' => ['required', 'date'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'total_marks' => ['required', 'integer', 'min:1', 'max:10000'],
            'pass_mark' => ['required', 'integer', 'min:0', 'lte:total_marks'],
            'syllabus' => ['nullable', 'string', 'max:5000'],
            'randomize' => ['nullable', 'boolean'],
        ];
    }
}
