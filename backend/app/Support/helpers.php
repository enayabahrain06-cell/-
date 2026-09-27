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
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return e($text);
        }
        static $arabic = null;
        $arabic ??= new \ArPHP\I18N\Arabic;

        return e($arabic->utf8Glyphs($text));
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
