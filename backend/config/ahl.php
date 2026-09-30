<?php

return [

    'authority' => [
        'name_ar' => 'هيئة التعليم الديني — سار',
        'name_en' => 'Religious Education Authority — Sar',
        'system_ar' => 'نظام أهل القرآن',
        'system_en' => 'Ahl Al-Quran System',
    ],

    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    'display_timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Bahrain'),

    // Placement test attempts allowed per guardian phone for one test (registration).
    'placement_max_attempts' => (int) env('PLACEMENT_MAX_ATTEMPTS', 3),
    'country_code' => env('DEFAULT_COUNTRY_CODE', '973'),
    'currency' => env('DEFAULT_CURRENCY', 'BHD'),
    'fils_per_unit' => 1000,

    'otp' => [
        'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),
        'length' => (int) env('OTP_LENGTH', 6),
        'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN', 60),
    ],

    'media' => [
        'disk' => env('MEDIA_DISK', 'local'),
        'signed_url_minutes' => 10,
        'photo' => ['max_kb' => 5120, 'profile_px' => 512, 'thumb_px' => 96],
    ],

    // معرض الصور (Phase 8). Photos are re-encoded to WebP (EXIF, including GPS, is dropped); originals are not kept.
    // Videos are an add-on: they are accepted only when ffmpeg and ffprobe run on this server (GALLERY_VIDEO=auto),
    // or never (GALLERY_VIDEO=off).
    'gallery' => [
        'max_upload_mb' => (int) env('GALLERY_MAX_UPLOAD_MB', 15),
        'image_px' => 2048,
        'thumb_px' => 480,
        'video' => env('GALLERY_VIDEO', 'auto'), // auto | off
        'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_PATH', 'ffprobe'),
        'video_max_mb' => (int) env('GALLERY_VIDEO_MAX_MB', 50),
        'video_max_seconds' => (int) env('GALLERY_VIDEO_MAX_SECONDS', 60),
        'files_per_minute' => 600, // thumbnails load one request each; kept apart from the api limiter
    ],

    'attendance' => [
        'repeated_absence_count' => 3,
        'repeated_absence_days' => 30,
    ],

    'sessions' => [
        'generate_weeks_ahead' => 8,
    ],

    'reminders' => [
        'pre_lesson_hours' => 2,
        'invoice_days_before' => 3,
        'invoice_days_after' => 7,
    ],

    'staff_roles' => ['super_admin', 'supervisor', 'teacher'],
];
