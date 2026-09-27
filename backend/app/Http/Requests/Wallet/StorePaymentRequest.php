<?php

namespace App\Http\Requests\Wallet;

use App\Enums\PaymentMethod;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payments.record') ?? false;
    }

    /** Accept either amount_fils (int) or amount (BHD decimal, e.g. "20.500"). */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('amount_fils') && $this->filled('amount')) {
            $this->merge(['amount_fils' => Money::fromUnits($this->input('amount'))]);
        }
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'amount_fils' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', PaymentMethod::rule()],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
            'receipt_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
            'notify' => ['nullable', 'boolean'],
        ];
    }
}
