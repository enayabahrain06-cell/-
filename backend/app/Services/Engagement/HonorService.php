<?php

namespace App\Services\Engagement;

use App\Enums\CertificateSource;
use App\Enums\CertificateType;
use App\Enums\EvaluationType;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Enums\ProgressType;
use App\Enums\StudentStatus;
use App\Models\Attendance;
use App\Models\Badge;
use App\Models\Evaluation;
use App\Models\HonorPeriod;
use App\Models\HonorPoint;
use App\Models\HonorRanking;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\StudentBadge;
use App\Models\StudentProgress;
use App\Models\User;
use Ahl\Certificates\CertificateService;
use App\Services\Lessons\StudentMessenger;
use App\Services\Progress\ProgressService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Honor board (section 13). One period per month and gender track; boys and girls never share a ranking.
 * points = w_att × attendance% + w_eval × evaluation average/10 + w_mem × (new ayahs / best new ayahs in the track) + bonus
 * (weights from settings honor.weight_*; stored ×100 as integers).
 */
class HonorService
{
    public const GENDERS = ['male', 'female'];

    public function __construct(
        private ProgressService $progress,
        private StudentMessenger $messenger,
        private CertificateService $certificates,
    ) {}

    public static function weights(): array
    {
        $w = [
            'attendance' => max(0, (int) setting('honor.weight_attendance', 40)),
            'evaluation' => max(0, (int) setting('honor.weight_evaluation', 40)),
            'memorization' => max(0, (int) setting('honor.weight_memorization', 20)),
        ];
        $sum = array_sum($w) ?: 1;

        // Normalise to 100 so points stay on a 0–100 scale whatever the admin types.
        return array_map(fn ($v) => round($v * 100 / $sum, 2), $w);
    }

    public static function currentPeriod(): string
    {
        return now(config('ahl.display_timezone', 'Asia/Bahrain'))->format('Y-m');
    }

    public function period(string $period, string $gender): HonorPeriod
    {
        return HonorPeriod::firstOrCreate(['period' => $period, 'gender' => $gender], ['status' => 'open', 'weights' => self::weights()]);
    }

