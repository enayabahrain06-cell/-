<?php

namespace App\Services\Lessons;

use App\Enums\LessonStatus;
use App\Enums\SessionStatus;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\LocationBooking;
use App\Support\WeekDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Detects hall double-bookings across four sources:
 *  - materialised lesson_sessions (with their effective location),
 *  - recurring lessons (days + date range) for dates not yet materialised,
 *  - one-day overrides that move a lesson INTO the hall,
 *  - manual/event/exam location_bookings.
 * Overlap rule: start < other_end AND end > other_start (adjacent slots are fine).
 *
 * @phpstan-type Conflict array{kind: string, id: int, title: string, date: string|null, days: array<int,string>|null, start_time: string, end_time: string}
 */
class LocationConflictDetector
{
    /**
     * Conflicts for a single date + time slot.
     *
     * @return list<Conflict>
     */
    public function forDate(int $locationId, CarbonInterface $date, string $start, string $end, ?int $ignoreLessonId = null, ?int $ignoreBookingId = null): array
    {
        return $this->detect($locationId, Carbon::instance($date)->startOfDay(), Carbon::instance($date)->startOfDay(), [WeekDays::keyFor($date)], $start, $end, $ignoreLessonId, $ignoreBookingId);
    }

    /**
     * Conflicts for a recurring slot (weekday keys within a date range).
     *
     * @param  list<string>  $days
     * @return list<Conflict>
     */
    public function forRecurring(int $locationId, array $days, CarbonInterface $from, ?CarbonInterface $to, string $start, string $end, ?int $ignoreLessonId = null): array
    {
        $to ??= Carbon::instance($from)->addYear();

        return $this->detect($locationId, Carbon::instance($from)->startOfDay(), Carbon::instance($to)->startOfDay(), $days, $start, $end, $ignoreLessonId, null);
    }

    /** @return list<Conflict> */
    public function forLesson(Lesson $lesson): array
    {
        if (! $lesson->location_id) {
            return [];
        }

        return $this->forRecurring($lesson->location_id, $lesson->days ?? [], $lesson->start_date, $lesson->end_date, $lesson->start_time, $lesson->end_time, $lesson->id);
    }

    /** @return list<Conflict> */
    private function detect(int $locationId, Carbon $from, Carbon $to, array $days, string $start, string $end, ?int $ignoreLessonId, ?int $ignoreBookingId): array
    {
        $start = WeekDays::time($start);
        $end = WeekDays::time($end);
        $days = array_values(array_unique($days));
        $singleDate = $from->equalTo($to);
        $conflicts = [];

        // 1. Materialised sessions held in this hall.
        $sessions = LessonSession::with('lesson:id,name')
            ->where('location_id', $locationId)
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
            ->when($ignoreLessonId, fn ($q) => $q->where('lesson_id', '!=', $ignoreLessonId))
            ->get();

        $seenLessonDates = [];
        foreach ($sessions as $s) {
            if (! in_array(WeekDays::keyFor($s->session_date), $days, true)) {
                continue;
            }
            $seenLessonDates[$s->lesson_id][$s->session_date->toDateString()] = true;
            if (WeekDays::overlaps($start, $end, $s->start_time, $s->end_time)) {
                $conflicts[] = $this->item('session', $s->id, $s->lesson?->name ?? '', $s->session_date->toDateString(), null, $s->start_time, $s->end_time);
            }
        }

        // 2. Recurring lessons whose default hall is this one (covers dates not yet materialised).
        $lessons = Lesson::query()
            ->where('location_id', $locationId)
            ->where('status', '!=', LessonStatus::Ended->value)
            ->when($ignoreLessonId, fn ($q) => $q->where('id', '!=', $ignoreLessonId))
            ->where('start_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString()))
            ->get();

        foreach ($lessons as $l) {
            $common = array_values(array_intersect($days, $l->days ?? []));
            if (! $common || ! WeekDays::overlaps($start, $end, $l->start_time, $l->end_time)) {
                continue;
            }

            if ($singleDate) {
                // Already reported through its session, or moved elsewhere that day by an override.
                $date = $from->toDateString();
                if (isset($seenLessonDates[$l->id][$date])) {
                    continue;
                }
                $movedAway = LessonLocationOverride::where('lesson_id', $l->id)->where('override_date', $date)->where('location_id', '!=', $locationId)->exists();
                if ($movedAway) {
                    continue;
                }
                $conflicts[] = $this->item('lesson', $l->id, $l->name, $date, $common, $l->start_time, $l->end_time);
            } else {
                $conflicts[] = $this->item('lesson', $l->id, $l->name, null, $common, $l->start_time, $l->end_time);
            }
        }

        // 3. One-day overrides moving another lesson INTO this hall.
        $overrides = LessonLocationOverride::with('lesson:id,name,start_time,end_time')
            ->where('location_id', $locationId)
            ->whereBetween('override_date', [$from->toDateString(), $to->toDateString()])
            ->when($ignoreLessonId, fn ($q) => $q->where('lesson_id', '!=', $ignoreLessonId))
            ->get();

        foreach ($overrides as $o) {
            $l = $o->lesson;
            if (! $l || ! in_array(WeekDays::keyFor($o->override_date), $days, true)) {
                continue;
            }
            if (isset($seenLessonDates[$l->id][$o->override_date->toDateString()])) {
                continue; // its session row already carries this hall
            }
            if (WeekDays::overlaps($start, $end, $l->start_time, $l->end_time)) {
                $conflicts[] = $this->item('override', $o->id, $l->name, $o->override_date->toDateString(), null, $l->start_time, $l->end_time);
            }
        }

        // 4. Manual / event / exam bookings.
        $bookings = LocationBooking::query()
            ->where('location_id', $locationId)
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->get();

        foreach ($bookings as $b) {
            if (! in_array(WeekDays::keyFor($b->booking_date), $days, true)) {
                continue;
            }
            if (WeekDays::overlaps($start, $end, $b->start_time, $b->end_time)) {
                $conflicts[] = $this->item('booking', $b->id, $b->title, $b->booking_date->toDateString(), null, $b->start_time, $b->end_time);
            }
        }

        return $conflicts;
    }

    private function item(string $kind, int $id, string $title, ?string $date, ?array $days, string $start, string $end): array
    {
        return ['kind' => $kind, 'id' => $id, 'title' => $title, 'date' => $date, 'days' => $days, 'start_time' => WeekDays::time($start), 'end_time' => WeekDays::time($end)];
    }
}
