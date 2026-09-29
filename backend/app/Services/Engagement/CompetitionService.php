<?php

namespace App\Services\Engagement;

use App\Enums\CertificateSource;
use App\Enums\CertificateType;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Enums\StudentStatus;
use App\Models\Competition;
use App\Models\CompetitionJudge;
use App\Models\CompetitionParticipant;
use App\Models\CompetitionRound;
use App\Models\CompetitionScore;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;
use Ahl\Certificates\CertificateService;
use App\Services\Lessons\StudentMessenger;
use App\Support\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Competitions (section 14): one gender track each; eligibility by age, scope and gender enforced here;
 * judges from the same track; weighted criteria averaged across judges; results hidden until published;
 * prizes (certificate drafts, badges, honor points) written exactly once per participant.
 */
class CompetitionService
{
    /** Default recitation criteria (editable per competition / per round). Weights sum to 100. */
    public const DEFAULT_CRITERIA = [
        ['key' => 'accuracy', 'name_ar' => 'دقة الحفظ', 'name_en' => 'Memorization accuracy', 'weight' => 40, 'max' => 10],
        ['key' => 'tajweed', 'name_ar' => 'أحكام التجويد', 'name_en' => 'Tajweed', 'weight' => 30, 'max' => 10],
        ['key' => 'voice', 'name_ar' => 'الصوت والأداء', 'name_en' => 'Voice and pace', 'weight' => 20, 'max' => 10],
        ['key' => 'rules', 'name_ar' => 'الالتزام بالضوابط', 'name_en' => 'Adherence to rules', 'weight' => 10, 'max' => 10],
    ];

    public function __construct(
        private CertificateService $certificates,
        private HonorService $honor,
        private StudentMessenger $messenger,
    ) {}

    /** @return array{eligible: bool, reason: string|null} */
    public function eligibility(Competition $c, Student $s): array
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

    public function register(Competition $c, Student $s, ?User $by = null, bool $ignoreWindow = false): CompetitionParticipant
    {
        if (! $ignoreWindow && ($c->status !== 'open' || now()->lt($c->registration_opens_at) || now()->gt($c->registration_closes_at))) {
            throw ValidationException::withMessages(['competition' => __('engagement.errors.registration_closed')]);
        }
        $e = $this->eligibility($c, $s);
        if (! $e['eligible']) {
            throw ValidationException::withMessages(['student_id' => __('engagement.errors.not_eligible.'.$e['reason'])]);
        }

        return DB::transaction(function () use ($c, $s, $by) {
            $existing = CompetitionParticipant::where('competition_id', $c->id)->where('student_id', $s->id)->lockForUpdate()->first();
            if ($existing && $existing->status !== 'withdrawn') {
                return $existing;
            }
            $count = CompetitionParticipant::where('competition_id', $c->id)->where('status', '!=', 'withdrawn')->count();
            if ($c->max_participants && $count >= $c->max_participants) {
                throw ValidationException::withMessages(['competition' => __('engagement.errors.full')]);
            }

            return CompetitionParticipant::updateOrCreate(
                ['competition_id' => $c->id, 'student_id' => $s->id],
                ['registered_at' => now(), 'registered_by' => $by?->id, 'status' => 'registered']
            );
        });
    }

    public function withdraw(CompetitionParticipant $p): void
    {
        $p->update(['status' => 'withdrawn']);
    }

    /** Judges must be staff of the competition track (female judges for girls, male for boys). */
    public function assertJudgeAllowed(Competition $c, User $judge): void
    {
        if (Track::staffGender($judge)?->value !== $c->gender || ! $judge->hasAnyRole(config('ahl.staff_roles'))) {
            throw ValidationException::withMessages(['user_id' => __('engagement.errors.judge_track')]);
        }
    }

    public function addJudge(Competition $c, User $judge, ?int $roundId = null): CompetitionJudge
    {
        $this->assertJudgeAllowed($c, $judge);

        return CompetitionJudge::firstOrCreate(['competition_id' => $c->id, 'round_id' => $roundId, 'user_id' => $judge->id]);
    }

