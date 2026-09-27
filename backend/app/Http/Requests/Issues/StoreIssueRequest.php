<?php

namespace App\Http\Requests\Issues;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\TajweedAspect;
use App\Models\StudentIssue;
use Illuminate\Foundation\Http\FormRequest;

class StoreIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [StudentIssue::class, $this->route('student')]) ?? false;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', IssueCategory::rule()],
            'subcategory' => ['nullable', TajweedAspect::rule()],
            'description' => ['required', 'string', 'min:3', 'max:2000'],
            'action_plan' => ['nullable', 'string', 'max:2000'],
            'severity' => ['required', IssueSeverity::rule()],
            'next_follow_up_date' => ['nullable', 'date', 'after_or_equal:today'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'evaluation_id' => ['nullable', 'integer', 'exists:evaluations,id'],
        ];
    }
}
