<?php

use App\Services\SettingsService;

if (! function_exists('setting')) {
    /** Read a setting by key with a default (cached). */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }
}

if (! function_exists('pdf_ar')) {
    /**
     * Shape Arabic text into presentation glyphs for dompdf, which does no bidi/shaping itself.
     * Use inside PDF Blade views on every Arabic string. Latin text passes through unchanged.
     */
    function pdf_ar(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        // Only text with Arabic letters is shaped. Arabic-Indic digits alone (an answer such as «٣٠») pass through
        // unchanged: ArPHP would reverse them into «٠٣».
        if (! preg_match('/[\x{0621}-\x{064A}\x{066E}-\x{06D3}\x{06FA}-\x{06FF}]/u', $text)) {
            return e($text);
        }
        // Harakat between lam and alef (الْأَرْضَ، لَا، كَلَّا) make ArPHP print the lam-alef ligature and then the alef
        // again («الأأرض»). Moving them after the alef keeps the marks and gives the single ligature.
        $text = preg_replace('/\x{0644}([\x{064B}-\x{0652}]+)([\x{0622}\x{0623}\x{0625}\x{0627}])/u', "\u{0644}$2$1", $text);
        // ArPHP leaves a leading number on the left of the reversed run ("16 ربيع الآخر" → "١٦ ﺮﺧﻵا ﻊﻴﺑر");
        // in right-to-left reading it belongs at the right end, so it is placed there by hand.
        if (preg_match('/^([0-9٠-٩]+)\s+(.+)$/u', trim($text), $m) && preg_match('/^\p{Arabic}/u', $m[2])) {
            return pdf_ar($m[2]).' '.e(strtr($m[1], array_combine(range(0, 9), ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'])));
        }
        static $arabic = null;
        $arabic ??= new \ArPHP\I18N\Arabic;

        // ArPHP 7 reads its glyph table with the next character even when that is a space, for a word-final
        // haraka + shadda (as in «عَمَّ يَتَسَاءَلُونَ»). The missing key is read as "does not join", which is
        // right, but the PHP warning would abort the PDF. Only warnings raised inside ArPHP are ignored here.
        set_error_handler(fn (int $no, string $msg, string $file) => str_contains(str_replace('\\', '/', $file), '/ar-php/'), E_WARNING);
        try {
            return e($arabic->utf8Glyphs($text));
        } finally {
            restore_error_handler();
        }
    }
}

if (! function_exists('pdf_ar_lines')) {
    /**
     * Word-wrap text into lines of at most $max characters (in reading order), each shaped with pdf_ar().
     * dompdf would wrap a single shaped Arabic run from the wrong end, so long paragraphs are split here.
     *
     * @return list<string>
     */
    function pdf_ar_lines(?string $text, int $max = 70): array
    {
        $lines = [];
        $current = '';
        foreach (preg_split('/\s+/u', trim((string) $text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            if ($current !== '' && mb_strlen($current.' '.$word) > $max) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $current === '' ? $word : $current.' '.$word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return array_map('pdf_ar', $lines);
    }
}

if (! function_exists('money_bhd')) {
    /** Format fils as BHD with 3 decimals, localized suffix. */
    function money_bhd(int $fils, ?string $locale = null): string
    {
        return \App\Support\Money::format($fils, $locale ?? app()->getLocale());
    }
}

if (! function_exists('hijri_date')) {
    /** Hijri (Umm al-Qura) date string next to the Gregorian one, e.g. "١٤٤٨/٠٤/١٥هـ". Empty when intl is missing. */
    function hijri_date(?\DateTimeInterface $date, string $locale = 'ar'): string
    {
        if (! $date || ! class_exists(\IntlDateFormatter::class)) {
            return '';
        }
        $fmt = new \IntlDateFormatter(
            $locale.'@calendar=islamic-umalqura',
            \IntlDateFormatter::LONG,
            \IntlDateFormatter::NONE,
            config('ahl.display_timezone'),
            \IntlDateFormatter::TRADITIONAL
        );

        return $fmt->format($date) ?: '';
    }
}

if (! function_exists('display_tz')) {
    /** Convert a UTC Carbon to the authority's display timezone (Asia/Bahrain). */
    function display_tz(?\DateTimeInterface $dt): ?\Carbon\Carbon
    {
        return $dt ? \Carbon\Carbon::instance($dt)->setTimezone(config('ahl.display_timezone')) : null;
    }
}
