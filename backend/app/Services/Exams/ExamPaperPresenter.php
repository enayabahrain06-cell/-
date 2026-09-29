<?php

namespace App\Services\Exams;

use App\Enums\QuestionType;
use App\Models\ExamQuestion;

/**
 * Turns stored answers ({key} | {value} | {text} | {order}) into plain text for the printable exam papers.
 * Strings come back unshaped; the Blade view passes them through pdf_ar().
 */
class ExamPaperPresenter
{
    public function __construct(private string $locale = 'ar') {}

    /** What the student answered, or null when the question was left blank. */
    public function answer(ExamQuestion $q, ?array $answer): ?string
    {
        if ($q->type === QuestionType::Recitation || $answer === null) {
            return null;
        }

        return $this->describe($q, $answer);
    }

    /** The answer key. Recitation has none (the teacher scores it). */
    public function correct(ExamQuestion $q): ?string
    {
        return $q->type === QuestionType::Recitation ? null : $this->describe($q, $q->correct_answer ?? []);
    }

    /** @return list<array{key:string, text:string}> the options of an MCQ or order question */
    public function options(ExamQuestion $q): array
    {
        return array_values(array_map(fn ($o) => ['key' => (string) ($o['key'] ?? ''), 'text' => (string) ($o['text'] ?? '')], $q->options ?? []));
    }

    private function describe(ExamQuestion $q, array $answer): ?string
    {
        $text = fn (string $key) => collect($this->options($q))->firstWhere('key', $key)['text'] ?? $key;

        $out = match ($q->type) {
            QuestionType::Mcq => isset($answer['key']) ? $text((string) $answer['key']) : null,
            QuestionType::TrueFalse => array_key_exists('value', $answer) && $answer['value'] !== null
                ? __(filter_var($answer['value'], FILTER_VALIDATE_BOOLEAN) ? 'exams.pdf.true' : 'exams.pdf.false', [], $this->locale)
                : null,
            QuestionType::CompleteVerse => trim((string) ($answer['text'] ?? '')) ?: null,
            // One verse per line, in the given order; the view numbers the lines.
            QuestionType::OrderVerses => ! empty($answer['order']) ? implode("\n", array_map(fn ($k) => $text((string) $k), $answer['order'])) : null,
            default => null,
        };

        return $out === '' ? null : $out;
    }
}
