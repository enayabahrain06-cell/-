<?php

namespace App\Support;

use Carbon\CarbonInterface;

final class WeekDays
{
    /** Carbon dayOfWeek (0 = Sunday) → weekday key used in packages.days / lessons.days. */
    public const MAP = [0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat'];

    public static function keyFor(CarbonInterface $date): string
    {
        return self::MAP[$date->dayOfWeek];
    }

    /** Normalise "16:00" / "16:00:00" / "٤:٠٠" to zero-padded "HH:MM:SS" for string comparison. */
    public static function time(string $time): string
    {
        $time = PhoneNumber::toLatinDigits(trim($time));
        $parts = array_map('intval', explode(':', $time));

        return sprintf('%02d:%02d:%02d', $parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0);
    }

    /** Overlap rule: adjacent ranges do not overlap. */
    public static function overlaps(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return self::time($aStart) < self::time($bEnd) && self::time($aEnd) > self::time($bStart);
    }
}