    /** Recompute one month for one track (idempotent). Finalized periods are not changed. */
    public function compute(string $period, string $gender): HonorPeriod
    {
        $hp = $this->period($period, $gender);
        if ($hp->status !== 'open') {
            return $hp;
        }
        $hp->update(['weights' => self::weights()]);
        $w = $hp->weights;

        $tz = config('ahl.display_timezone', 'Asia/Bahrain');
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01', $tz)->startOfDay();
        $to = $from->copy()->endOfMonth();

        $enrol = LessonStudent::with('lesson:id,package_id')->where('status', LessonStudentStatus::Active->value)
            ->whereHas('student', fn ($q) => $q->where('gender', $gender)->where('status', StudentStatus::Active->value))
            ->orderBy('joined_at')->get()->unique('student_id')->keyBy('student_id');

        $rows = [];
        foreach ($enrol as $studentId => $ls) {
            $att = Attendance::where('student_id', $studentId)
                ->whereHas('session', fn ($q) => $q->whereBetween('session_date', [$from->toDateString(), $to->toDateString()]))
                ->get(['status'])->countBy(fn ($a) => $a->status->value);
            $counted = $att->sum() - ($att['excused'] ?? 0);
            $attPct = $counted > 0 ? (int) round((($att['present'] ?? 0) + ($att['late'] ?? 0)) * 100 / $counted) : 0;

            $evals = Evaluation::quran()->where('student_id', $studentId)->where('type', EvaluationType::Daily->value)
                ->whereBetween('evaluated_on', [$from->toDateString(), $to->toDateString()])->get(['memorization', 'tajweed', 'revision', 'behavior']);
            $evalAvgX100 = $evals->isEmpty() ? 0 : (int) round($evals->avg(fn ($e) => ($e->memorization + $e->tajweed + $e->revision + $e->behavior) / 4) * 100);

            $rowsLedger = StudentProgress::where('student_id', $studentId)->where('type', ProgressType::Memorized->value)
                ->where('recorded_on', '<=', $to->toDateString())->get(['surah_number', 'from_ayah', 'to_ayah', 'recorded_on']);
            $student = new Student(['id' => $studentId]);
            $student->id = $studentId;
            $all = $this->progress->memorizedSet($student, $rowsLedger);
            $before = $this->progress->memorizedSet($student, $rowsLedger->filter(fn ($r) => $r->recorded_on->lt($from)));
            $newAyahs = count(array_diff_key($all, $before));

            $rows[$studentId] = [
                'lesson_id' => $ls->lesson_id, 'package_id' => $ls->lesson?->package_id,
                'attendance_pct' => $attPct, 'evaluation_avg_x100' => $evalAvgX100, 'new_ayahs' => $newAyahs,
                'sessions' => $counted, 'tajweed_x100' => $evals->count() >= 3 ? (int) round($evals->avg('tajweed') * 100) : null,
            ];
        }

        $bestNew = max(1, (int) collect($rows)->max('new_ayahs'));
        $bonus = HonorPoint::where('period', $period)->whereIn('student_id', array_keys($rows))->get()->groupBy('student_id')->map->sum('points_x100');
        $previous = HonorRanking::whereHas('period', fn ($q) => $q->where('gender', $gender)->where('period', $from->copy()->subMonth()->format('Y-m')))
            ->pluck('points_x100', 'student_id');

        foreach ($rows as $id => &$r) {
            $r['attendance_points_x100'] = (int) round($w['attendance'] * $r['attendance_pct'] / 100 * 100);
            $r['evaluation_points_x100'] = (int) round($w['evaluation'] * ($r['evaluation_avg_x100'] / 1000) * 100);
            $r['memorization_points_x100'] = (int) round($w['memorization'] * min(1, $r['new_ayahs'] / $bestNew) * 100);
            $r['bonus_points_x100'] = (int) ($bonus[$id] ?? 0);
            $r['points_x100'] = $r['attendance_points_x100'] + $r['evaluation_points_x100'] + $r['memorization_points_x100'] + $r['bonus_points_x100'];
            $r['points_change_x100'] = isset($previous[$id]) ? $r['points_x100'] - (int) $previous[$id] : null;
        }
        unset($r);

        // Standard competition ranking (1, 2, 2, 4) inside the track, the package and the circle.
        $rank = function (Collection $group): array {
            $out = [];
            foreach ($group as $id => $r) {
                $out[$id] = 1 + $group->filter(fn ($x) => $x['points_x100'] > $r['points_x100'])->count();
            }

            return $out;
        };
        $all = collect($rows);
        $rTrack = $rank($all);
        $rPkg = $all->groupBy('package_id', true)->reduce(fn ($acc, $g) => $acc + $rank($g), []); // + keeps student-id keys
        $rCircle = $all->groupBy('lesson_id', true)->reduce(fn ($acc, $g) => $acc + $rank($g), []);

        DB::transaction(function () use ($hp, $rows, $rTrack, $rPkg, $rCircle) {
            $hp->rankings()->whereNotIn('student_id', array_keys($rows))->delete();
            foreach ($rows as $id => $r) {
                HonorRanking::updateOrCreate(['honor_period_id' => $hp->id, 'student_id' => $id], [
                    'lesson_id' => $r['lesson_id'], 'package_id' => $r['package_id'],
                    'attendance_pct' => $r['attendance_pct'], 'evaluation_avg_x100' => $r['evaluation_avg_x100'], 'new_ayahs' => $r['new_ayahs'],
                    'attendance_points_x100' => $r['attendance_points_x100'], 'evaluation_points_x100' => $r['evaluation_points_x100'],
                    'memorization_points_x100' => $r['memorization_points_x100'], 'bonus_points_x100' => $r['bonus_points_x100'],
                    'points_x100' => $r['points_x100'], 'points_change_x100' => $r['points_change_x100'],
                    'rank_in_track' => $rTrack[$id], 'rank_in_package' => $rPkg[$id] ?? null, 'rank_in_circle' => $rCircle[$id] ?? null,
                ]);
            }

            // Circle of the month: highest average points among circles with at least 3 ranked students.
            $circle = collect($rows)->groupBy('lesson_id')->filter(fn ($g) => $g->count() >= 3)
                ->map(fn ($g) => $g->avg('points_x100'))->sortDesc()->keys()->first();
            $hp->update(['circle_of_month_lesson_id' => $circle]);
        });

        $this->awardBadges($hp, $rows);

        return $hp->fresh();
    }

