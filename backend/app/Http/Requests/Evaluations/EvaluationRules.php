<?php

namespace App\Http\Requests\Evaluations;

use App\Http\Requests\Progress\ProgressRules;

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

        return $out;
    }

    public static function entries(bool $withProgress): array
    {
        $rules = [
            'entries' => ['required', 'array', 'min:1'],
            'entries.*.student_id' => ['required', 'integer', 'distinct', 'exists:students,id'],
        ] + self::scores('entries.*.');

        if ($withProgress) {
            $rules['entries.*.progress'] = ['nullable', 'array', 'max:4'];
            $rules += ProgressRules::for('entries.*.progress.*.');
        }

        return $rules;
    }
}
