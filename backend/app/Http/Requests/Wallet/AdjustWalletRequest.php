<?php

namespace App\Http\Requests\Wallet;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class AdjustWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Track-aware: WalletPolicy::adjust also checks the student's gender track.
        $student = $this->route('student');

        return $student instanceof \App\Models\Student
            && ($this->user()?->can('adjust', [\App\Models\Wallet::class, $student]) ?? false);
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
            'amount_fils' => ['required', 'integer', 'not_in:0', 'min:-100000000', 'max:100000000'],
            'note' => ['required', 'string', 'min:3', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
