<?php

namespace App\Console\Commands;

use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Enums\SessionStatus;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Services\Lessons\StudentMessenger;
use Carbon\Carbon;
use Illuminate\Console\Command;

/** Pre-lesson WhatsApp reminders, sent once per session at a configurable offset before start. */
class SendLessonRemindersCommand extends Command
{
    protected $signature = 'lessons:send-reminders {--hours= : Override the reminder offset}';

    protected $description = 'Send pre-lesson reminders for sessions starting within the configured window';

    public function handle(StudentMessenger $messenger): int
    {
        $hours = $this->option('hours') !== null ? (float) $this->option('hours') : (float) setting('reminders.pre_lesson_hours', config('ahl.reminders.pre_lesson_hours', 2));
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $now = now();
        $windowEnd = $now->copy()->addMinutes((int) round($hours * 60));

        $candidates = LessonSession::with(['lesson.teacher', 'location'])
            ->where('status', SessionStatus::Scheduled->value)
            ->whereNull('reminder_sent_at')
            ->whereBetween('session_date', [$now->copy()->setTimezone($tz)->toDateString(), $windowEnd->copy()->setTimezone($tz)->toDateString()])
            ->get();

        $sessions = 0;
        $messages = 0;

        foreach ($candidates as $session) {
            $startsAt = Carbon::parse($session->session_date->toDateString().' '.$session->start_time, $tz)->utc();
            if ($startsAt->lt($now) || $startsAt->gt($windowEnd)) {
                continue;
            }

            $enrolled = LessonStudent::with('student')
                ->where('lesson_id', $session->lesson_id)
                ->where('status', LessonStudentStatus::Active->value)
                ->get();

            foreach ($enrolled as $ls) {
                if (! $ls->student) {
                    continue;
                }
                $messages += $messenger->notify($ls->student, MessageType::PreLessonReminder, [
                    'lesson' => $session->lesson?->name ?? '',
                    'teacher' => $session->lesson?->teacher?->name ?? '',
                    'time' => substr($session->start_time, 0, 5),
                    'location' => $session->location?->name ?? '',
                    'map_link' => $session->location?->map_link ?? '',
                    'assignment' => $ls->current_memorization ?: '—',
                ]);
            }

            $session->update(['reminder_sent_at' => now()]);
            $sessions++;
        }

        $this->info("Reminded sessions: {$sessions}, messages queued: {$messages}");

        return self::SUCCESS;
    }
}
