<?php

namespace App\Http\Requests\Evaluations;

use App\Models\Evaluation;
use Illuminate\Foundation\Http\FormRequest;

class SaveMonthlyEvaluationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('record', [Evaluation::class, $this->route('lesson'), $this->subjectId()]) ?? false;
    }

    public function rules(): array
    {
        return ['period' => ['required', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')]]
            + EvaluationRules::entries(withProgress: false, quran: EvaluationRules::isQuran($this->givenSubject()));
    }

    public function subjectId(): ?int
    {
        return EvaluationRules::subjectId($this->givenSubject());
    }

    private function givenSubject(): ?int
    {
        $v = $this->input('subject_id');

        return is_numeric($v) ? (int) $v : null;
    }
}
