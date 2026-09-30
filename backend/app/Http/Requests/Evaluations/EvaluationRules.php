<?php

namespace App\Http\Requests\Evaluations;

use App\Http\Requests\Progress\ProgressRules;
use App\Models\Subject;

final class EvaluationRules
{
    /** Scores are integers 0 to 10. */
    public static function scores(string $prefix = '', bool $required = true): array
    {
        $req = $required ? 'required' : 'sometimes';
        $out = [];
        foreach (['memorization', 'tajweed', 'revision', 'behavior'] as $c) {
            $out[$prefix.$c] = [$req, 'integer', 'between:0,10'];
        }
        $out[$prefix.'note'] = ['nullable', 'string', 'max:1000'];

        return $out + self::criterionScores($prefix, false);
    }

    /**
     * U5: scores per criterion id ({criterion_id: score}); the service checks each against its criterion's max.
     * Quran sends its four system criteria as the columns above, other subjects only here.
     */
    public static function criterionScores(string $prefix, bool $required): array
    {
        return [
            $prefix.'scores' => [$required ? 'required' : 'nullable', 'array'],
            $prefix.'scores.*' => ['integer', 'min:0', 'max:255'],
        ];
    }

    public static function entries(bool $withProgress, bool $quran = true): array
    {
        $rules = [
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'],
        ];
        $rules += $quran
            ? self::scores('entries.*.')
            : ['entries.*.note' => ['nullable', 'string', 'max:1000']] + self::criterionScores('entries.*.', true);

        if ($withProgress && $quran) {
            $rules['entries.*.progress'] = ['nullable', 'array', 'max:4'];
            $rules += ProgressRules::for('entries.*.progress.*.');
        }

        return $rules;
    }

    /** The subject the request evaluates: the given one, else Quran. */
    public static function subjectId(?int $given): ?int
    {
        return $given ?: Subject::quranId();
    }

    public static function isQuran(?int $given): bool
    {
        return ! $given || $given === Subject::quranId();
    }
}
