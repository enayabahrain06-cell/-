<?php

namespace App\Services\Lessons;

use App\Enums\LessonStatus;
use App\Enums\SessionStatus;
use App\Models\Activity;
use App\Models\Lesson;
use App\Models\LessonLocationOverride;
use App\Models\LessonSession;
use App\Models\LocationBooking;
use App\Models\TimetableSlot;
use App\Support\WeekDays;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The one room clash check (U2). A room is taken by:
 *  - classes' periods from الجدول الدراسي (ClassSchedule: the period's room, else the class's room) — on a single
 *    date a cancelled session frees it and a one-day override moves the whole night away;
 *  - one-day overrides that move another class INTO the room;
 *  - manual / event / exam bookings;
 *  - programs (activities) held in the room at their times on every day of their dates.
 * Overlap rule: start < other_end AND end > other_start (adjacent periods are fine). The same whole-level period
 * shared by several classes of a level is one use of the room, so it never clashes with itself.
 *
 * @phpstan-type Conflict array{kind: string, id: int, title: string, date: string|null, days: array<int,string>|null, start_time: string, end_time: string}
 */
class LocationConflictDetector
{
    public function __construct(private ClassSchedule $schedule) {}

    /**
     * Conflicts for a single date + time slot.
     *
     * @param  list<int>  $ignoreLessonIds
     * @return list<Conflict>
     */
    public function forDate(int $locationId, CarbonInterface $date, string $start, string $end, ?int $ignoreLessonId = null, ?int $ignoreBookingId = null, array $ignoreLessonIds = [], ?int $ignoreSlotId = null): array
    {
        $d = Carbon::instance($date)->startOfDay();

        return $this->detect($locationId, $d, $d->copy(), [WeekDays::keyFor($date)], $start, $end, array_filter([$ignoreLessonId, ...$ignoreLessonIds]), $ignoreBookingId, $ignoreSlotId);
    }

    /**
     * Conflicts for a recurring slot (weekday keys within a date range).
     *
     * @param  list<string>  $days
     * @param  list<int>  $ignoreLessonIds
     * @return list<Conflict>
     */
    public function forRecurring(int $locationId, array $days, CarbonInterface $from, ?CarbonInterface $to, string $start, string $end, ?int $ignoreLessonId = null, array $ignoreLessonIds = [], ?int $ignoreSlotId = null): array
    {
        $to ??= Carbon::instance($from)->addYear();

        return $this->detect($locationId, Carbon::instance($from)->startOfDay(), Carbon::instance($to)->startOfDay(), $days, $start, $end, array_filter([$ignoreLessonId, ...$ignoreLessonIds]), null, $ignoreSlotId);
    }

    /**
     * Conflicts of a program held in a room every day from $from to $to (البرامج), ignoring the program itself.
     *
     * @return list<Conflict>
     */
    public function forActivity(int $locationId, CarbonInterface $from, CarbonInterface $to, string $start, string $end, ?int $ignoreActivityId = null): array
    {
        $this->ignoreActivityId = $ignoreActivityId;
        try {
            return $this->detect($locationId, Carbon::instance($from)->startOfDay(), Carbon::instance($to)->startOfDay(), array_values(WeekDays::MAP), $start, $end, [], null, null);
        } finally {
            $this->ignoreActivityId = null;
        }
    }

    private ?int $ignoreActivityId = null;

    private static ?bool $hasActivities = null;

    /**
     * Conflicts of a class's own weekly periods, each in its effective room.
     *
     * @return list<Conflict>
     */
    public function forLesson(Lesson $lesson): array
    {
        $out = [];
        foreach ($this->schedule->periods($lesson) as $p) {
            if (! $p['location_id']) {
                continue;
            }
            // A whole-level period is one use of the room by all the level's classes.
            $siblings = $p['shared'] && $p['slot_id'] ? $this->schedule->classesOf(TimetableSlot::find($p['slot_id']))->pluck('id')->all() : [];
            foreach ($this->forRecurring($p['location_id'], [$p['weekday']], $lesson->start_date, $lesson->end_date, $p['start'], $p['end'], $lesson->id, $siblings, $p['slot_id']) as $c) {
                $out[$c['kind'].':'.$c['id'].':'.($c['date'] ?? '').':'.$c['start_time']] = $c;
            }
        }

        return array_values($out);
    }

