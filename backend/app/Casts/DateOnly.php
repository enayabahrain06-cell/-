<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Portable date-only cast. Eloquent's built-in `date` cast writes "Y-m-d 00:00:00";
 * MySQL/PostgreSQL DATE columns truncate that, SQLite keeps it as text, so string
 * comparisons on date columns diverge between drivers. This cast always stores "Y-m-d".
 */
class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof \DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value))->format('Y-m-d');
    }
}
