<?php

namespace App\Services\Exams;

/**
 * Normalises Arabic text so that "complete the verse" answers compare fairly:
 * strips tashkeel (U+064B–U+0652, U+0670, U+0653–U+0655 handled by the range), tatweel,
 * unifies alef variants (إ أ آ ٱ → ا), ى → ي, converts Arabic-Indic digits, trims and
 * collapses whitespace, and removes punctuation.
 */
final class ArabicNormalizer
{
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $t = trim($text);
        // diacritics / tashkeel + superscript alef + tatweel
        $t = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}\x{06D6}-\x{06ED}]/u', '', $t) ?? $t;
        // alef variants
        $t = strtr($t, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ى' => 'ي']);
        // digits
        $t = strtr($t, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        // punctuation (Arabic and Latin)
        $t = preg_replace('/[\p{P}\p{S}]/u', ' ', $t) ?? $t;
        // whitespace
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return mb_strtolower(trim($t));
    }

    public static function same(?string $a, ?string $b): bool
    {
        return self::normalize($a) !== '' && self::normalize($a) === self::normalize($b);
    }
}
