<?php

namespace App\Services\Exams;

use App\Enums\QuestionType;
use App\Models\ExamQuestion;

/**
 * Grades objective questions. Recitation is never auto-graded (returns nulls so the
 * attempt waits for manual grading).
 *
 * @return array{is_correct: bool|null, score: int|null}
 */
class AutoGrader
{
    public function grade(ExamQuestion $question, ?array $answer): array
    {
        if ($question->type === QuestionType::Recitation) {
            return ['is_correct' => null, 'score' => null];
        }

        $correct = $this->isCorrect($question, $answer ?? []);

        return ['is_correct' => $correct, 'score' => $correct ? (int) $question->marks : 0];
    }

    public function isCorrect(ExamQuestion $question, array $answer): bool
    {
        $key = $question->correct_answer ?? [];

        return match ($question->type) {
            QuestionType::Mcq => isset($answer['key'], $key['key']) && (string) $answer['key'] === (string) $key['key'],
            QuestionType::TrueFalse => array_key_exists('value', $answer) && array_key_exists('value', $key)
                && filter_var($answer['value'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === filter_var($key['value'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            QuestionType::CompleteVerse => $this->matchesText((string) ($answer['text'] ?? ''), $key),
            QuestionType::OrderVerses => isset($answer['order'], $key['order']) && is_array($answer['order'])
                && array_values(array_map('strval', $answer['order'])) === array_values(array_map('strval', $key['order'])),
            default => false,
        };
    }

    private function matchesText(string $given, array $key): bool
    {
        $candidates = array_merge([$key['text'] ?? ''], $key['alternatives'] ?? []);

        foreach ($candidates as $c) {
            if (ArabicNormalizer::same($given, (string) $c)) {
                return true;
            }
        }

        return false;
    }
}
