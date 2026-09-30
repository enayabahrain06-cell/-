<?php

namespace App\Services\Lessons;

use App\Models\Lesson;
use App\Models\Subject;
use App\Models\TimetableSlot;
use App\Support\WeekDays;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * When and where a class (صف, the lessons table) meets — read from الجدول الدراسي, the only source (U1).
 *
 * A class's periods are its own periods (timetable_slots.lesson_id) plus its level's whole-level periods, in the
 * term of its package. Room of a period: the period's room, else the class's room. Teacher: the period's teacher,
 * else the class's teacher.
 *
 * Classes whose package has no academic term (and, as a safety net, termed classes with no periods at all) keep
 * using their own days/times; `sessions:sync --dry-run` lists them as "legacy".
 *
 * @phpstan-type Period array{slot_id: ?int, weekday: string, start: string, end: string, location_id: ?int, subject_id: ?int, teacher_id: ?int, shared: bool}
 */
class ClassSchedule
{
    private const WEEK = ['sat', 'sun', 'mon', 'tue', 'wed', 'thu', 'fri'];

    /** @return list<Period> */
    public function periods(Lesson $lesson): array
    {
        return $this->periodsFor(collect([$lesson]))[$lesson->id] ?? [];
    }

    /**
     * Periods of many classes in two queries.
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return array<int, list<Period>>
     */
    public function periodsFor(Collection $lessons): array
    {
        $lessons = \Illuminate\Database\Eloquent\Collection::make($lessons->all());
        $lessons->loadMissing('package:id,academic_term_id');
        $termed = $lessons->filter(fn (Lesson $l) => $l->package?->academic_term_id);
        $slots = collect();
        if ($termed->isNotEmpty()) {
            $terms = $termed->map(fn (Lesson $l) => $l->package->academic_term_id)->unique()->values();
            $levels = $termed->pluck('level_id')->filter()->unique()->values();
            $slots = TimetableSlot::query()->whereIn('academic_term_id', $terms)
                ->where(fn ($q) => $q->whereIn('lesson_id', $termed->pluck('id'))
                    ->orWhere(fn ($w) => $w->whereNull('lesson_id')->whereIn('level_id', $levels->all() ?: [0])))
                ->orderBy('start_time')->orderBy('id')
                ->get(['id', 'academic_term_id', 'level_id', 'lesson_id', 'weekday', 'start_time', 'end_time', 'subject_id', 'teacher_id', 'location_id']);
        }

        $out = [];
        foreach ($lessons as $lesson) {
            $term = $lesson->package?->academic_term_id;
            $own = $term ? $slots->filter(fn ($s) => (int) $s->lesson_id === (int) $lesson->id && (int) $s->academic_term_id === (int) $term) : collect();
            $shared = $term && $lesson->level_id
                ? $slots->filter(fn ($s) => $s->lesson_id === null && (int) $s->level_id === (int) $lesson->level_id && (int) $s->academic_term_id === (int) $term)
                : collect();
            $list = $own->merge($shared);
            $out[$lesson->id] = $list->isEmpty()
                ? $this->legacy($lesson)
                : $list->map(fn (TimetableSlot $s) => [
                    'slot_id' => $s->id,
                    'weekday' => $s->weekday instanceof \BackedEnum ? $s->weekday->value : (string) $s->weekday,
                    'start' => WeekDays::time((string) $s->start_time),
                    'end' => WeekDays::time((string) $s->end_time),
                    'location_id' => $s->location_id ?? $lesson->location_id,
                    'subject_id' => $s->subject_id,
                    'teacher_id' => $s->teacher_id ?? $lesson->teacher_id,
                    'shared' => $s->lesson_id === null,
                ])->sortBy(fn ($p) => array_search($p['weekday'], self::WEEK, true) * 100000 + (int) str_replace(':', '', $p['start']))->values()->all();
        }

        return $out;
    }

    /**
     * Summary for the class screens: its nights and whether the class form may still edit the schedule (only a
     * simple one: its own Quran periods from the form, one a night, and no whole-level periods).
     *
     * @return array{source: string, editable: bool, nights: list<array{weekday: string, start: string, end: string, location_id: ?int}>, periods: int}
     */
    public function summary(Lesson $lesson): array
    {
        $periods = $this->periods($lesson);
        $legacy = ($periods[0]['slot_id'] ?? null) === null;
        $own = $legacy ? collect() : TimetableSlot::where('lesson_id', $lesson->id)->get();
        $simple = $legacy || (collect($periods)->every(fn ($p) => ! $p['shared'])
            && $own->every(fn ($s) => $s->source === 'class') && $own->groupBy(fn ($s) => $s->weekday->value)->every(fn ($g) => $g->count() === 1));
        $nights = [];
        foreach (self::nights($periods) as $day => $n) {
            $nights[] = ['weekday' => $day, 'start' => substr($n['start'], 0, 5), 'end' => substr($n['end'], 0, 5), 'location_id' => $n['location_id']];
        }

        return ['source' => $legacy ? 'legacy' : 'timetable', 'editable' => $simple, 'nights' => $nights, 'periods' => count($periods)];
    }

