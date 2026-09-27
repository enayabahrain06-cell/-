<?php

namespace App\Http\Requests\Lessons;

use App\Enums\SessionStatus;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('session')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', SessionStatus::rule()],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
