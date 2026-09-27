<?php

namespace App\Services\Settings;

use App\Enums\MemorizationDirection;
use App\Enums\WeekDay;

/**
 * The one place that says which settings the admin screen may edit and how each is validated.
 * A key seeded but missing here is returned read-only by GET and refused by PUT; to make it
 * editable, add one entry below (group, type, rules, and for the UI: options / min / max).
 */
class SettingsRegistry
{
    /**
     * @return array<string, array{group: string, type: string, rules?: array, options?: array<int, string>, min?: int, max?: int, editable?: bool}>
     */
    public static function definitions(): array
    {
        return [
            // authority
            'authority.name_ar' => ['group' => 'authority', 'type' => 'string', 'rules' => ['required', 'string', 'max:150']],
            'authority.name_en' => ['group' => 'authority', 'type' => 'string', 'rules' => ['required', 'string', 'max:150']],
            'authority.address_ar' => ['group' => 'authority', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:250']],
            'authority.address_en' => ['group' => 'authority', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:250']],
            'authority.phone' => ['group' => 'authority', 'type' => 'string', 'rules' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{6,20}$/']],
            // Set through POST/DELETE admin/settings/logo, never through PUT.
            'authority.logo_media_id' => ['group' => 'authority', 'type' => 'int', 'editable' => false],

            // locale
            'locale.timezone' => ['group' => 'locale', 'type' => 'string', 'rules' => ['required', 'string', 'timezone:all']],
            'locale.country_code' => ['group' => 'locale', 'type' => 'string', 'rules' => ['required', 'string', 'regex:/^[0-9]{1,4}$/']],
            // Amounts are stored in fils (1/1000), so the currency is fixed.
            'locale.currency' => ['group' => 'locale', 'type' => 'string', 'editable' => false],
            'locale.show_hijri' => ['group' => 'locale', 'type' => 'bool'],
            'locale.default' => ['group' => 'locale', 'type' => 'string', 'options' => ['ar', 'en']],

            // reminders
            'reminders.pre_lesson_hours' => ['group' => 'reminders', 'type' => 'int', 'min' => 1, 'max' => 48],
            'reminders.invoice_days_before' => ['group' => 'reminders', 'type' => 'int', 'min' => 0, 'max' => 30],
            'reminders.invoice_days_after' => ['group' => 'reminders', 'type' => 'int', 'min' => 0, 'max' => 60],
            'reminders.exam_day_before' => ['group' => 'reminders', 'type' => 'bool'],
            'reminders.exam_hour_before' => ['group' => 'reminders', 'type' => 'bool'],
            'reminders.weekly_report_day' => ['group' => 'reminders', 'type' => 'string', 'options' => array_column(WeekDay::cases(), 'value')],

            // attendance
            'attendance.repeated_absence_count' => ['group' => 'attendance', 'type' => 'int', 'min' => 2, 'max' => 20],
            'attendance.repeated_absence_days' => ['group' => 'attendance', 'type' => 'int', 'min' => 7, 'max' => 180],

            // registration
            'registration.open' => ['group' => 'registration', 'type' => 'bool'],
            'registration.photo_required' => ['group' => 'registration', 'type' => 'bool'],

            // sessions
            'sessions.generate_weeks_ahead' => ['group' => 'sessions', 'type' => 'int', 'min' => 1, 'max' => 26],

            // memorization & evaluation
            'progress.default_direction' => ['group' => 'progress', 'type' => 'string', 'options' => array_column(MemorizationDirection::cases(), 'value')],
            'evaluation.issue_threshold' => ['group' => 'progress', 'type' => 'int', 'min' => 1, 'max' => 10],
            'messages.progress_update_enabled' => ['group' => 'progress', 'type' => 'bool'],
            'messages.progress_update_day' => ['group' => 'progress', 'type' => 'int', 'min' => 1, 'max' => 28],

            // certificates
            'certificates.require_approval' => ['group' => 'certificates', 'type' => 'bool'],
            'certificates.notify_on_approve' => ['group' => 'certificates', 'type' => 'bool'],
            'certificates.auto_juz' => ['group' => 'certificates', 'type' => 'bool'],
            'certificates.link_minutes' => ['group' => 'certificates', 'type' => 'int', 'min' => 5, 'max' => 1440],

            // honor board (section 13): HonorService normalises the three weights to 100
            'honor.weight_attendance' => ['group' => 'honor', 'type' => 'int', 'min' => 0, 'max' => 100],
            'honor.weight_evaluation' => ['group' => 'honor', 'type' => 'int', 'min' => 0, 'max' => 100],
            'honor.weight_memorization' => ['group' => 'honor', 'type' => 'int', 'min' => 0, 'max' => 100],
            'honor.full_attendance_min_sessions' => ['group' => 'honor', 'type' => 'int', 'min' => 1, 'max' => 31],
            // empty = TV display off
            'honor.display_key' => ['group' => 'honor', 'type' => 'string', 'rules' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]*$/']],

            // appearance
            'ui.ornament_level' => ['group' => 'ui', 'type' => 'string', 'options' => ['full', 'minimal', 'off']],

            // media
            'media.print_female_photos' => ['group' => 'media', 'type' => 'bool'],
        ];
    }

    public static function get(string $key): ?array
    {
        return static::definitions()[$key] ?? null;
    }

    public static function editable(string $key): bool
    {
        $def = static::get($key);

        return $def !== null && ($def['editable'] ?? true);
    }

    /** Validation rules for one key, built from type, options and range. */
    public static function rules(string $key): array
    {
        $def = static::get($key);
        if ($def === null) {
            return [];
        }
        if (isset($def['rules'])) {
            return $def['rules'];
        }

        return match ($def['type']) {
            'bool' => ['required', 'boolean'],
            'int' => ['required', 'integer', 'min:'.($def['min'] ?? PHP_INT_MIN), 'max:'.($def['max'] ?? PHP_INT_MAX)],
            default => isset($def['options']) ? ['required', 'string', 'in:'.implode(',', $def['options'])] : ['required', 'string', 'max:255'],
        };
    }

    /** Normalise a validated value to the registry type before storing. */
    public static function normalise(string $key, mixed $value): mixed
    {
        return match (static::get($key)['type'] ?? 'string') {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            default => $value === null ? null : trim((string) $value),
        };
    }
}
