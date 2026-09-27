<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const CACHE_KEY = 'settings.all';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function set(string $key, mixed $value, string $group = 'general', ?string $type = null): Setting
    {
        $type ??= match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_array($value) => 'json',
            default => 'string',
        };

        $stored = match ($type) {
            'bool' => $value ? '1' : '0',
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE),
            default => (string) $value,
        };

        $setting = Setting::updateOrCreate(['key' => $key], ['value' => $stored, 'group' => $group, 'type' => $type]);
        $this->flush();

        return $setting;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        return $this->loaded = Cache::rememberForever(self::CACHE_KEY, function () {
            $out = [];
            foreach (Setting::query()->get() as $s) {
                $out[$s->key] = self::cast($s->value, $s->type);
            }

            return $out;
        });
    }

    /** @return array<string, array<string, mixed>> grouped for the settings screen */
    public function grouped(): array
    {
        $out = [];
        foreach (Setting::query()->orderBy('group')->orderBy('key')->get() as $s) {
            $out[$s->group][$s->key] = ['value' => self::cast($s->value, $s->type), 'type' => $s->type];
        }

        return $out;
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    public static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'bool' => $value === '1' || $value === 'true',
            'int' => (int) $value,
            'json' => $value === null ? null : json_decode($value, true),
            default => $value,
        };
    }
}
