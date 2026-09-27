<?php

namespace App\Http\Requests\Lessons;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location instanceof Location
            ? ($this->user()?->can('update', $location) ?? false)
            : ($this->user()?->can('create', Location::class) ?? false);
    }

    public function rules(): array
    {
        $sometimes = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $id = $this->route('location')?->id;

        return [
            'name' => [$sometimes, 'string', 'max:120'],
            'code' => ['nullable', 'string', 'max:30', Rule::unique('locations', 'code')->ignore($id)],
            'address' => ['nullable', 'string', 'max:2000'],
            'map_link' => ['nullable', 'url', 'max:500'],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'gender' => ['sometimes', \App\Enums\LocationGender::rule()],
        ];
    }

    /** Limiting a hall to one gender is refused while the other gender still uses it. */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $location = $this->route('location');
            $gender = $this->input('gender');
            if (! $location || ! $gender || $gender === \App\Enums\LocationGender::Shared->value) {
                return;
            }
            $other = $gender === 'male' ? 'female' : 'male';
            $inUse = \App\Models\Lesson::where('location_id', $location->id)->where('gender', $other)->where('status', 'active')->exists()
                || \App\Models\LocationBooking::where('location_id', $location->id)->where('gender', $other)->where('booking_date', '>=', today()->toDateString())->exists();
            if ($inUse) {
                $v->errors()->add('gender', __('gender.hall_in_use'));
            }
        });
    }
}
