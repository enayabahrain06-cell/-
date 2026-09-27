<?php

namespace App\Http\Requests\Evaluations;

use App\Models\Evaluation;
use Illuminate\Foundation\Http\FormRequest;

class SaveDailyEvaluationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('record', [Evaluation::class, $this->route('session')->lesson]) ?? false;
    }

    public function rules(): array
    {
        return EvaluationRules::entries(withProgress: true);
    }
}