    /** Apply badge rules for this period. Lifetime badges use period "once"; monthly ones the YYYY-MM. */
    public function awardBadges(HonorPeriod $hp, array $rows): int
    {
        $awarded = 0;
        $minSessions = (int) setting('honor.full_attendance_min_sessions', 4);
        $badges = Badge::where('is_active', true)->get();
        $improved = collect($rows)->filter(fn ($r) => ($r['points_change_x100'] ?? 0) > 0)->sortByDesc('points_change_x100');
        $mostImprovedId = $improved->keys()->first();

        foreach ($badges as $badge) {
            $period = $badge->repeatable_monthly ? $hp->period : 'once';
            foreach ($rows as $studentId => $r) {
                $earned = match ($badge->rule_type) {
                    'full_attendance' => $r['sessions'] >= $minSessions && $r['attendance_pct'] >= (int) ($badge->rule_value ?? 100),
                    'tajweed_average' => $r['tajweed_x100'] !== null && $r['tajweed_x100'] >= (int) ($badge->rule_value ?? 900),
                    'most_improved' => $studentId === $mostImprovedId,
                    'completed_juz' => $this->completedJuz($studentId, (int) $badge->rule_value),
                    default => false, // competition / challenge / manual badges are awarded by their own modules
                };
                if ($earned && $this->grant($studentId, $badge, $period, HonorPeriod::class, $hp->id)) {
                    $awarded++;
                }
            }
        }

        return $awarded;
    }

    public function grant(int $studentId, Badge $badge, string $period, ?string $sourceType = null, ?int $sourceId = null, ?int $by = null): bool
    {
        $row = StudentBadge::firstOrCreate(
            ['student_id' => $studentId, 'badge_id' => $badge->id, 'period' => $period],
            ['awarded_at' => now(), 'source_type' => $sourceType, 'source_id' => $sourceId, 'awarded_by' => $by]
        );

        return $row->wasRecentlyCreated;
    }

    private function completedJuz(int $studentId, int $juz): bool
    {
        $student = Student::find($studentId);
        if (! $student || $juz < 1 || $juz > 30) {
            return false;
        }
        $map = $this->progress->juzMap($this->progress->memorizedSet($student), $this->progress->directionFor($student));

        return collect($map)->firstWhere('juz', $juz)['status'] === 'memorized';
    }

    /** Close a month: it keeps its history and no longer changes. */
    public function finalize(HonorPeriod $hp): HonorPeriod
    {
        if ($hp->status === 'open') {
            $this->compute($hp->period, $hp->gender);
            $hp->update(['status' => 'finalized', 'finalized_at' => now()]);
        }

        return $hp->fresh();
    }

    /**
     * Monthly honoring: excellence certificate drafts for the top 3, congratulation messages to guardians,
     * optional publication to students. Idempotent per student and period.
     *
     * @return array{certificates:int, messages:int}
     */
    public function honor(HonorPeriod $hp, User $by, bool $certificates = true, bool $messages = true, bool $publish = true): array
    {
        $top = $hp->rankings()->with(['student', 'lesson:id,name'])->where('rank_in_track', '<=', 3)->orderBy('rank_in_track')->get();
        $made = 0;
        $sent = 0;
        foreach ($top as $r) {
            if (! $r->student) {
                continue;
            }
            $locale = $r->student->locale?->value ?? 'ar';
            $place = __("honor.place.{$r->rank_in_track}", [], $locale);
            if ($certificates && ! $this->certificates->exists($r->student, CertificateSource::HonorPeriod, $hp->id)) {
                $this->certificates->createDraft($r->student, CertificateType::Excellence, [
                    'achievement' => __('honor.certificate_achievement', ['place' => $place, 'month' => $hp->period], $locale),
                    'context' => $r->lesson_id ? Lesson::find($r->lesson_id) : null,
                    'source' => CertificateSource::HonorPeriod,
                    'source_id' => $hp->id,
                ], $by);
                $made++;
            }
            if ($messages) {
                $sent += $this->messenger->notify($r->student, MessageType::HonorCongrats, [
                    'place' => $place,
                    'month' => $hp->period,
                    'lesson' => $r->lesson?->name ?? '',
                    'points' => number_format($r->points_x100 / 100, 1),
                ]);
            }
        }
        $hp->update(['status' => 'honored', 'honored_at' => now(), 'honored_by' => $by->id, 'published_to_students' => $publish || $hp->published_to_students]);

        return ['certificates' => $made, 'messages' => $sent];
    }

    /** Bonus points from a competition or challenge, written once per source and student. */
    public function addPoints(int $studentId, int $points, string $sourceType, int $sourceId, string $reason, ?string $period = null): bool
    {
        if ($points <= 0) {
            return false;
        }
        $row = HonorPoint::firstOrCreate(
            ['source_type' => $sourceType, 'source_id' => $sourceId, 'student_id' => $studentId],
            ['period' => $period ?? self::currentPeriod(), 'points_x100' => $points * 100, 'reason' => $reason]
        );

        return $row->wasRecentlyCreated;
    }
}
