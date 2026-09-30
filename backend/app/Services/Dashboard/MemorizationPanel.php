<?php

namespace App\Services\Dashboard;

use App\Support\TermScope;
use App\Enums\LessonStudentStatus;
use App\Enums\ProgressType;
use App\Enums\StudentStatus;
use App\Models\LessonStudent;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\Progress\ProgressService;
use App\Support\Quran;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard "Memorization progress" card. Students in scope = active students actively enrolled in a
 * circle the user may see (DashboardService::lessonScope: track, own circles for teachers, term).
 *
 * Rules (shown to the user as help text):
 *  - Pages: no page data is stored, so pages ≈ ayahs ÷ (6236 ÷ 604) — the Madani mushaf average.
 *  - Reviews due: students with no "revised" ledger row in the last 7 days.
 *  - Finished a juz this month: a juz that is fully memorized now but was not before the month started
 *    (derived from the memorization ledger, independent of whether certificates are auto-drafted).
 *  - Behind plan (U4): when the student has no own yearly target and the class's level has a Quran plan with ayah
 *    targets this term, planned-to-date = the targets of the weeks started so far (from the term start). Otherwise
 *    planned-to-date = target (student.yearly_target_ayahs ?: package.plan_ayahs) ×
 *    elapsed share of the package period (start_date → end_date, or one year); actual = ayahs newly
 *    memorized since the period started (ProgressService set maths); gap = planned − actual.
 */
class MemorizationPanel
{
    public const AYAHS_PER_PAGE = Quran::TOTAL_AYAHS / 604;

    public function __construct(private DashboardService $dashboard, private ProgressService $progress) {}

    public function build(User $user, string|int|null $term = null): array
    {
        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $today = Carbon::parse(now($tz)->toDateString());
        $weekStart = $today->copy()->startOfWeek(Carbon::SATURDAY);
        $monthStart = $today->copy()->startOfMonth();

        // One enrolment per student (earliest joined), with its circle and package.
        $enrolments = LessonStudent::with(['lesson:id,name,package_id,level_id', 'lesson.package', 'student'])
            ->where('status', LessonStudentStatus::Active->value)
            ->whereIn('lesson_id', $this->dashboard->lessonScope($user, $term)->select('id'))
            ->whereHas('student', fn ($q) => $q->where('status', StudentStatus::Active->value))
            ->orderBy('joined_at')->orderBy('id')->get()
            ->unique('student_id')->keyBy('student_id');

        $ids = $enrolments->keys();
        $rows = $ids->isEmpty() ? collect() : StudentProgress::whereIn('student_id', $ids)
            ->get(['id', 'student_id', 'type', 'surah_number', 'from_ayah', 'to_ayah', 'ayah_count', 'recorded_on'])
            ->groupBy('student_id');

        $weekAyahs = 0;
        $reviewsDue = 0;
        $juzFinishers = 0;
        $behind = [];

        foreach ($enrolments as $studentId => $enrolment) {
            /** @var Collection $studentRows */
            $studentRows = $rows->get($studentId, collect());
            $memorized = $studentRows->filter(fn ($r) => $r->type === ProgressType::Memorized);

            $weekAyahs += (int) $memorized->filter(fn ($r) => $r->recorded_on->gte($weekStart))->sum('ayah_count');

            $revisedRecently = $studentRows->contains(fn ($r) => $r->type === ProgressType::Revised && $r->recorded_on->gte($today->copy()->subDays(6)));
            if (! $revisedRecently) {
                $reviewsDue++;
            }

            $student = $enrolment->student;
            $set = $this->progress->memorizedSet($student, $memorized);

            if ($this->finishedJuzSince($set, $memorized, $student, $monthStart)) {
                $juzFinishers++;
            }

            $row = $this->planGap($enrolment, $set, $memorized, $today);
            if ($row) {
                $behind[] = $row;
            }
        }

        usort($behind, fn ($a, $b) => $b['gap_ayahs'] <=> $a['gap_ayahs'] ?: $a['name'] <=> $b['name']);

        return [
            'students' => $enrolments->count(),
            'pages_this_week' => $this->pages($weekAyahs),
            'ayahs_this_week' => $weekAyahs,
            'reviews_due' => $reviewsDue,
            'juz_finished_this_month' => $juzFinishers,
            'behind_total' => count($behind),
            'behind' => array_slice($behind, 0, 5),
            'week_start' => $weekStart->toDateString(),
        ];
    }

