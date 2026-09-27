<?php

namespace App\Support;

final class PhoneNumber
{
    /**
     * Normalise any user-entered phone into E.164 (+973XXXXXXXX).
     * Accepts "36000001", "036000001", "973 3600 0001", "+973-3600-0001", "0097336000001".
     * Returns null when the digits cannot form a plausible number.
     */
    public static function normalize(?string $raw, ?string $countryCode = null): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cc = $countryCode ?? (string) config('ahl.country_code', '973');
        $digits = preg_replace('/\D+/', '', self::toLatinDigits($raw));

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0') && ! str_starts_with($digits, $cc)) {
            $digits = substr($digits, 1);
        }

        if (! str_starts_with($digits, $cc)) {
            $digits = $cc.$digits;
        }

        if (strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        return '+'.$digits;
    }

    /** Convert Arabic-Indic and Persian digits to Latin. */
    public static function toLatinDigits(string $value): string
    {
        $map = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

        return strtr($value, $map);
    }

    /** Digits only without "+", as WhatsApp providers expect. */
    public static function forWhatsApp(string $e164): string
    {
        return ltrim($e164, '+');
    }
}