    /** True when the class takes its schedule from its own days/times (no term, or no periods yet). */
    public function isLegacy(Lesson $lesson): bool
    {
        return ($this->periods($lesson)[0]['slot_id'] ?? null) === null;
    }

    /**
     * One entry per meeting night: from the first period's start to the last one's end, in the first period's room.
     *
     * @param  list<Period>  $periods
     * @return array<string, array{start: string, end: string, location_id: ?int}>
     */
    public static function nights(array $periods): array
    {
        $out = [];
        foreach ($periods as $p) {
            $n = $out[$p['weekday']] ?? null;
            $out[$p['weekday']] = $n === null
                ? ['start' => $p['start'], 'end' => $p['end'], 'location_id' => $p['location_id']]
                : ['start' => min($n['start'], $p['start']), 'end' => max($n['end'], $p['end']), 'location_id' => $p['start'] < $n['start'] ? $p['location_id'] : $n['location_id']];
        }
        uksort($out, fn ($a, $b) => array_search($a, self::WEEK, true) <=> array_search($b, self::WEEK, true));

        return $out;
    }

    /**
     * The class form's simple schedule (days + one time): written as the class's own Quran periods, one per day.
     * Refused when the class already has a richer timetable (several own periods a night, or other subjects) —
     * those are edited in الجدول الدراسي. Classes without a term keep writing their own days/times.
     *
     * @param  list<string>  $days
     */
    public function setOwnSchedule(Lesson $lesson, array $days, string $start, string $end): void
    {
        $lesson->loadMissing('package:id,academic_term_id');
        $term = $lesson->package?->academic_term_id;
        if (! $term) {
            return; // legacy: lessons.days/start_time/end_time are the schedule
        }
        $own = TimetableSlot::where('lesson_id', $lesson->id)->get();
        $quran = Subject::quranId();
        $simple = $own->every(fn ($s) => $s->source === 'class') && $own->groupBy(fn ($s) => $s->weekday->value)->every(fn ($g) => $g->count() === 1);
        if (! $simple) {
            throw ValidationException::withMessages(['days' => __('term_setup.errors.edit_in_timetable')]);
        }
        TimetableSlot::where('lesson_id', $lesson->id)->where('source', 'class')->delete();
        foreach (array_values(array_unique($days)) as $day) {
            TimetableSlot::create([
                'academic_term_id' => $term, 'level_id' => $lesson->level_id, 'lesson_id' => $lesson->id,
                'weekday' => $day, 'start_time' => WeekDays::time($start), 'end_time' => WeekDays::time($end),
                'subject_id' => $quran, 'source' => 'class',
            ]);
        }
    }

    /** Write the lessons.days / start_time / end_time copy from the timetable (legacy classes are left as they are). */
    public function syncCopy(Lesson $lesson): void
    {
        $periods = $this->periods($lesson);
        if (($periods[0]['slot_id'] ?? null) === null) {
            return;
        }
        $nights = self::nights($periods);
        $lesson->forceFill([
            'days' => array_keys($nights),
            'start_time' => min(array_column($nights, 'start')),
            'end_time' => max(array_column($nights, 'end')),
        ]);
        if ($lesson->isDirty()) {
            $lesson->saveQuietly();
        }
    }

    /**
     * Classes whose periods a timetable period touches: its class, or every class of its level in its term.
     *
     * @return Collection<int, Lesson>
     */
    public function classesOf(TimetableSlot $slot): Collection
    {
        if ($slot->lesson_id) {
            return Lesson::whereKey($slot->lesson_id)->get();
        }

        return Lesson::where('level_id', $slot->level_id)
            ->whereHas('package', fn ($q) => $q->where('academic_term_id', $slot->academic_term_id))->get();
    }

    /** @return list<Period> Periods of a legacy class: one per day from its own days/times. */
    private function legacy(Lesson $lesson): array
    {
        return collect($lesson->days ?? [])->unique()->map(fn ($d) => [
            'slot_id' => null, 'weekday' => $d, 'start' => WeekDays::time((string) $lesson->start_time), 'end' => WeekDays::time((string) $lesson->end_time),
            'location_id' => $lesson->location_id, 'subject_id' => null, 'teacher_id' => $lesson->teacher_id, 'shared' => false,
        ])->sortBy(fn ($p) => array_search($p['weekday'], self::WEEK, true))->values()->all();
    }
}
