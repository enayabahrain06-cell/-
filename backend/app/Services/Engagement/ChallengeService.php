<?php

namespace App\Services\Engagement;

use App\Enums\EvaluationType;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Enums\ProgressType;
use App\Enums\StudentStatus;
use App\Models\Attendance;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\Evaluation;
use App\Models\HonorRanking;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\StudentProgress;
use App\Models\User;
use App\Services\Lessons\StudentMessenger;
use App\Support\Quran;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Challenges (section 14): self-paced goals tracked only from existing records (attendance, evaluations,
 * memorization ledger, honor points). Nothing is entered manually. Rewards (badge + honor points) are
 * written exactly once, guarded by challenge_participants.rewarded_at.
 */
class ChallengeService
{
    public const GOALS = ['memorize_range', 'attendance_days', 'revision_range', 'score_streak', 'points'];

    public function __construct(private HonorService $honor, private StudentMessenger $messenger) {}

    /** @return array{eligible: bool, reason: string|null} */
    public function eligibility(Challenge $c, Student $s): array
    {
        if ($s->status?->value !== StudentStatus::Active->value) {
            return ['eligible' => false, 'reason' => 'inactive'];
        }
        if ($s->gender?->value !== $c->gender) {
            return ['eligible' => false, 'reason' => 'gender'];
        }
        $age = $s->birth_date ? $s->birth_date->diffInYears($c->starts_at ?? now()) : null;
        if (($c->min_age !== null && ($age === null || $age < $c->min_age)) || ($c->max_age !== null && ($age === null || $age > $c->max_age))) {
            return ['eligible' => false, 'reason' => 'age'];
        }
        $active = LessonStudent::where('student_id', $s->id)->where('status', LessonStudentStatus::Active->value);
        $inScope = match ($c->scope) {
            'circle' => (clone $active)->where('lesson_id', $c->scope_lesson_id)->exists(),
            'package' => (clone $active)->whereIn('lesson_id', Lesson::where('package_id', $c->scope_package_id)->select('id'))->exists(),
            default => (clone $active)->exists(),
        };

        return $inScope ? ['eligible' => true, 'reason' => null] : ['eligible' => false, 'reason' => 'scope'];
    }

    public function join(Challenge $c, Student $s, ?User $by = null): ChallengeParticipant
    {
        if ($c->status !== 'active' || today()->gt($c->ends_at)) {
            throw ValidationException::withMessages(['challenge' => __('engagement.errors.challenge_closed')]);
        }
        $e = $this->eligibility($c, $s);
        if (! $e['eligible']) {
            throw ValidationException::withMessages(['student_id' => __('engagement.errors.not_eligible.'.$e['reason'])]);
        }
        $p = ChallengeParticipant::firstOrCreate(['challenge_id' => $c->id, 'student_id' => $s->id], ['joined_at' => now(), 'joined_by' => $by?->id, 'status' => 'joined']);
        $this->refresh($p);

        return $p->fresh();
    }

    /** Current value toward the goal and the goal size for one student. @return array{value:int, target:int} */
    public function measure(Challenge $c, Student $s): array
    {
        $from = $c->starts_at->toDateString();
        $to = min($c->ends_at->toDateString(), today()->toDateString());

        switch ($c->goal_type) {
            case 'memorize_range':
            case 'revision_range':
                $type = $c->goal_type === 'memorize_range' ? ProgressType::Memorized : ProgressType::Revised;
                $want = [];
                $from_a = (int) ($c->from_ayah ?: 1);
                $to_a = (int) ($c->to_ayah ?: Quran::ayahCount((int) $c->surah_number));
                for ($a = $from_a; $a <= $to_a; $a++) {
                    $want[$a] = true;
                }
                $have = [];
                StudentProgress::where('student_id', $s->id)->where('type', $type->value)->where('surah_number', $c->surah_number)
                    ->whereBetween('recorded_on', [$from, $to])->get(['from_ayah', 'to_ayah'])
                    ->each(function ($r) use (&$have, $want) {
                        for ($a = $r->from_ayah; $a <= $r->to_ayah; $a++) {
                            if (isset($want[$a])) {
                                $have[$a] = true;
                            }
                        }
                    });

                return ['value' => count($have), 'target' => max(1, count($want))];

            case 'attendance_days':
                $n = Attendance::where('student_id', $s->id)->whereIn('status', ['present', 'late'])
                    ->whereHas('session', fn ($q) => $q->whereBetween('session_date', [$from, $to]))->count();

                return ['value' => $n, 'target' => max(1, $c->goal_value)];

            case 'score_streak':
                $criterion = $c->score_criterion ?: 'tajweed';
                $min = (int) ($c->min_score ?? 8);
                $best = 0;
                $run = 0;
                Evaluation::quran()->where('student_id', $s->id)->where('type', EvaluationType::Daily->value)
                    ->whereBetween('evaluated_on', [$from, $to])->orderBy('evaluated_on')->orderBy('id')->get()
                    ->each(function ($e) use (&$best, &$run, $criterion, $min) {
                        $score = $criterion === 'total' ? ($e->memorization + $e->tajweed + $e->revision + $e->behavior) / 4 : $e->{$criterion};
                        $run = $score >= $min ? $run + 1 : 0;
                        $best = max($best, $run);
                    });

                return ['value' => $best, 'target' => max(1, $c->goal_value)];

            case 'points':
                $pts = (int) (HonorRanking::where('student_id', $s->id)->whereHas('period', fn ($q) => $q->where('period', $c->ends_at->format('Y-m')))->value('points_x100') ?? 0);

                return ['value' => intdiv($pts, 100), 'target' => max(1, $c->goal_value)];
        }

        return ['value' => 0, 'target' => 1];
    }

