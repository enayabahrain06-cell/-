<?php

namespace App\Services\Messaging;

use App\Models\LessonMessagingRule;
use App\Services\SettingsService;

/**
 * The editable rules of automatic attendance messaging (settings group "messaging", plus the existing
 * attendance.repeated_absence_* thresholds). Defaults match section 23 and SettingsSeeder.
 */
class MessagingRules
{
    /** key => [default, type, group] */
    public const KEYS = [
        'messaging.reminder_1_enabled' => [true, 'bool', 'messaging'],
        'messaging.reminder_1_minutes' => [120, 'int', 'messaging'],
        'messaging.reminder_2_enabled' => [true, 'bool', 'messaging'],
        'messaging.reminder_2_minutes' => [60, 'int', 'messaging'],
        'messaging.absence_enabled' => [true, 'bool', 'messaging'],
        'messaging.repeated_absence_enabled' => [true, 'bool', 'messaging'],
        'messaging.repeated_absence_throttle_days' => [14, 'int', 'messaging'],
        'attendance.repeated_absence_count' => [3, 'int', 'attendance'],
        'attendance.repeated_absence_days' => [30, 'int', 'attendance'],
        'messaging.location_change_enabled' => [true, 'bool', 'messaging'],
        'messaging.session_cancelled_enabled' => [true, 'bool', 'messaging'],
        'messaging.quiet_start' => ['22:00', 'string', 'messaging'],
        'messaging.quiet_end' => ['07:00', 'string', 'messaging'],
        'messaging.quiet_days' => [[], 'json', 'messaging'],
        'messaging.student_copy_min_age' => [12, 'int', 'messaging'],
        'messaging.supervisor_phone' => ['', 'string', 'messaging'],
        'messaging.auto_reply_hours' => [24, 'int', 'messaging'],
        'messaging.invalid_after_failures' => [3, 'int', 'messaging'],
    ];

    public function __construct(private SettingsService $settings) {}

    public function get(string $key): mixed
    {
        $default = self::KEYS[$key][0] ?? null;
        $value = $this->settings->get($key, $default);

        return $value ?? $default;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /** @return array<string, mixed> short keys (without the group prefix) for the rules screen */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::KEYS) as $key) {
            $out[self::short($key)] = $this->get($key);
        }
        $out['quiet_days'] = array_values((array) ($out['quiet_days'] ?? []));

        return $out;
    }

    /** @param  array<string, mixed>  $values short keys, already validated */
    public function update(array $values): array
    {
        foreach (self::KEYS as $key => [$default, $type, $group]) {
            $short = self::short($key);
            if (! array_key_exists($short, $values)) {
                continue;
            }
            $v = $values[$short];
            $v = match ($type) {
                'bool' => (bool) $v,
                'int' => (int) $v,
                'json' => array_values((array) $v),
                default => (string) ($v ?? ''),
            };
            $this->settings->set($key, $v, $group, $type);
        }

        return $this->all();
    }

    public static function short(string $key): string
    {
        return substr($key, strpos($key, '.') + 1);
    }

    /** @return array{reminders_enabled: bool, second_reminder_enabled: bool} */
    public function forLesson(int $lessonId): array
    {
        $rule = LessonMessagingRule::where('lesson_id', $lessonId)->first();

        return [
            'reminders_enabled' => $rule?->reminders_enabled ?? true,
            'second_reminder_enabled' => $rule?->second_reminder_enabled ?? true,
        ];
    }

    public function timezone(): string
    {
        return (string) ($this->settings->get('locale.timezone') ?: config('ahl.display_timezone', 'Asia/Bahrain'));
    }

    public function supervisorPhone(): string
    {
        return (string) ($this->get('messaging.supervisor_phone') ?: $this->settings->get('authority.phone', ''));
    }
}
