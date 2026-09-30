<?php

namespace App\Http\Requests\Evaluations;

use App\Models\Evaluation;
use Illuminate\Foundation\Http\FormRequest;

class SaveDailyEvaluationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('record', [Evaluation::class, $this->route('session')->lesson, $this->subjectId()]) ?? false;
    }

    public function rules(): array
    {
        return EvaluationRules::entries(withProgress: true, quran: EvaluationRules::isQuran($this->givenSubject()))
            + ['division_id' => ['nullable', 'integer', 'exists:divisions,id']];
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
