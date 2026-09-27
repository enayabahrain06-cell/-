<?php

namespace App\Console\Commands;

use App\Enums\MessageType;
use App\Models\Competition;
use App\Models\CompetitionRound;
use App\Models\HonorPeriod;
use App\Models\Lesson;
use App\Models\Student;
use App\Services\Engagement\ChallengeService;
use App\Services\Engagement\CompetitionService;
use App\Services\Engagement\HonorService;
use App\Services\Lessons\StudentMessenger;
use Illuminate\Console\Command;

/**
 * engagement:daily  — recompute the honor board for the current month (both tracks); on the first of the month
 *                      finalize the previous month (rankings reset: a new period starts, history is kept);
 *                      refresh challenges and send their nudges.
 * engagement:reminders — competition registration open / closing and round reminders (each sent once).
 */
class EngagementCommands extends Command
{
    protected $signature = 'engagement:run {task : daily|reminders}';

    protected $description = 'Honor board computation, challenge progress and competition reminders';

    public function handle(HonorService $honor, ChallengeService $challenges, StudentMessenger $messenger): int
    {
        return match ($this->argument('task')) {
            'daily' => $this->daily($honor, $challenges),
            'reminders' => $this->reminders($messenger),
            default => self::INVALID,
        };
    }

    private function daily(HonorService $honor, ChallengeService $challenges): int
    {
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $current = now($tz)->format('Y-m');
        HonorPeriod::where('status', 'open')->where('period', '<', $current)->get()->each(fn ($p) => $honor->finalize($p));
        foreach (HonorService::GENDERS as $g) {
            $honor->compute($current, $g);
        }
        $sent = $challenges->nightly();
        $this->info("Honor board computed for {$current}; challenge nudges: {$sent}");

        return self::SUCCESS;
    }

    private function reminders(StudentMessenger $messenger): int
    {
        $sent = 0;
        $eligibleStudents = fn (Competition $c) => Student::where('gender', $c->gender)->where('status', 'active')
            ->whereHas('lessonStudents', fn ($q) => $q->where('status', 'active')->when($c->scope === 'circle', fn ($w) => $w->where('lesson_id', $c->scope_lesson_id))
                ->when($c->scope === 'package', fn ($w) => $w->whereIn('lesson_id', Lesson::where('package_id', $c->scope_package_id)->select('id'))))
            ->get()->filter(fn ($s) => app(CompetitionService::class)->eligibility($c, $s)['eligible']);

        // Registration opened.
        Competition::where('status', 'open')->whereNull('registration_open_notified_at')->where('registration_opens_at', '<=', now())->where('registration_closes_at', '>', now())->get()
            ->each(function (Competition $c) use (&$sent, $messenger, $eligibleStudents) {
                foreach ($eligibleStudents($c) as $s) {
                    $sent += $messenger->notify($s, MessageType::CompetitionOpen, ['competition' => $c->name($s->locale?->value ?? 'ar'), 'date' => display_tz($c->registration_closes_at)->toDateString()]);
                }
                $c->update(['registration_open_notified_at' => now()]);
            });

        // Registration closing within 24 hours (to eligible students not yet registered).
        Competition::where('status', 'open')->whereNull('registration_close_notified_at')->whereBetween('registration_closes_at', [now(), now()->addDay()])->get()
            ->each(function (Competition $c) use (&$sent, $messenger, $eligibleStudents) {
                $registered = $c->participants()->pluck('student_id')->flip();
                foreach ($eligibleStudents($c) as $s) {
                    if (! $registered->has($s->id)) {
                        $sent += $messenger->notify($s, MessageType::CompetitionClosing, ['competition' => $c->name($s->locale?->value ?? 'ar'), 'date' => display_tz($c->registration_closes_at)->toDateString()]);
                    }
                }
                $c->update(['registration_close_notified_at' => now()]);
            });

        // Round tomorrow: participants get the date and location.
        CompetitionRound::with(['competition.participants.student', 'location'])->whereNull('reminder_sent_at')->where('round_date', now(config('ahl.display_timezone'))->addDay()->toDateString())->get()
            ->each(function (CompetitionRound $r) use (&$sent, $messenger) {
                foreach ($r->competition->participants->where('status', '!=', 'withdrawn') as $p) {
                    if ($p->student) {
                        $locale = $p->student->locale?->value ?? 'ar';
                        $sent += $messenger->notify($p->student, MessageType::CompetitionRound, ['competition' => $r->competition->name($locale), 'round' => $r->name, 'date' => $r->round_date->toDateString().($r->start_time ? ' '.substr($r->start_time, 0, 5) : ''), 'location' => $r->location?->name ?? '']);
                    }
                }
                $r->update(['reminder_sent_at' => now()]);
            });

        $this->info("Competition reminders queued: {$sent}");

        return self::SUCCESS;
    }
}
