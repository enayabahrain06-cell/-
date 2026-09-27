<?php

namespace App\Http\Requests\Lessons;

use App\Enums\BookingSource;
use App\Models\Location;
use App\Support\WeekDays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Location::class) ?? false;
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
        $sometimes = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'location_id' => [$sometimes, 'integer', 'exists:locations,id'],
            'title' => [$sometimes, 'string', 'max:150'],
            'source' => ['nullable', Rule::in([BookingSource::Manual->value, BookingSource::Event->value])],
            'booking_date' => [$sometimes, 'date_format:Y-m-d'],
            'start_time' => [$sometimes, 'date_format:H:i:s'],
            'end_time' => [$sometimes, 'date_format:H:i:s', 'after:start_time'],
        ];
    }
}
