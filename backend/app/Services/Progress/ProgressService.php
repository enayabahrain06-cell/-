<?php

namespace App\Services\Progress;

use App\Enums\MemorizationDirection;
use App\Enums\ProgressType;
use App\Models\Package;
use App\Models\Student;
use App\Models\StudentProgress;
use App\Support\Quran;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The memorization ledger (student_progress) is the source of truth.
 * Position, juz map and percentages are always derived from it; students.progress_* is only a cache.
 */
class ProgressService
{
    /**
     * Append one ledger row and refresh the student's cached position.
     *
     * @param  array{type:string, surah_number:int, from_ayah:int, to_ayah:int, recorded_on?:string|null, lesson_id?:int|null, note?:string|null}  $data
     */
    public function append(Student $student, array $data, ?int $by = null): StudentProgress
    {
        $surah = (int) $data['surah_number'];
        $from = (int) $data['from_ayah'];
        $to = (int) $data['to_ayah'];
        $this->assertRange($surah, $from, $to);

        return DB::transaction(function () use ($student, $data, $surah, $from, $to, $by) {
            $row = StudentProgress::create([
                'student_id' => $student->id,
                'lesson_id' => $data['lesson_id'] ?? null,
                'type' => $data['type'],
                'surah_number' => $surah,
                'from_ayah' => $from,
                'to_ayah' => $to,
                'ayah_count' => $to - $from + 1,
                'recorded_on' => $data['recorded_on'] ?? today()->toDateString(),
                'recorded_by' => $by ?? auth()->id(),
                'note' => $data['note'] ?? null,
            ]);

            $this->refreshCache($student);

            // A newly completed juz (or the whole Quran) drafts a completion certificate.
            if ($row->type === ProgressType::Memorized) {
                app(\App\Services\Certificates\AutoCertificateIssuer::class)->afterProgress($student);
            }

            return $row;
        });
    }

    public function remove(StudentProgress $row): void
    {
        DB::transaction(function () use ($row) {
            $student = $row->student;
            $row->delete();
            $this->refreshCache($student);
        });
    }

    public function assertRange(int $surah, int $from, int $to): void
    {
        if (! Quran::exists($surah)) {
            throw ValidationException::withMessages(['surah_number' => __('progress.invalid_surah')]);
        }
        $max = Quran::ayahCount($surah);
        if ($from < 1 || $to < $from || $to > $max) {
            throw ValidationException::withMessages(['to_ayah' => __('progress.invalid_range', ['max' => $max])]);
        }
    }

    public function directionFor(Student $student, ?Package $package = null): MemorizationDirection
    {
        $package ??= $student->currentPackage();

        return $package?->memorization_direction
            ?? MemorizationDirection::tryFrom((string) setting('progress.default_direction', 'backward'))
            ?? MemorizationDirection::Backward;
    }

    /**
     * Set of memorized ayahs as global indexes (1..6236) => true.
     *
     * @param  iterable<StudentProgress>|null  $rows
     * @return array<int,true>
     */
    public function memorizedSet(Student $student, ?iterable $rows = null, ?CarbonInterface $since = null): array
    {
        $rows ??= $student->progress()->where('type', ProgressType::Memorized->value)->get(['surah_number', 'from_ayah', 'to_ayah', 'recorded_on']);
        $set = [];
        foreach ($rows as $r) {
            if ($since && $r->recorded_on && $r->recorded_on->lt($since->copy()->startOfDay())) {
                continue;
            }
            $start = Quran::globalIndex($r->surah_number, $r->from_ayah);
            $end = Quran::globalIndex($r->surah_number, $r->to_ayah);
            for ($i = $start; $i <= $end; $i++) {
                $set[$i] = true;
            }
        }

        return $set;
    }

    /**
     * Current position: the furthest memorized ayah in the student's direction, plus the next ayah to memorize.
     *
     * @param  array<int,true>  $set
     */
    public function position(array $set, MemorizationDirection $direction, string $locale = 'ar'): array
    {
        $furthest = 0;
        foreach (array_keys($set) as $g) {
            [$s, $a] = Quran::fromGlobalIndex($g);
            $furthest = max($furthest, Quran::sequenceIndex($s, $a, $direction));
        }

        if ($furthest === 0) {
            [$ns, $na] = Quran::startOf($direction);

            return [
                'direction' => $direction->value,
                'current' => null,
                'next' => $this->point($ns, $na, $direction, $locale),
            ];
        }

        [$cs, $ca] = Quran::fromSequenceIndex($furthest, $direction);
        $next = $furthest < Quran::TOTAL_AYAHS ? Quran::fromSequenceIndex($furthest + 1, $direction) : null;

        return [
            'direction' => $direction->value,
            'current' => $this->point($cs, $ca, $direction, $locale),
            'next' => $next ? $this->point($next[0], $next[1], $direction, $locale) : null,
        ];
    }

