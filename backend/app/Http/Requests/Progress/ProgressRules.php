<?php

namespace App\Http\Requests\Progress;

use App\Enums\ProgressType;
use App\Support\Quran;
use Closure;

/** Shared rules for a ledger entry (surah + ayah range), usable at the root or nested (e.g. "entries.*.progress.*."). */
final class ProgressRules
{
    public static function for(string $prefix = ''): array
    {
        $req = 'required'; // inside a nested list every item must be complete

        return [
            $prefix.'type' => [$req, ProgressType::rule()],
            $prefix.'surah_number' => [$req, 'integer', 'between:1,114'],
            $prefix.'from_ayah' => [$req, 'integer', 'min:1'],
            $prefix.'to_ayah' => [$req, 'integer', 'gte:'.$prefix.'from_ayah', self::withinSurah()],
            $prefix.'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** to_ayah must not exceed the ayah count of the chosen surah. Resolves the sibling key, so wildcards work. */
    private static function withinSurah(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $surahKey = preg_replace('/to_ayah$/', 'surah_number', $attribute);
            $surah = (int) data_get(request()->all(), $surahKey);
            if (Quran::exists($surah) && (int) $value > Quran::ayahCount($surah)) {
                $fail(__('progress.invalid_range', ['max' => Quran::ayahCount($surah)]));
            }
        };
    }
}
