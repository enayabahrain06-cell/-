<?php

namespace App\Http\Requests\Exams;

use App\Enums\ExamType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('exam')) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'lesson_id' => ['nullable', 'exists:lessons,id'],
            'type' => ['sometimes', ExamType::rule()],
            'exam_date' => ['sometimes', 'date'],
            'opens_at' => ['sometimes', 'date'],
            'closes_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'total_marks' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'pass_mark' => ['sometimes', 'integer', 'min:0'],
            'syllabus' => ['nullable', 'string', 'max:5000'],
            'randomize' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $exam = $this->route('exam');
            $opens = $this->input('opens_at', $exam->opens_at);
            $closes = $this->input('closes_at', $exam->closes_at);
            if (strtotime((string) $closes) <= strtotime((string) $opens)) {
                $v->errors()->add('closes_at', __('validation.after', ['attribute' => 'closes_at', 'date' => 'opens_at']));
            }
            $total = (int) $this->input('total_marks', $exam->total_marks);
            if ((int) $this->input('pass_mark', $exam->pass_mark) > $total) {
                $v->errors()->add('pass_mark', __('validation.lte.numeric', ['attribute' => 'pass_mark', 'value' => $total]));
            }
            if (! $this->input('package_id', $exam->package_id) && ! $this->input('lesson_id', $exam->lesson_id)) {
                $v->errors()->add('lesson_id', __('exams.package_or_lesson'));
            }
            // Gender separation: package and circle must be in the same track, and inside the actor track.
            $pg = \App\Support\GenderRules::packageGender((int) ($this->input('package_id', $exam->package_id)) ?: null);
            $lg = \App\Support\GenderRules::lessonGender((int) ($this->input('lesson_id', $exam->lesson_id)) ?: null);
            if ($pg && $lg && $pg !== $lg) {
                $v->errors()->add('lesson_id', __('gender.package_mismatch'));
            }
            \App\Support\GenderRules::check($v, $this->user(), $pg ?? $lg);
        });
    }
}
