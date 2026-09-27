<?php

namespace App\Http\Requests\Exams;

use App\Enums\QuestionType;
use Illuminate\Foundation\Http\FormRequest;

class QuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('exam')) ?? false;
    }

    public function rules(): array
    {
        $update = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $req = $update ? 'sometimes' : 'required';

        return [
            'type' => [$req, QuestionType::rule()],
            'prompt' => [$req, 'string', 'max:5000'],
            'marks' => [$req, 'integer', 'min:0', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'options' => ['nullable', 'array', 'max:10'],
            'options.*.key' => ['required_with:options', 'string', 'max:10'],
            'options.*.text' => ['required_with:options', 'string', 'max:2000'],
            'correct_answer' => ['nullable', 'array'],
            'correct_answer.key' => ['nullable', 'string', 'max:10'],
            'correct_answer.value' => ['nullable', 'boolean'],
            'correct_answer.text' => ['nullable', 'string', 'max:2000'],
            'correct_answer.alternatives' => ['nullable', 'array', 'max:10'],
            'correct_answer.alternatives.*' => ['string', 'max:2000'],
            'correct_answer.order' => ['nullable', 'array', 'max:10'],
            'correct_answer.order.*' => ['string', 'max:10'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $type = $this->input('type', $this->route('question')?->type?->value);
            $opts = $this->input('options', $this->route('question')?->options);
            $ans = $this->input('correct_answer', $this->route('question')?->correct_answer) ?? [];
            $keys = array_map(fn ($o) => (string) ($o['key'] ?? ''), $opts ?? []);

            switch ($type) {
                case QuestionType::Mcq->value:
                    if (count($keys) < 2) {
                        $v->errors()->add('options', __('exams.q_mcq_options'));
                    }
                    if (! isset($ans['key']) || ! in_array((string) $ans['key'], $keys, true)) {
                        $v->errors()->add('correct_answer.key', __('exams.q_mcq_answer'));
                    }
                    break;
                case QuestionType::TrueFalse->value:
                    if (! array_key_exists('value', $ans)) {
                        $v->errors()->add('correct_answer.value', __('exams.q_tf_answer'));
                    }
                    break;
                case QuestionType::CompleteVerse->value:
                    if (trim((string) ($ans['text'] ?? '')) === '') {
                        $v->errors()->add('correct_answer.text', __('exams.q_text_answer'));
                    }
                    break;
                case QuestionType::OrderVerses->value:
                    $order = array_map('strval', $ans['order'] ?? []);
                    if (count($keys) < 2 || count($order) !== count($keys) || array_diff($order, $keys) || array_diff($keys, $order)) {
                        $v->errors()->add('correct_answer.order', __('exams.q_order_answer'));
                    }
                    break;
            }
        });
    }
}
