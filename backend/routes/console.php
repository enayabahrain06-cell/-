<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Lessons: materialise sessions 8 weeks ahead, and pre-lesson WhatsApp reminders.
Schedule::command('lesson-sessions:generate')->dailyAt('00:30');
Schedule::command('lessons:send-reminders')->everyFiveMinutes();

// exams
Schedule::command('exams:send-reminders')->everyFiveMinutes()->withoutOverlapping();

// memorization: optional monthly progress update to guardians (the command checks the enabled flag and day)
Schedule::command('progress:send-monthly-updates')->dailyAt('17:00')->withoutOverlapping();

// honor board (monthly periods per track), challenge progress and nudges, competition reminders
Schedule::command('engagement:run daily')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('engagement:run reminders')->hourly()->withoutOverlapping();
