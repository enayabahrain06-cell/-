<?php

namespace App\Http\Requests\Enrollment;

use App\Enums\Gender;
use App\Enums\Locale;
use App\Enums\MemorizationLevel;
use App\Services\Enrollment\QuickEnrollmentService;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Staff quick enrollment. Formats here; package, circle, scope, payment and duplicate rules in QuickEnrollmentService::errors(). */
class QuickEnrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('enrollment.quick');
    }

    protected function prepareForValidation(): void
    {
        $this->merge(self::normalize($this->all()));
    }

    /** Shared with the bulk import: phones to E.164 (+973 default), digits to Latin, booleans cast. */
    public static function normalize(array $input): array
    {
        $phone = fn ($v) => filled($v) ? (PhoneNumber::normalize((string) $v) ?? $v) : null;

        return [
            'guardian_phone' => $phone($input['guardian_phone'] ?? null),
            'student_phone' => $phone($input['student_phone'] ?? null),
            'birth_date' => filled($input['birth_date'] ?? null) ? PhoneNumber::toLatinDigits((string) $input['birth_date']) : null,
            'waitlist' => filter_var($input['waitlist'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'record_payment' => filter_var($input['record_payment'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'confirm_duplicate' => filter_var($input['confirm_duplicate'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'cpr' => \App\Support\Cpr::normalize($input['cpr'] ?? null),
            'address' => \App\Support\Cpr::address($input['address'] ?? null),
            'without_package' => filter_var($input['without_package'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /** The single form may save a student with no package yet (placed later from a circle); the bulk import may not. */
    public function rules(): array
    {
        return [
            'package_id' => ['exclude_if:without_package,true', 'required', 'integer'],
            'lesson_id' => ['exclude_if:without_package,true', 'nullable', 'integer'],
            'without_package' => ['boolean'],
        ] + self::fieldRules() + [
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,heic,heif', 'max:5120'],
        ];
    }

    /** Field names in validation messages ("مستوى الحفظ" rather than "memorization level"). */
    public function attributes(): array
    {
        return __('enrollment.attributes');
    }

    public static function fieldRules(): array
    {
        return [
            'cpr' => \App\Support\Cpr::rules(),
            'address' => \App\Support\Cpr::addressRules(),
            'full_name' => ['required', 'string', 'min:3', 'max:150'],
            'birth_date' => ['required', 'date', 'before:today', 'after:'.now()->subYears(80)->toDateString()],
            'gender' => ['required', Gender::rule()],
            'guardian_name' => ['required', 'string', 'min:3', 'max:150'],
            'guardian_phone' => ['required', 'string', 'regex:/^\+\d{8,15}$/'],
            'student_phone' => ['nullable', 'string', 'regex:/^\+\d{8,15}$/', 'different:guardian_phone'],
            'memorization_level' => ['required', MemorizationLevel::rule()],
            'locale' => ['nullable', Locale::rule()],
            'package_id' => ['required', 'integer'],
            'lesson_id' => ['nullable', 'integer'],
            'waitlist' => ['boolean'],
            'record_payment' => ['boolean'],
            'payment_amount_fils' => ['nullable', 'integer', 'min:0'],
            'confirm_duplicate' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $errors = app(QuickEnrollmentService::class)->errors($this->user(), $v->getData(), $this->hasFile('photo'));
            foreach ($errors as $field => $message) {
                $v->errors()->add($field, $message);
            }
        });
    }
}
