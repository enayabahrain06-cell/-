<?php

namespace App\Http\Requests\Registration;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\MemorizationLevel;
use App\Models\Package;
use App\Services\Registration\PackageSuitability;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Public self-registration. Age and gender suitability are enforced here even if the client is bypassed. */
class StoreRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) setting('registration.open', true);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'guardian_phone' => PhoneNumber::normalize($this->input('guardian_phone')) ?? $this->input('guardian_phone'),
            'student_phone' => $this->filled('student_phone') ? (PhoneNumber::normalize($this->input('student_phone')) ?? $this->input('student_phone')) : null,
            'birth_date' => $this->filled('birth_date') ? PhoneNumber::toLatinDigits((string) $this->input('birth_date')) : null,
        ]);
    }

    public function rules(): array
    {
        $photoRequired = (bool) setting('registration.photo_required', false);

        return [
            'package_id' => ['required', 'integer', 'exists:packages,id'],
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'birth_date' => ['required', 'date', 'before:today', 'after:'.now()->subYears(80)->toDateString()],
            'gender' => ['required', Gender::rule()],
            'student_phone' => ['nullable', 'string', 'regex:/^\+\d{8,15}$/'],
            'guardian_name' => ['required', 'string', 'min:3', 'max:150'],
            'guardian_phone' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'memorization_level' => ['required', MemorizationLevel::rule()],
            'locale' => ['nullable', Locale::rule()],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => [$photoRequired ? 'required' : 'nullable', 'file', 'mimes:jpg,jpeg,png,heic,heif', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $package = Package::find($this->input('package_id'));
            if (! $package) {
                return;
            }
            $check = PackageSuitability::check($package, Carbon::parse($this->input('birth_date')), Gender::from($this->input('gender')));
            if (! $check['suitable']) {
                $v->errors()->add('package_id', __('registration.errors.'.$check['reason'], [
                    'age' => $check['age_at_start'], 'min' => $package->min_age, 'max' => $package->max_age,
                ]));
            }
        });
    }
}
