<?php

namespace App\Http\Requests\Wallet;

use App\Enums\PaymentMethod;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('refunds.manage') ?? false;
    }

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
            'amount_fils' => ['required', 'integer', 'min:1'],
            'method' => ['required', PaymentMethod::rule()],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