    /** @return array{surah:int, surah_name:string, ayah:int, juz:int, juz_ordinal:int, label:string} */
    public function point(int $surah, int $ayah, MemorizationDirection $direction, string $locale = 'ar'): array
    {
        $juz = Quran::juzOf($surah, $ayah);
        $name = Quran::name($surah, $locale);

        return [
            'surah' => $surah,
            'surah_name' => $name,
            'ayah' => $ayah,
            'juz' => $juz,
            'juz_ordinal' => Quran::juzOrdinal($juz, $direction),
            'label' => __('progress.position_label', ['surah' => $name, 'ayah' => $ayah, 'juz' => $juz], $locale),
        ];
    }

    /**
     * 30 cells: memorized / in_progress / not_started, with counts and percentage.
     *
     * @param  array<int,true>  $set
     * @return list<array{juz:int, ordinal:int, total:int, memorized:int, percent:int, status:string}>
     */
    public function juzMap(array $set, MemorizationDirection $direction): array
    {
        $totals = Quran::juzTotals();
        $counts = array_fill(1, Quran::JUZ_COUNT, 0);
        foreach (array_keys($set) as $g) {
            [$s, $a] = Quran::fromGlobalIndex($g);
            $counts[Quran::juzOf($s, $a)]++;
        }

        $cells = [];
        foreach ($totals as $juz => $total) {
            $done = $counts[$juz];
            $cells[] = [
                'juz' => $juz,
                'ordinal' => Quran::juzOrdinal($juz, $direction),
                'total' => $total,
                'memorized' => $done,
                'percent' => (int) floor($done * 100 / $total),
                'status' => $done === 0 ? 'not_started' : ($done >= $total ? 'memorized' : 'in_progress'),
            ];
        }

        // Present the map in the student's own order (Amma first for backward circles).
        usort($cells, fn ($x, $y) => $x['ordinal'] <=> $y['ordinal']);

        return $cells;
    }

    /** Full progress block for the profile. */
    public function summary(Student $student, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $package = $student->currentPackage();
        $direction = $this->directionFor($student, $package);

        $rows = $student->progress()->orderBy('recorded_on')->orderBy('id')->get();
        $memorizedRows = $rows->where('type', ProgressType::Memorized);
        $set = $this->memorizedSet($student, $memorizedRows);
        $memorized = count($set);

        // Yearly plan: ayahs newly memorized since the plan period started.
        $target = (int) ($student->yearly_target_ayahs ?: $package?->plan_ayahs ?: 0);
        $periodStart = $package?->start_date && $package->start_date->lte(today()) ? $package->start_date : today()->startOfYear();
        $before = $this->memorizedSet($student, $memorizedRows->filter(fn ($r) => $r->recorded_on->lt($periodStart)));
        $inPeriod = count(array_diff_key($set, $before));

        $revised30 = $rows->where('type', ProgressType::Revised)
            ->filter(fn ($r) => $r->recorded_on->gte(today()->subDays(30)))
            ->sum('ayah_count');

        return [
            'position' => $this->position($set, $direction, $locale),
            'memorized_ayahs' => $memorized,
            'quran_percent' => round($memorized * 100 / Quran::TOTAL_AYAHS, 1),
            'completed_juz' => collect($this->juzMap($set, $direction))->where('status', 'memorized')->count(),
            'juz_map' => $this->juzMap($set, $direction),
            'plan' => [
                'target_ayahs' => $target,
                'period_start' => $periodStart->toDateString(),
                'memorized_in_period' => $inPeriod,
                'percent' => $target > 0 ? min(100, (int) round($inPeriod * 100 / $target)) : null,
            ],
            'revised_ayahs_30d' => (int) $revised30,
            'recent' => $rows->sortByDesc(fn ($r) => [$r->recorded_on->toDateString(), $r->id])->take(10)->values()
                ->map(fn ($r) => $this->ledgerRow($r, $locale))->all(),
        ];
    }

    public function ledgerRow(StudentProgress $r, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return [
            'id' => $r->id,
            'type' => $r->type->value,
            'type_label' => $r->type->label($locale),
            'surah_number' => $r->surah_number,
            'surah_name' => Quran::name($r->surah_number, $locale),
            'from_ayah' => $r->from_ayah,
            'to_ayah' => $r->to_ayah,
            'ayah_count' => $r->ayah_count,
            'juz' => Quran::juzOf($r->surah_number, $r->from_ayah),
            'recorded_on' => $r->recorded_on?->toDateString(),
            'recorded_by' => $r->recorded_by,
            'lesson_id' => $r->lesson_id,
            'note' => $r->note,
        ];
    }

    /** Recompute students.progress_* from the ledger. */
    public function refreshCache(Student $student): void
    {
        $set = $this->memorizedSet($student);
        $pos = $this->position($set, $this->directionFor($student))['current'];

        $student->forceFill([
            'progress_surah' => $pos['surah'] ?? null,
            'progress_ayah' => $pos['ayah'] ?? null,
            'progress_juz' => $pos['juz'] ?? null,
            'memorized_ayahs' => count($set),
        ])->saveQuietly();
    }

    /** @param  Collection<int,Student>  $students */
    public function refreshMany(Collection $students): void
    {
        $students->each(fn (Student $s) => $this->refreshCache($s));
    }
}
