<?php

namespace App\Http\Requests\Registration;

use App\Enums\PackageGender;
use App\Enums\PackageStatus;
use App\Enums\WeekDay;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('packages.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('price_fils') && $this->filled('price')) {
            $this->merge(['price_fils' => Money::fromUnits($this->input('price'))]);
        }
    }

    public function rules(): array
    {
        $sometimes = $this->isMethod('PUT') || $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'name' => [$sometimes, 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'min_age' => [$sometimes, 'integer', 'min:3', 'max:99'],
            'max_age' => [$sometimes, 'integer', 'min:3', 'max:99', 'gte:min_age'],
            'gender' => [$sometimes, PackageGender::rule()],
            'seats' => [$sometimes, 'integer', 'min:1', 'max:1000'],
            'price_fils' => [$sometimes, 'integer', 'min:0'],
            'days' => [$sometimes, 'array', 'min:1'],
            'days.*' => [Rule::in(WeekDay::values())],
            'start_time' => [$sometimes, 'date_format:H:i'],
            'end_time' => [$sometimes, 'date_format:H:i', 'after:start_time'],
            'start_date' => [$sometimes, 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'term' => ['nullable', 'string', 'max:60'],
            'plan_ayahs' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', PackageStatus::rule()],
        ];
    }
}