    public function isJudge(Competition $c, User $user, ?CompetitionRound $round = null): bool
    {
        return CompetitionJudge::where('competition_id', $c->id)->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('round_id')->when($round, fn ($w) => $w->orWhere('round_id', $round->id)))->exists();
    }

    /**
     * Save one judge's criteria scores for a participant in a round. Total = Σ (score / max × weight), ×100.
     *
     * @param  array<string,int>  $scores
     */
    public function score(CompetitionRound $round, CompetitionParticipant $p, User $judge, array $scores, ?string $note = null): CompetitionScore
    {
        $c = Competition::findOrFail($round->competition_id); // fresh: publication may have happened since the round was loaded
        if (! $this->isJudge($c, $judge, $round)) {
            throw ValidationException::withMessages(['judge' => __('engagement.errors.not_judge')]);
        }
        if ($p->competition_id !== $c->id || $p->status === 'withdrawn') {
            throw ValidationException::withMessages(['participant_id' => __('engagement.errors.not_participant')]);
        }
        if ($c->results_published_at) {
            throw ValidationException::withMessages(['competition' => __('engagement.errors.published')]);
        }
        $criteria = $round->effectiveCriteria();
        $total = 0.0;
        $clean = [];
        foreach ($criteria as $cr) {
            $v = $scores[$cr['key']] ?? null;
            $max = (int) ($cr['max'] ?? 10);
            if ($v === null || ! is_numeric($v) || $v < 0 || $v > $max) {
                throw ValidationException::withMessages(["scores.{$cr['key']}" => __('engagement.errors.score_range', ['max' => $max])]);
            }
            $clean[$cr['key']] = (int) $v;
            $total += ((int) $v / max(1, $max)) * (float) ($cr['weight'] ?? 0);
        }

        return CompetitionScore::updateOrCreate(
            ['participant_id' => $p->id, 'round_id' => $round->id, 'judge_id' => $judge->id],
            ['criteria_scores' => $clean, 'total_x100' => (int) round($total * 100), 'note' => $note]
        );
    }

    /**
     * Standings: per round the average of the judges' totals; final score = average of the round averages.
     * Ties broken by the competition rule. Rank 1 is best; equal after tie-break share the rank.
     */
    public function standings(Competition $c): Collection
    {
        $c->loadMissing(['rounds', 'participants.student', 'participants.scores']);
        $rounds = $c->rounds->values();
        $lastRoundId = $rounds->last()?->id;
        $criteriaKeys = collect($c->criteria ?? self::DEFAULT_CRITERIA)->pluck('key')->all();

        $rows = $c->participants->where('status', '!=', 'withdrawn')->map(function (CompetitionParticipant $p) use ($rounds, $lastRoundId, $criteriaKeys) {
            $perRound = [];
            foreach ($rounds as $r) {
                $s = $p->scores->where('round_id', $r->id);
                if ($s->isNotEmpty()) {
                    $perRound[$r->id] = (int) round($s->avg('total_x100'));
                }
            }
            $critAvg = [];
            foreach ($criteriaKeys as $k) {
                $vals = $p->scores->map(fn ($s) => $s->criteria_scores[$k] ?? null)->filter(fn ($v) => $v !== null);
                $critAvg[$k] = $vals->isEmpty() ? 0 : $vals->avg();
            }

            return [
                'participant' => $p,
                'rounds' => $perRound,
                'final_x100' => $perRound ? (int) round(array_sum($perRound) / count($perRound)) : null,
                'last_x100' => $lastRoundId ? ($perRound[$lastRoundId] ?? null) : null,
                'criteria' => $critAvg,
                'age_days' => $p->student?->birth_date ? $p->student->birth_date->diffInDays(now()) : PHP_INT_MAX,
                'registered' => $p->registered_at?->getTimestamp() ?? PHP_INT_MAX,
            ];
        })->values();

        $cmp = function ($a, $b) use ($c, $criteriaKeys) {
            if (($a['final_x100'] ?? -1) !== ($b['final_x100'] ?? -1)) {
                return ($b['final_x100'] ?? -1) <=> ($a['final_x100'] ?? -1);
            }

            return match ($c->tie_break) {
                'criterion_order' => (function () use ($a, $b, $criteriaKeys) {
                    foreach ($criteriaKeys as $k) {
                        if ($a['criteria'][$k] != $b['criteria'][$k]) {
                            return $b['criteria'][$k] <=> $a['criteria'][$k];
                        }
                    }

                    return 0;
                })(),
                'age_younger' => $a['age_days'] <=> $b['age_days'],
                'registration_order' => $a['registered'] <=> $b['registered'],
                default => ($b['last_x100'] ?? -1) <=> ($a['last_x100'] ?? -1),
            };
        };

        $sorted = $rows->sort($cmp)->values();
        $out = [];
        foreach ($sorted as $i => $r) {
            $prev = $i > 0 ? $sorted[$i - 1] : null;
            $r['rank'] = ($prev && $r['final_x100'] !== null && $cmp($prev, $r) === 0) ? $out[$i - 1]['rank'] : $i + 1;
            $out[] = $r;
        }

        return collect($out);
    }

    /**
     * Publish results: store final rank and score, mark winners, write prizes once, notify guardians.
     *
     * @return array{published:int, rewarded:int, notified:int}
     */
    public function publish(Competition $c, User $by, bool $notify = true): array
    {
        $standings = $this->standings($c);
        $prizes = $c->prizes()->with('badge')->get()->keyBy('rank');
        $rewarded = 0;
        $notified = 0;

        DB::transaction(function () use ($c, $by, $standings) {
            foreach ($standings as $row) {
                $p = $row['participant'];
                $p->update([
                    'final_rank' => $row['final_x100'] === null ? null : $row['rank'],
                    'final_score_x100' => $row['final_x100'],
                    'status' => $row['final_x100'] !== null && $row['rank'] <= max(1, $c->prizes()->count()) ? 'winner' : ($p->status === 'registered' ? 'finalist' : $p->status),
                ]);
            }
            $c->update(['status' => 'finished', 'results_published_at' => now(), 'results_published_by' => $by->id]);
        });

        foreach ($standings as $row) {
            $p = $row['participant']->fresh(['student']);
            $prize = $p->final_rank ? $prizes->get($p->final_rank) : null;
            if ($prize && ! $p->rewarded_at) {
                DB::transaction(function () use ($c, $p, $prize, $by) {
                    $locked = CompetitionParticipant::whereKey($p->id)->lockForUpdate()->first();
                    if ($locked->rewarded_at) {
                        return;
                    }
                    $locale = $p->student->locale?->value ?? 'ar';
                    if (! $this->certificates->exists($p->student, CertificateSource::Competition, $c->id)) {
                        $this->certificates->createDraft($p->student, CertificateType::Competition, [
                            'achievement' => $prize->title.' — '.$c->name($locale),
                            'source' => CertificateSource::Competition, 'source_id' => $c->id,
                        ], $by);
                    }
                    if ($prize->badge) {
                        $this->honor->grant($p->student_id, $prize->badge, 'c'.$c->id, Competition::class, $c->id, $by->id);
                    }
                    $this->honor->addPoints($p->student_id, (int) $prize->points, Competition::class, $c->id, $prize->title);
                    $locked->update(['rewarded_at' => now()]);
                });
                $rewarded++;
            }
            if ($notify && $p->student && ! $p->result_notified_at) {
                $locale = $p->student->locale?->value ?? 'ar';
                $place = $p->final_rank ? __('engagement.place', ['n' => $p->final_rank], $locale) : __('engagement.participated', [], $locale);
                $notified += $this->messenger->notify($p->student, MessageType::CompetitionResult, ['competition' => $c->name($locale), 'place' => $place]);
                $p->update(['result_notified_at' => now()]);
            }
        }

        return ['published' => $standings->count(), 'rewarded' => $rewarded, 'notified' => $notified];
    }
}
