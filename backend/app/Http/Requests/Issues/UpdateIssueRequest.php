<?php

namespace App\Http\Requests\Issues;

use App\Enums\IssueCategory;
use App\Enums\IssueSeverity;
use App\Enums\IssueStatus;
use App\Enums\TajweedAspect;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('issue')) ?? false;
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', IssueCategory::rule()],
            'subcategory' => ['nullable', TajweedAspect::rule()],
            'description' => ['sometimes', 'string', 'min:3', 'max:2000'],
            'action_plan' => ['nullable', 'string', 'max:2000'],
            'severity' => ['sometimes', IssueSeverity::rule()],
            'status' => ['sometimes', IssueStatus::rule()],
            'next_follow_up_date' => ['nullable', 'date'],
        ];
    }
}
