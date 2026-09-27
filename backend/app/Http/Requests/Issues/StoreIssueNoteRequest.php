<?php

namespace App\Http\Requests\Issues;

use App\Enums\IssueStatus;
use Illuminate\Foundation\Http\FormRequest;

class StoreIssueNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('addNote', $this->route('issue')) ?? false;
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'min:2', 'max:2000'],
            'noted_on' => ['nullable', 'date', 'before_or_equal:today'],
            'status' => ['nullable', IssueStatus::rule()],
            'next_follow_up_date' => ['nullable', 'date'],
        ];
    }
}
