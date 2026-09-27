<?php

namespace App\Http\Requests\Messages;

use App\Enums\Locale;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('messages.send') ?? false;
    }

    public function rules(): array
    {
        return [
            'student_ids' => ['required_without:phones', 'array', 'max:500'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'phones' => ['required_without:student_ids', 'array', 'max:500'],
            'phones.*' => ['string', 'max:20'],
            'to' => ['nullable', 'in:guardian,student,both'],
            'body' => ['required', 'string', 'max:1000'],
            'locale' => ['nullable', Locale::rule()],
        ];
    }
}
