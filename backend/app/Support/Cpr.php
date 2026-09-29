<?php

namespace App\Support;

use App\Models\Student;

/**
 * Bahrain personal number (CPR): nine digits, as printed on the ID card and read by the card reader.
 * Staff may type it with spaces, dashes or Arabic digits; it is stored as nine Latin digits.
 */
final class Cpr
{
    public const PATTERN = '/^\d{9}$/';

    public static function normalize(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', PhoneNumber::toLatinDigits((string) $value));

        return $digits === '' ? null : $digits;
    }

    /** @return list<string> */
    public static function rules(): array
    {
        return ['nullable', 'string', 'regex:'.self::PATTERN];
    }

    /** The student who already holds this CPR (other than $exceptId), for a clear duplicate message. */
    public static function holder(?string $cpr, ?int $exceptId = null): ?Student
    {
        if (! $cpr) {
            return null;
        }

        return Student::where('cpr', $cpr)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->first();
    }

    /** An address as one tidy line: the card pads it with runs of spaces. Empty becomes null. */
    public static function address(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $line = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $line === '' ? null : $line;
    }

    /** @return list<string> */
    public static function addressRules(): array
    {
        return ['nullable', 'string', 'max:500'];
    }

    public static function takenMessage(Student $holder): string
    {
        return __('students.cpr_taken', ['name' => $holder->full_name, 'no' => $holder->student_no]);
    }
}