    private function finishedJuzSince(array $set, Collection $memorized, $student, Carbon $monthStart): bool
    {
        if ($set === [] || ! $memorized->contains(fn ($r) => $r->recorded_on->gte($monthStart))) {
            return false;
        }
        $before = $this->progress->memorizedSet($student, $memorized->filter(fn ($r) => $r->recorded_on->lt($monthStart)));

        $complete = function (array $s): array {
            $counts = [];
            foreach (array_keys($s) as $g) {
                [$surah, $ayah] = Quran::fromGlobalIndex($g);
                $j = Quran::juzOf($surah, $ayah);
                $counts[$j] = ($counts[$j] ?? 0) + 1;
            }

            return array_keys(array_filter($counts, fn ($c, $j) => $c >= Quran::juzTotals()[$j], ARRAY_FILTER_USE_BOTH));
        };

        return array_diff($complete($set), $complete($before)) !== [];
    }

    /** @var array<int, array{0: int, 1: Carbon, 2: int}|null> level Quran plan per class, memoised for this build */
    private array $plans = [];

    /**
     * Ayahs a class's level Quran plan expects by today (weeks counted from the term start), or null when the level
     * has no Quran plan with ayah targets this term. Returns [planned ayahs, plan start, whole-term target].
     */
    private function levelPlan(?\App\Models\Lesson $lesson, Carbon $today): ?array
    {
        if (! $lesson || ! $lesson->level_id || ! $lesson->package?->academic_term_id) {
            return null;
        }
        if (array_key_exists($lesson->id, $this->plans)) {
            return $this->plans[$lesson->id];
        }
        $term = \App\Models\AcademicTerm::find($lesson->package->academic_term_id);
        $startDate = $term?->start_date ?? $lesson->package->start_date;
        $ls = \App\Models\LevelSubject::where(['academic_term_id' => $lesson->package->academic_term_id, 'level_id' => $lesson->level_id, 'subject_id' => \App\Models\Subject::quranId()])->first();
        $items = $ls ? \App\Models\PlanItem::where('level_subject_id', $ls->id)->whereNotNull('target_ayahs')->get(['week_no', 'target_ayahs']) : collect();
        if (! $startDate || $items->isEmpty()) {
            return $this->plans[$lesson->id] = null;
        }
        $start = Carbon::parse($startDate->toDateString());
        $weeks = $today->lt($start) ? 0 : intdiv((int) $start->diffInDays($today), 7) + 1;

        return $this->plans[$lesson->id] = [(int) $items->where('week_no', '<=', $weeks)->sum('target_ayahs'), $start, max(1, (int) $items->sum('target_ayahs'))];
    }

    private function planGap(LessonStudent $enrolment, array $set, Collection $memorized, Carbon $today): ?array
    {
        $student = $enrolment->student;
        $package = $enrolment->lesson?->package;
        // U4: the student's own target, else the level's Quran plan (ayahs of the weeks started so far), else the package.
        $levelPlan = $student->yearly_target_ayahs ? null : $this->levelPlan($enrolment->lesson, $today);
        if ($levelPlan) {
            [$planned, $start, $target] = $levelPlan;
        } else {
            $target = (int) ($student->yearly_target_ayahs ?: $package?->plan_ayahs ?: 0);
            if ($target <= 0) {
                return null;
            }
            $start = $package?->start_date && $package->start_date->lte($today) ? Carbon::parse($package->start_date->toDateString()) : $today->copy()->startOfYear();
            $end = $package?->end_date && $package->end_date->gt($start) ? Carbon::parse($package->end_date->toDateString()) : $start->copy()->addYear();
            $span = max(1, $start->diffInDays($end));
            $elapsed = min(1, max(0, $start->diffInDays($today) / $span));
            $planned = (int) round($target * $elapsed);
        }

        $before = $this->progress->memorizedSet($student, $memorized->filter(fn ($r) => $r->recorded_on->lt($start)));
        $actual = count(array_diff_key($set, $before));
        $gap = $planned - $actual;
        if ($gap <= 0) {
            return null;
        }

        return [
            'student_id' => $student->id,
            'name' => $student->full_name,
            'lesson_id' => $enrolment->lesson_id,
            'lesson' => $enrolment->lesson?->name,
            'planned_ayahs' => $planned,
            'actual_ayahs' => $actual,
            'gap_ayahs' => $gap,
            'gap_pages' => $this->pages($gap),
            'plan_percent' => min(100, (int) round($actual * 100 / $target)),
            'target_ayahs' => $target,
        ];
    }

    private function pages(int $ayahs): float
    {
        return round($ayahs / self::AYAHS_PER_PAGE, 1);
    }
}
