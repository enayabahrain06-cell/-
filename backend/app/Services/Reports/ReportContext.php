<?php

namespace App\Services\Reports;

use App\Models\Lesson;
use App\Models\User;
use App\Support\TermScope;
use App\Support\Track;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Filters and scope shared by the operational reports (attendance, absence, evaluation, exams, teachers, messages).
 *
 * Period: from/to in the display timezone (default: this month up to today). Circles: the viewer's gender track,
 * teachers without lessons.manage limited to their own circles, then the lesson_id / package_id / teacher_id /
 * gender filters. Everything that follows a circle reuses lessonQuery() as a subquery, so no id lists are sent.
 */
final class ReportContext
{
    public readonly string $tz;

    public readonly string $locale;

    public readonly Carbon $from;

    public readonly Carbon $to;

    /** @param  array{from?:string|null,to?:string|null,lesson_id?:int|null,package_id?:int|null,teacher_id?:int|null,gender?:string|null}  $f */
    public function __construct(public readonly User $user, public readonly array $f = [])
    {
        $this->tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $this->locale = app()->getLocale();
        $this->from = ! empty($f['from']) ? Carbon::parse($f['from'], $this->tz)->startOfDay() : now($this->tz)->startOfMonth();
        $this->to = ! empty($f['to']) ? Carbon::parse($f['to'], $this->tz)->endOfDay() : now($this->tz)->endOfDay();
    }

    public function teacherOnly(): bool
    {
        return ! $this->user->can('lessons.manage');
    }

    /** Circles in scope (not limited to active ones: a report covers circles that ran in the period). */
    public function lessonQuery(): Builder
    {
        return Lesson::query()
            ->tap(fn ($q) => Track::scope($q, $this->user))
            ->when($this->teacherOnly(), fn ($q) => $q->where('teacher_id', $this->user->id))
            ->when(! empty($this->f['lesson_id']), fn ($q) => $q->where('id', $this->f['lesson_id']))
            ->when(! empty($this->f['package_id']), fn ($q) => $q->where('package_id', $this->f['package_id']))
            ->when(! empty($this->f['teacher_id']), fn ($q) => $q->where('teacher_id', $this->f['teacher_id']))
            ->when(! empty($this->f['gender']), fn ($q) => $q->where('gender', $this->f['gender']))
            ->tap(fn ($q) => TermScope::via($q, $this->term()));
    }

    /** Academic term filter (id, or a legacy label), set by the controller from ?term_id=. */
    public function term(): int|string|null
    {
        return $this->f['term'] ?? null;
    }

    public function lessonIds(): Builder
    {
        return $this->lessonQuery()->select('id');
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** Last day that has already happened within the period (sessions after today are not "due" yet). */
    public function dueUntil(): string
    {
        return min($this->toDate(), now($this->tz)->toDateString());
    }

    public function period(): string
    {
        return __('reports.period', ['from' => $this->fromDate(), 'to' => $this->toDate()]);
    }

    public function filters(): array
    {
        return [
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'lesson_id' => $this->f['lesson_id'] ?? null,
            'package_id' => $this->f['package_id'] ?? null,
            'teacher_id' => $this->f['teacher_id'] ?? null,
            'gender' => $this->f['gender'] ?? null,
            'term_id' => is_int($this->term()) ? $this->term() : null,
            'track' => Track::genderFor($this->user)?->value ?? 'both',
            'own_circles_only' => $this->teacherOnly(),
        ];
    }

    /** Whole-percent rate, or null when there is nothing to divide by. */
    public static function rate(int|float $part, int|float $whole): ?int
    {
        return $whole > 0 ? (int) round($part * 100 / $whole) : null;
    }

    public static function avg(Collection $values, int $precision = 1): ?float
    {
        $values = $values->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), $precision);
    }

    /**
     * Present / late / absent / excused counts and the attendance rate: (present + late) over every record except excused,
     * the same rule as the dashboard.
     *
     * @return array{present:int,late:int,absent:int,excused:int,total:int,rate:?int}
     */
    public static function tally(Collection $attendances): array
    {
        $c = $attendances->countBy(fn ($a) => $a->status->value);
        $present = (int) ($c['present'] ?? 0);
        $late = (int) ($c['late'] ?? 0);
        $absent = (int) ($c['absent'] ?? 0);
        $excused = (int) ($c['excused'] ?? 0);

        return [
            'present' => $present, 'late' => $late, 'absent' => $absent, 'excused' => $excused,
            'total' => $present + $late + $absent + $excused,
            'rate' => self::rate($present + $late, $present + $late + $absent),
        ];
    }

    /** Enum label with a readable fallback when a case has no translation yet. */
    public static function label(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $key = "enums.{$group}.{$value}";
        $out = __($key);

        return $out === $key ? \Illuminate\Support\Str::headline($value) : $out;
    }
}
