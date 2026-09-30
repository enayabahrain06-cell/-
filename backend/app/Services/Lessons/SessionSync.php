<?php

namespace App\Services\Lessons;

use App\Enums\LessonStatus;
use App\Enums\SessionStatus;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Support\WeekDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a class's sessions (lesson_sessions: one per class per night) in step with its schedule (ClassSchedule) —
 * a diff, never delete-and-recreate (U1/D2). This replaces the old SessionGenerator::regenerate().
 *
 * For every date from today (or the class start) up to N weeks ahead (or the class end):
 *  - scheduled, no session          → create;
 *  - scheduled, session differs     → update its times/room, only while no attendance has been recorded;
 *  - not scheduled, session exists  → delete when nothing is attached to it, otherwise mark it cancelled.
 * "Attached": attendance rows or taken, WhatsApp confirmations, excuses, messages (sent or planned), evaluations,
 * inbound replies. Past sessions are never touched. A session staff cancelled by hand stays cancelled.
 * Classes that are not active get no new sessions and keep the ones they have.
 */
class SessionSync
{
    public function __construct(private ClassSchedule $schedule) {}

    public function weeksAhead(): int
    {
        return (int) setting('sessions.generate_weeks_ahead', config('ahl.sessions.generate_weeks_ahead', 8));
    }

    /**
     * What sync would do for one class (nothing is written).
     *
     * @return array{lesson_id:int, lesson:string, legacy:bool, create:list<array>, update:list<array>, cancel:list<array>, delete:list<array>, kept:list<array>}
     */
    public function plan(Lesson $lesson, ?CarbonInterface $until = null): array
    {
        $until = Carbon::instance($until ?? today()->addWeeks($this->weeksAhead()))->startOfDay();
        $today = today()->startOfDay();
        $periods = $this->schedule->periods($lesson);
        $nights = ClassSchedule::nights($periods);
        $plan = ['lesson_id' => $lesson->id, 'lesson' => $lesson->name, 'legacy' => ($periods[0]['slot_id'] ?? null) === null,
            'create' => [], 'update' => [], 'cancel' => [], 'delete' => [], 'kept' => []];

        // Expected nights: active classes only, inside the class's own dates.
        $expected = [];
        if ($lesson->status === LessonStatus::Active && $nights) {
            $from = Carbon::instance($lesson->start_date)->startOfDay()->max($today);
            $to = $lesson->end_date ? Carbon::instance($lesson->end_date)->startOfDay()->min($until) : $until;
            $overrides = LessonLocationOverride::where('lesson_id', $lesson->id)
                ->whereBetween('override_date', [$from->toDateString(), $to->toDateString()])->get()
                ->keyBy(fn ($o) => $o->override_date->toDateString());
            for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
                $n = $nights[WeekDays::keyFor($d)] ?? null;
                if ($n) {
                    $key = $d->toDateString();
                    $expected[$key] = ['start_time' => $n['start'], 'end_time' => $n['end'], 'location_id' => $overrides[$key]->location_id ?? $n['location_id']];
                }
            }
        }

        $sessions = LessonSession::where('lesson_id', $lesson->id)->where('session_date', '>=', $today->toDateString())
            ->withCount(['attendances', 'attendanceConfirmations', 'excuses', 'messageLogs', 'evaluations', 'inboundMessages'])
            ->get()->keyBy(fn ($s) => $s->session_date->toDateString());

        foreach ($expected as $date => $want) {
            $s = $sessions[$date] ?? null;
            if (! $s) {
                $plan['create'][] = ['date' => $date] + $want;
                continue;
            }
            $changes = array_filter([
                'start_time' => WeekDays::time((string) $s->start_time) !== $want['start_time'] ? $want['start_time'] : null,
                'end_time' => WeekDays::time((string) $s->end_time) !== $want['end_time'] ? $want['end_time'] : null,
                'location_id' => (int) $s->location_id !== (int) $want['location_id'] ? ($want['location_id'] ?? 0) : null,
            ], fn ($v) => $v !== null);
            if (array_key_exists('location_id', $changes) && $changes['location_id'] === 0) {
                $changes['location_id'] = null;
            }
            if (! $changes) {
                continue;
            }
            $row = ['date' => $date, 'session_id' => $s->id, 'changes' => $changes];
            if ($s->attendance_taken_at || $s->attendances_count > 0) {
                $plan['kept'][] = $row + ['reason' => 'attendance'];
            } else {
                $plan['update'][] = $row;
            }
        }

        foreach ($sessions as $date => $s) {
            if (isset($expected[$date]) || $s->status === SessionStatus::Cancelled) {
                continue;
            }
            // Not scheduled any more (or the class is paused/ended): only active classes lose future sessions.
            if ($lesson->status !== LessonStatus::Active && $nights) {
                continue;
            }
            $row = ['date' => $date, 'session_id' => $s->id];
            // Attendance was recorded: the night happened. It stays exactly as it is.
            if ($s->attendance_taken_at || $s->attendances_count > 0) {
                $plan['kept'][] = $row + ['changes' => [], 'reason' => 'attendance'];
                continue;
            }
            $attached = $s->attendance_confirmations_count + $s->excuses_count + $s->message_logs_count
                + $s->evaluations_count + $s->inbound_messages_count > 0;
            if ($attached) {
                $plan['cancel'][] = $row;
            } else {
                $plan['delete'][] = $row;
            }
        }

        return $plan;
    }

    /** Apply a plan (or plan and apply). Returns the plan that was applied. */
    public function apply(Lesson $lesson, ?array $plan = null, ?CarbonInterface $until = null): array
    {
        $plan ??= $this->plan($lesson, $until);

        DB::transaction(function () use ($lesson, $plan) {
            foreach ($plan['create'] as $c) {
                LessonSession::create([
                    'lesson_id' => $lesson->id, 'session_date' => $c['date'], 'start_time' => $c['start_time'], 'end_time' => $c['end_time'],
                    'location_id' => $c['location_id'], 'status' => SessionStatus::Scheduled,
                ]);
            }
            foreach ($plan['update'] as $u) {
                LessonSession::whereKey($u['session_id'])->first()?->update($u['changes']);
            }
            // A schedule change cancels quietly: planned reminders are withdrawn, but no "session cancelled" message
            // goes to families (the old delete-and-recreate never sent one either).
            foreach ($plan['cancel'] as $c) {
                $s = LessonSession::whereKey($c['session_id'])->first();
                if ($s) {
                    app(\App\Services\Messaging\AttendanceMessenger::class)->cancelPending($s, 'schedule_changed');
                    $s->forceFill(['status' => SessionStatus::Cancelled])->saveQuietly();
                }
            }
            foreach ($plan['delete'] as $d) {
                LessonSession::whereKey($d['session_id'])->first()?->delete();
            }
        });

        return $plan;
    }

    /**
     * Plan (dry run) or apply for every class. Paused/ended classes are included so their plan is visible.
     *
     * @param  list<int>|null  $lessonIds
     * @return list<array>
     */
    public function all(bool $dryRun = false, ?int $weeksAhead = null, ?array $lessonIds = null): array
    {
        $until = today()->addWeeks($weeksAhead ?? $this->weeksAhead());
        $out = [];
        Lesson::with('package:id,academic_term_id')->when($lessonIds, fn ($q) => $q->whereIn('id', $lessonIds))->orderBy('id')
            ->each(function (Lesson $lesson) use ($dryRun, $until, &$out) {
                $plan = $this->plan($lesson, $until);
                $out[] = $dryRun ? $plan : $this->apply($lesson, $plan);
            });

        return $out;
    }
}