    /** Recompute one participant; completes and rewards exactly once. */
    public function refresh(ChallengeParticipant $p): ChallengeParticipant
    {
        $c = $p->challenge;
        if ($p->status === 'completed') {
            return $p;
        }
        $m = $this->measure($c, $p->student);
        $pct = (int) min(100, floor($m['value'] * 100 / $m['target']));
        $status = $pct >= 100 ? 'completed' : (today()->gt($c->ends_at) ? 'failed' : 'joined');
        $p->update(['progress_value' => $m['value'], 'progress_pct' => $pct, 'status' => $status, 'completed_at' => $status === 'completed' ? now() : null, 'progress_computed_at' => now()]);

        if ($status === 'completed') {
            $this->reward($p);
        }

        return $p;
    }

    public function reward(ChallengeParticipant $p): bool
    {
        return DB::transaction(function () use ($p) {
            $locked = ChallengeParticipant::whereKey($p->id)->lockForUpdate()->first();
            if ($locked->rewarded_at || $locked->status !== 'completed') {
                return false;
            }
            $c = $p->challenge;
            if ($c->rewardBadge) {
                $this->honor->grant($p->student_id, $c->rewardBadge, 'ch'.$c->id, Challenge::class, $c->id);
            }
            $this->honor->addPoints($p->student_id, (int) $c->reward_points, Challenge::class, $c->id, $c->name_ar);
            $locked->update(['rewarded_at' => now()]);
            $student = $p->student;
            if ($student) {
                $this->messenger->notify($student, MessageType::ChallengeCompleted, ['challenge' => $c->name($student->locale?->value ?? 'ar')]);
            }

            return true;
        });
    }

    /** Refresh every open participation of a student (called after attendance, evaluation and ledger saves). */
    public function refreshStudent(int $studentId): void
    {
        ChallengeParticipant::with(['challenge', 'student'])->where('student_id', $studentId)->where('status', 'joined')
            ->whereHas('challenge', fn ($q) => $q->where('status', 'active'))->get()->each(fn ($p) => $this->refresh($p));
    }

    /** Nightly: refresh all, and send nudges at 50% and 3 days before the end (each once). @return int nudges sent */
    public function nightly(): int
    {
        $sent = 0;
        ChallengeParticipant::with(['challenge', 'student'])->where('status', 'joined')
            ->whereHas('challenge', fn ($q) => $q->where('status', 'active'))->get()
            ->each(function (ChallengeParticipant $p) use (&$sent) {
                $this->refresh($p);
                $p->refresh();
                if ($p->status !== 'joined' || ! $p->student) {
                    return;
                }
                $c = $p->challenge;
                $locale = $p->student->locale?->value ?? 'ar';
                $daysLeft = max(0, (int) today()->diffInDays($c->ends_at, false));
                $vars = ['challenge' => $c->name($locale), 'progress' => $p->progress_pct.'%', 'days_left' => (string) $daysLeft];
                if ($p->progress_pct >= 50 && ! $p->nudged_half_at) {
                    $sent += $this->messenger->notify($p->student, MessageType::ChallengeNudge, $vars);
                    $p->update(['nudged_half_at' => now()]);
                }
                if ($daysLeft <= 3 && ! $p->nudged_deadline_at) {
                    $sent += $this->messenger->notify($p->student, MessageType::ChallengeDeadline, $vars);
                    $p->update(['nudged_deadline_at' => now()]);
                }
            });

        return $sent;
    }
}
