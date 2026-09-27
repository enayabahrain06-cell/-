<?php

use App\Services\SettingsService;

if (! function_exists('setting')) {
    /** Read a setting by key with a default (cached). */
    function setting(string $key, mixed $default = null): mixed
    {
        return app(SettingsService::class)->get($key, $default);
    }
}

if (! function_exists('display_tz')) {
    /** Convert a UTC Carbon to the authority's display timezone (Asia/Bahrain). */
    function display_tz(?\DateTimeInterface $dt): ?\Carbon\Carbon
    {
        return $dt ? \Carbon\Carbon::instance($dt)->setTimezone(config('ahl.display_timezone')) : null;
    }
}
