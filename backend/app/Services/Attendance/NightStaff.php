<?php

namespace App\Services\Attendance;

use App\Enums\SessionStatus;
use App\Models\AcademicTerm;
use App\Models\LessonSession;
use App\Models\NightSupervisor;
use App\Services\Lessons\ClassSchedule;
use App\Support\TermScope;
use App\Support\WeekDays;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who works on a night, read from the existing sources only (no copy of the schedule):
 * - the night's sessions: lesson_sessions of the term's classes, not cancelled (SessionSync writes them);
 * - the teachers of a session: the teachers of the class's timetable periods on that weekday (ClassSchedule: the
 *   period's teacher, else the class teacher);
 * - the supervisors of a night: مشرفو الليالي (night_supervisors) of the term for that weekday.
 */
class NightStaff
{
    public function __construct(private ClassSchedule $schedule) {}

    /** Not-cancelled sessions of the term's classes between two dates (inclusive). */
    public function sessions(AcademicTerm $term, string $from, string $to): Builder
    {
        return LessonSession::query()
            ->where('status', '!=', SessionStatus::Cancelled->value)
            ->whereBetween('session_date', [$from, $to])
            ->tap(fn ($q) => TermScope::via($q, $term->id, 'lesson.package'));
    }

    /**
     * Teacher ids of each session's night (the class's periods on the session's weekday), in period order.
     *
     * @param  Collection<int, LessonSession>  $sessions  with lesson loaded
     * @return array<int, list<int>> session id => teacher ids
     */
    public function teachersOf(Collection $sessions): array
    {
        $lessons = $sessions->pluck('lesson')->filter()->unique('id')->values();
        $periods = $lessons->isEmpty() ? [] : $this->schedule->periodsFor($lessons);
        $out = [];
        foreach ($sessions as $s) {
            $day = WeekDays::keyFor(Carbon::parse($s->session_date));
            $ids = collect($periods[$s->lesson_id] ?? [])->where('weekday', $day)->pluck('teacher_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
            if (! $ids && $s->lesson?->teacher_id) {
                $ids = [(int) $s->lesson->teacher_id];
            }
            $out[$s->id] = $ids;
        }

        return $out;
    }

    /**
     * Teacher id => the sessions they teach, for the term's sessions between two dates.
     *
     * @return array<int, Collection<int, LessonSession>>
     */
    public function teacherSessions(AcademicTerm $term, string $from, string $to): array
    {
        $sessions = $this->sessions($term, $from, $to)->with(['lesson:id,name,package_id,level_id,teacher_id,location_id,days,start_time,end_time'])
            ->orderBy('session_date')->orderBy('start_time')->get();
        $out = [];
        foreach ($this->teachersOf($sessions) as $sessionId => $teachers) {
            foreach ($teachers as $t) {
                $out[$t] ??= collect();
                $out[$t]->push($sessions->firstWhere('id', $sessionId));
            }
        }

        return $out;
    }

    /** Supervisor user ids on duty on a weekday of the term (مشرفو الليالي). @return list<int> */
    public function supervisorsOn(AcademicTerm $term, string $weekday): array
    {
        return NightSupervisor::where('academic_term_id', $term->id)->where('weekday', $weekday)->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Dates between two dates on which the term has at least one session (nights that are held). @return list<string> */
    public function heldDates(AcademicTerm $term, string $from, string $to): array
    {
        return $this->sessions($term, $from, $to)->distinct()->orderBy('session_date')->pluck('session_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values()->all();
    }

    /** The term's date range clipped to [$from, $to]; null when empty. @return array{0: string, 1: string}|null */
    public static function clip(AcademicTerm $term, string $from, string $to): ?array
    {
        $a = $term->start_date && $term->start_date->toDateString() > $from ? $term->start_date->toDateString() : $from;
        $b = $term->end_date && $term->end_date->toDateString() < $to ? $term->end_date->toDateString() : $to;

        return $a <= $b ? [$a, $b] : null;
    }
}
