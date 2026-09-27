<?php

namespace App\Support;

final class Money
{
    /** 1 BHD = 1000 fils. */
    public static function toUnits(int $fils): float
    {
        return $fils / 1000;
    }

    public static function fromUnits(float|int|string $units): int
    {
        return (int) round(((float) PhoneNumber::toLatinDigits((string) $units)) * 1000);
    }

    public static function format(int $fils, string $locale = 'ar'): string
    {
        $units = number_format($fils / 1000, 3, '.', ',');

        return $locale === 'ar' ? "{$units} د.ب" : "BHD {$units}";
    }
}
