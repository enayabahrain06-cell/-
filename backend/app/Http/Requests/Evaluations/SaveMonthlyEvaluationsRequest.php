<?php

namespace App\Http\Requests\Evaluations;

use App\Models\Evaluation;
use Illuminate\Foundation\Http\FormRequest;

class SaveMonthlyEvaluationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('record', [Evaluation::class, $this->route('lesson')]) ?? false;
    }

    public function rules(): array
    {
        return ['period' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')]] + EvaluationRules::entries(withProgress: false);
    }
}
