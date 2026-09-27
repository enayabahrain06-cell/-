<?php

namespace App\Http\Requests\Lessons;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('changeLocation', $this->route('lesson')) ?? false;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['one_day', 'all_upcoming'])],
            'date' => ['required_if:mode,one_day', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('is_active', true)],
            'notify' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $lesson = $this->route('lesson');
            if ($this->filled('location_id') && ! \App\Support\GenderRules::locationAccepts((int) $this->input('location_id'), $lesson->gender)) {
                $v->errors()->add('location_id', __('gender.location_mismatch'));
            }
        });
    }
}
