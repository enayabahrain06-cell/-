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

/**
 * Materialises one lesson_sessions row per lesson per scheduled day.
 * Sessions that already have attendance are never deleted or moved.
 */
class SessionGenerator
{
    public function weeksAhead(): int
    {
        return (int) setting('sessions.generate_weeks_ahead', config('ahl.sessions.generate_weeks_ahead', 8));
    }

    /** Generate missing sessions for every active lesson up to N weeks ahead. Returns rows created. */
    public function generateAll(?int $weeksAhead = null): int
    {
        $until = today()->addWeeks($weeksAhead ?? $this->weeksAhead());
        $created = 0;

        Lesson::where('status', LessonStatus::Active->value)->each(function (Lesson $lesson) use ($until, &$created) {
            $created += $this->generateFor($lesson, $until);
        });

        return $created;
    }

    /** Create sessions for one lesson between max(today, start_date) and min(until, end_date). */
    public function generateFor(Lesson $lesson, ?CarbonInterface $until = null): int
    {
        $until = Carbon::instance($until ?? today()->addWeeks($this->weeksAhead()))->startOfDay();
        $from = Carbon::instance($lesson->start_date)->startOfDay()->max(today());
        $to = $lesson->end_date ? Carbon::instance($lesson->end_date)->startOfDay()->min($until) : $until;

        if ($from->gt($to) || empty($lesson->days)) {
            return 0;
        }

        $existing = LessonSession::where('lesson_id', $lesson->id)
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
            ->pluck('id', 'session_date')
            ->mapWithKeys(fn ($id, $date) => [Carbon::parse($date)->toDateString() => $id]);

        $overrides = LessonLocationOverride::where('lesson_id', $lesson->id)
            ->whereBetween('override_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->keyBy(fn ($o) => $o->override_date->toDateString());

        $created = 0;
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $key = $d->toDateString();
            if (! in_array(WeekDays::keyFor($d), $lesson->days, true) || isset($existing[$key])) {
                continue;
            }

            LessonSession::create([
                'lesson_id' => $lesson->id,
                'session_date' => $key,
                'start_time' => WeekDays::time($lesson->start_time),
                'end_time' => WeekDays::time($lesson->end_time),
                'location_id' => $overrides[$key]->location_id ?? $lesson->location_id,
                'status' => SessionStatus::Scheduled,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * After a schedule change: drop FUTURE sessions with no attendance, keep every session
     * that has attendance (even if its day no longer matches), then regenerate.
     */
    public function regenerate(Lesson $lesson): int
    {
        $this->emptyFutureSessions($lesson)->each->delete();

        return $this->generateFor($lesson);
    }

    /** Future sessions (today onwards) with no attendance taken and no attendance rows. */
    public function emptyFutureSessions(Lesson $lesson)
    {
        return LessonSession::where('lesson_id', $lesson->id)
            ->where('session_date', '>=', today()->toDateString())
            ->whereNull('attendance_taken_at')
            ->whereDoesntHave('attendances')
            ->get();
    }
}
