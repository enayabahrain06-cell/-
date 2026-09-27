<?php

namespace Database\Seeders;

use App\Services\SettingsService;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $s = app(SettingsService::class);
        $defaults = [
            // authority
            ['authority.name_ar', 'هيئة التعليم الديني — سار', 'authority', 'string'],
            ['authority.name_en', 'Religious Education Authority — Sar', 'authority', 'string'],
            ['authority.address_ar', 'سار، مملكة البحرين', 'authority', 'string'],
            ['authority.address_en', 'Sar, Kingdom of Bahrain', 'authority', 'string'],
            ['authority.phone', '+97317000000', 'authority', 'string'],
            ['authority.logo_media_id', null, 'authority', 'int'],
            // locale
            ['locale.timezone', 'Asia/Bahrain', 'locale', 'string'],
            ['locale.country_code', '973', 'locale', 'string'],
            ['locale.currency', 'BHD', 'locale', 'string'],
            ['locale.show_hijri', true, 'locale', 'bool'],
            ['locale.default', 'ar', 'locale', 'string'],
            // reminders
            ['reminders.pre_lesson_hours', 2, 'reminders', 'int'],
            ['reminders.invoice_days_before', 3, 'reminders', 'int'],
            ['reminders.invoice_days_after', 7, 'reminders', 'int'],
            ['reminders.exam_day_before', true, 'reminders', 'bool'],
            ['reminders.exam_hour_before', true, 'reminders', 'bool'],
            ['reminders.weekly_report_day', 'thu', 'reminders', 'string'],
            // attendance
            ['attendance.repeated_absence_count', 3, 'attendance', 'int'],
            ['attendance.repeated_absence_days', 30, 'attendance', 'int'],
            // registration
            ['registration.photo_required', false, 'registration', 'bool'],
            ['registration.open', true, 'registration', 'bool'],
            // sessions
            ['sessions.generate_weeks_ahead', 8, 'sessions', 'int'],
            // memorization & evaluation
            ['progress.default_direction', 'backward', 'progress', 'string'],
            ['evaluation.issue_threshold', 6, 'progress', 'int'],
            ['messages.progress_update_enabled', false, 'progress', 'bool'],
            ['messages.progress_update_day', 1, 'progress', 'int'],
            // appearance: Islamic ornament density (full / minimal / off)
            ['ui.ornament_level', 'full', 'ui', 'string'],
            // gender separation: girls' photos never print unless the Super Admin enables this
            ['media.print_female_photos', false, 'media', 'bool'],
        ];

        foreach ($defaults as [$key, $value, $group, $type]) {
            if ($s->get($key) === null) {
                $s->set($key, $value, $group, $type);
            }
        }
    }
}
