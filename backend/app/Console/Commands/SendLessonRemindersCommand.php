<?php

namespace App\Console\Commands;

use App\Enums\SessionStatus;
use App\Models\LessonSession;
use App\Services\Messaging\AttendanceMessenger;
use App\Services\Messaging\ScheduledMessageDispatcher;
use Illuminate\Console\Command;

/**
 * Plans the two pre-lesson reminders (attendance_reminder_long / _short, section 23) for every
 * scheduled session starting in the next --hours (default 36), then releases whatever is due.
 * Planning is idempotent (one row per template, recipient and session time); a session whose time
 * changes has its planned reminders cancelled and re-planned by LessonSessionObserver.
 */
class SendLessonRemindersCommand extends Command
{
    protected $signature = 'lessons:send-reminders {--hours=36 : Plan for sessions starting within this many hours}';

    protected $description = 'Plan pre-lesson WhatsApp reminders and send the ones that are due';

    public function handle(AttendanceMessenger $messenger, ScheduledMessageDispatcher $dispatcher): int
    {
        $hours = max(1, (float) $this->option('hours'));
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $now = now();
        $until = $now->copy()->addMinutes((int) round($hours * 60));

        $sessions = LessonSession::with(['lesson.teacher', 'lesson.location', 'location'])
            ->where('status', SessionStatus::Scheduled->value)
            ->whereBetween('session_date', [$now->copy()->setTimezone($tz)->toDateString(), $until->copy()->setTimezone($tz)->toDateString()])
            ->get()
            ->filter(fn (LessonSession $s) => $messenger->startsAt($s)->between($now, $until));

        $planned = 0;
        foreach ($sessions as $session) {
            $planned += $messenger->planReminders($session);
        }

        $released = $dispatcher->dispatchDue();
        $this->info("Sessions: {$sessions->count()}, reminders planned: {$planned}, released: ".json_encode($released ?: new \stdClass));

        return self::SUCCESS;
    }
}