    /** @return list<Conflict> */
    private function detect(int $locationId, Carbon $from, Carbon $to, array $days, string $start, string $end, array $ignoreLessonIds, ?int $ignoreBookingId, ?int $ignoreSlotId): array
    {
        $start = WeekDays::time($start);
        $end = WeekDays::time($end);
        $days = array_values(array_unique($days));
        $singleDate = $from->equalTo($to);
        $conflicts = [];

        // 1. Classes that may use this room: its default room, or a period / override placing them in it.
        $slotLessons = TimetableSlot::where('location_id', $locationId)->whereNotNull('lesson_id')->pluck('lesson_id');
        $slotLevels = TimetableSlot::where('location_id', $locationId)->whereNull('lesson_id')->pluck('level_id');
        $lessons = Lesson::query()
            ->where('status', '!=', LessonStatus::Ended->value)
            ->when($ignoreLessonIds, fn ($q) => $q->whereNotIn('id', $ignoreLessonIds))
            ->where('start_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $from->toDateString()))
            ->where(fn ($q) => $q->where('location_id', $locationId)->orWhereIn('id', $slotLessons)->orWhereIn('level_id', $slotLevels->filter()->all() ?: [0]))
            ->get();
        $periods = $this->schedule->periodsFor($lessons);

        $date = $singleDate ? $from->toDateString() : null;
        $sessions = $singleDate
            ? LessonSession::whereIn('lesson_id', $lessons->pluck('id'))->where('session_date', $date)->get()->keyBy('lesson_id')
            : collect();
        $movedAway = $singleDate
            ? LessonLocationOverride::whereIn('lesson_id', $lessons->pluck('id'))->where('override_date', $date)->where('location_id', '!=', $locationId)->pluck('lesson_id')->flip()
            : collect();

        foreach ($lessons as $l) {
            if ($singleDate && ($movedAway->has($l->id) || $sessions->get($l->id)?->status === SessionStatus::Cancelled)) {
                continue;
            }
            foreach ($periods[$l->id] ?? [] as $p) {
                if ((int) $p['location_id'] !== $locationId || ! in_array($p['weekday'], $days, true)
                    || ($ignoreSlotId && $p['slot_id'] === $ignoreSlotId) || ! WeekDays::overlaps($start, $end, $p['start'], $p['end'])) {
                    continue;
                }
                $session = $sessions->get($l->id);
                $conflicts[] = $session
                    ? $this->item('session', $session->id, $l->name, $date, null, $p['start'], $p['end'])
                    : $this->item('lesson', $l->id, $l->name, $date, $singleDate ? [$p['weekday']] : [$p['weekday']], $p['start'], $p['end']);
            }
        }
        $conflicts = $this->mergeLessonDays($conflicts);

        // 2. One-day overrides moving another class INTO this room: all its periods that night.
        $overrides = LessonLocationOverride::with('lesson')
            ->where('location_id', $locationId)
            ->whereBetween('override_date', [$from->toDateString(), $to->toDateString()])
            ->when($ignoreLessonIds, fn ($q) => $q->whereNotIn('lesson_id', $ignoreLessonIds))
            ->get();
        foreach ($overrides as $o) {
            $l = $o->lesson;
            $day = WeekDays::keyFor($o->override_date);
            if (! $l || ! in_array($day, $days, true)) {
                continue;
            }
            foreach ($this->schedule->periods($l) as $p) {
                if ($p['weekday'] === $day && WeekDays::overlaps($start, $end, $p['start'], $p['end'])) {
                    $conflicts[] = $this->item('override', $o->id, $l->name, $o->override_date->toDateString(), null, $p['start'], $p['end']);
                    break;
                }
            }
        }

        // 3. Manual / event / exam bookings.
        $bookings = LocationBooking::query()
            ->where('location_id', $locationId)
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->when($ignoreBookingId, fn ($q) => $q->where('id', '!=', $ignoreBookingId))
            ->get();
        foreach ($bookings as $b) {
            if (in_array(WeekDays::keyFor($b->booking_date), $days, true) && WeekDays::overlaps($start, $end, $b->start_time, $b->end_time)) {
                $conflicts[] = $this->item('booking', $b->id, $b->title, $b->booking_date->toDateString(), null, $b->start_time, $b->end_time);
            }
        }

        // 4. Programs held in the room (every day of their dates, at their times). Skipped until the activities
        // migration has run on this database.
        self::$hasActivities ??= \Illuminate\Support\Facades\Schema::hasTable('activities');
        $activities = ! self::$hasActivities ? collect() : Activity::query()
            ->where('location_id', $locationId)->whereNotNull('start_time')->whereNotNull('end_time')
            ->where('status', '!=', 'done')
            ->when($this->ignoreActivityId, fn ($q, $id) => $q->whereKeyNot($id))
            ->where('starts_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('ends_on')->where('starts_on', '>=', $from->toDateString()))->orWhere('ends_on', '>=', $from->toDateString()))
            ->get();
        foreach ($activities as $a) {
            if (! WeekDays::overlaps($start, $end, $a->start_time, $a->end_time)) {
                continue;
            }
            $first = $a->starts_on->max($from);
            $last = $a->lastDay()->min($to);
            for ($d = $first->copy(), $n = 0; $d->lte($last) && $n < 8; $d->addDay(), $n++) {
                if (in_array(WeekDays::keyFor($d), $days, true)) {
                    $conflicts[] = $this->item('activity', $a->id, $a->name_ar, $first->equalTo($last) ? $first->toDateString() : null, null, $a->start_time, $a->end_time);
                    break;
                }
            }
        }

        return $conflicts;
    }

    /** One "lesson" row per class and time, listing all its clashing weekdays (as before the timetable). */
    private function mergeLessonDays(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $c) {
            if ($c['kind'] !== 'lesson' || $c['date'] !== null) {
                $out[] = $c;
                continue;
            }
            $key = "lesson:{$c['id']}:{$c['start_time']}:{$c['end_time']}";
            if (isset($out[$key])) {
                $out[$key]['days'] = array_values(array_unique([...$out[$key]['days'], ...$c['days']]));
            } else {
                $out[$key] = $c;
            }
        }

        return array_values($out);
    }

    private function item(string $kind, int $id, string $title, ?string $date, ?array $days, string $start, string $end): array
    {
        return ['kind' => $kind, 'id' => $id, 'title' => $title, 'date' => $date, 'days' => $days, 'start_time' => WeekDays::time($start), 'end_time' => WeekDays::time($end)];
    }
}
