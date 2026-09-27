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
