<?php

namespace App\Services\Evaluation;

use App\Enums\EvaluationType;
use App\Enums\IssueCategory;
use App\Enums\LessonStudentStatus;
use App\Enums\MessageType;
use App\Enums\RecipientType;
use App\Models\Division;
use App\Models\Evaluation;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationScore;
use App\Models\LevelSubject;
use App\Models\Subject;
use App\Models\Lesson;
use App\Models\LessonSession;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\StudentIssue;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Messaging\MessageService;
use App\Services\Progress\ProgressService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EvaluationService
{
    public const CRITERIA = ['memorization', 'tajweed', 'revision', 'behavior'];

    public function __construct(
        private ProgressService $progress,
        private MessageService $messages,
        private AuditLogger $audit,
    ) {}

    /**
     * Upsert daily evaluations for a session; optional ledger entries are appended per student.
     *
     * @param  list<array{student_id:int, memorization:int, tajweed:int, revision:int, behavior:int, note?:string|null, progress?:list<array>}>  $entries
     * @return array{evaluations: Collection<int,Evaluation>, suggestions: list<array>}
     */
    public function saveDaily(LessonSession $session, array $entries, User $by, ?int $subjectId = null, ?Division $division = null): array
    {
        $subjectId ??= Subject::quranId();
        $studentIds = array_column($entries, 'student_id');
        $this->assertEnrolled($session->lesson_id, $studentIds);
        $this->assertInDivision($division, $studentIds);
        $criteria = $this->criteria($subjectId);
        $quran = $this->isQuranSubject($subjectId);
        $this->assertScores($entries, $criteria, $quran);
        $date = $session->session_date->toDateString();

        $saved = DB::transaction(function () use ($session, $entries, $by, $date, $subjectId, $division, $criteria, $quran) {
            $out = new Collection;
            foreach ($entries as $e) {
                $evaluation = Evaluation::updateOrCreate(
                    ['student_id' => $e['student_id'], 'lesson_session_id' => $session->id, 'type' => EvaluationType::Daily->value, 'subject_id' => $subjectId],
                    ['lesson_id' => $session->lesson_id, 'evaluated_on' => $date, 'evaluated_by' => $by->id] + $this->scores($e, $quran)
                        + ($division ? ['division_id' => $division->id] : [])
                );
                $this->writeScores($evaluation, $e, $criteria, $quran);
                $this->auditChange($evaluation);
                if ($quran) {
                    $this->appendProgress($evaluation->student, $e['progress'] ?? [], $session->lesson_id, $date, $by->id);
                }
                $out->push($evaluation);
            }

            return $out;
        });

        return ['evaluations' => $saved, 'suggestions' => $this->suggestions($saved)];
    }

    /**
     * Upsert the monthly evaluation (one per student, circle, subject and YYYY-MM).
     *
     * @return array{evaluations: Collection<int,Evaluation>, suggestions: list<array>}
     */
    public function saveMonthly(Lesson $lesson, string $period, array $entries, User $by, ?int $subjectId = null): array
    {
        $subjectId ??= Subject::quranId();
        $this->assertEnrolled($lesson->id, array_column($entries, 'student_id'));
        $criteria = $this->criteria($subjectId);
        $quran = $this->isQuranSubject($subjectId);
        $this->assertScores($entries, $criteria, $quran);
        $monthEnd = Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth();
        $evaluatedOn = $monthEnd->isFuture() ? today() : $monthEnd;

        $saved = DB::transaction(function () use ($lesson, $period, $entries, $by, $evaluatedOn, $subjectId, $criteria, $quran) {
            $out = new Collection;
            foreach ($entries as $e) {
                $evaluation = Evaluation::updateOrCreate(
                    ['student_id' => $e['student_id'], 'lesson_id' => $lesson->id, 'type' => EvaluationType::Monthly->value, 'period' => $period, 'subject_id' => $subjectId],
                    ['evaluated_on' => $evaluatedOn->toDateString(), 'evaluated_by' => $by->id] + $this->scores($e, $quran)
                );
                $this->writeScores($evaluation, $e, $criteria, $quran);
                $this->auditChange($evaluation);
                $out->push($evaluation);
            }

            return $out;
        });

        return ['evaluations' => $saved, 'suggestions' => $this->suggestions($saved)];
    }

    public function update(Evaluation $evaluation, array $data): Evaluation
    {
        $quran = $evaluation->isQuran();
        $criteria = $this->criteria($evaluation->subject_id ?? Subject::quranId());
        $entry = $data + ['scores' => []];
        $this->assertScores([$entry], $criteria, $quran, partial: true);

        DB::transaction(function () use ($evaluation, $data, $entry, $criteria, $quran) {
            $evaluation->update(array_intersect_key($data, array_flip($quran ? [...self::CRITERIA, 'note'] : ['note'])));
            $this->writeScores($evaluation, $entry + $evaluation->only(self::CRITERIA), $criteria, $quran);
        });
        $this->auditChange($evaluation);

        return $evaluation;
    }

    /** Active criteria of a subject, in order (U5 التقييمات). */
    public function criteria(?int $subjectId): Collection
    {
        return EvaluationCriterion::where('subject_id', $subjectId)->where('is_active', true)->ordered()->get();
    }

    public function isQuranSubject(?int $subjectId): bool
    {
        return $subjectId === null || $subjectId === Subject::quranId();
    }

    /** Subjects a class can be evaluated in: Quran, plus the subjects its level studies in the class's term. */
    public function subjectsFor(Lesson $lesson): Collection
    {
        $termId = $lesson->package()->value('academic_term_id');
        $ids = collect([Subject::quranId()]);
        if ($termId && $lesson->level_id) {
            $ids = $ids->merge(LevelSubject::where('academic_term_id', $termId)->where('level_id', $lesson->level_id)->pluck('subject_id'));
        }

        return Subject::whereIn('id', $ids->filter()->unique()->all())->ordered()->get();
    }

    /** The subjects this user may evaluate in the class (managers all of them; teachers the ones they teach there). */
    public function evaluableSubjects(User $user, Lesson $lesson): Collection
    {
        return $this->subjectsFor($lesson)->filter(fn (Subject $s) => $user->can('record', [Evaluation::class, $lesson, $s->id]))->values();
    }

    /** @return list<array{id:int, key:?string, name:string, max_score:int, weight:int, is_system:bool}> */
    public function criteriaRows(Collection $criteria): array
    {
        return $criteria->map(fn (EvaluationCriterion $c) => [
            'id' => $c->id, 'key' => $c->key, 'name' => $c->name(), 'max_score' => $c->max_score, 'weight' => $c->weight, 'is_system' => $c->is_system,
        ])->values()->all();
    }

    /**
     * Scores per criterion come as entries.*.scores = {criterion_id: score}. They must be active criteria of the
     * subject and within its max. Quran's four system criteria come as the four columns (as before); every other
     * subject must score each of its active criteria (unless partial, for an edit).
     */
    private function assertScores(array $entries, Collection $criteria, bool $quran, bool $partial = false): void
    {
        $byId = $criteria->keyBy('id');
        $errors = [];
        foreach ($entries as $i => $e) {
            $scores = (array) ($e['scores'] ?? []);
            foreach ($scores as $cid => $score) {
                $c = $byId->get((int) $cid);
                if (! $c || ($quran && $c->is_system)) {
                    if (! $c) {
                        $errors["entries.$i.scores.$cid"] = __('evaluations.errors.criterion');
                    }

                    continue;
                }
                if (! is_numeric($score) || (int) $score < 0 || (int) $score > $c->max_score) {
                    $errors["entries.$i.scores.$cid"] = __('evaluations.errors.score_max', ['criterion' => $c->name(), 'max' => $c->max_score]);
                }
            }
            if (! $quran && ! $partial) {
                if ($criteria->isEmpty()) {
                    $errors['entries'] = __('evaluations.errors.no_criteria');
                }
                foreach ($criteria as $c) {
                    if (! array_key_exists($c->id, $scores) && ! array_key_exists((string) $c->id, $scores)) {
                        $errors["entries.$i.scores.{$c->id}"] = __('evaluations.errors.score_required', ['criterion' => $c->name()]);
                    }
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Dual write (U5): Quran's four columns are mirrored into their system criteria; other criteria only live here. */
    private function writeScores(Evaluation $evaluation, array $e, Collection $criteria, bool $quran): void
    {
        $scores = (array) ($e['scores'] ?? []);
        foreach ($criteria as $c) {
            $value = $quran && $c->is_system && $c->key
                ? ($e[$c->key] ?? null)
                : ($scores[$c->id] ?? $scores[(string) $c->id] ?? null);
            if ($value === null) {
                continue;
            }
            EvaluationScore::updateOrCreate(['evaluation_id' => $evaluation->id, 'criterion_id' => $c->id], ['score' => (int) $value]);
        }
        $evaluation->unsetRelation('scores');
    }

    /** @param  list<int>  $studentIds */
    private function assertInDivision(?Division $division, array $studentIds): void
    {
        if (! $division) {
            return;
        }
        $in = DB::table('division_students')->where('division_id', $division->id)->whereIn('student_id', $studentIds)->pluck('student_id')->all();
        if (array_diff($studentIds, $in)) {
            throw ValidationException::withMessages(['entries' => __('evaluations.errors.not_in_division')]);
        }
    }

    /**
     * For every criterion below the threshold, suggest a difficulty in the matching category
     * unless the student already has an unresolved one in that category.
     *
     * @param  iterable<Evaluation>  $evaluations
     */
    public function suggestions(iterable $evaluations, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $threshold = (int) setting('evaluation.issue_threshold', 6);
        $out = [];

        foreach ($evaluations as $ev) {
            if (! $ev->isQuran()) {
                continue; // difficulties follow the Quran criteria only
            }
            $openCategories = StudentIssue::where('student_id', $ev->student_id)->unresolved()->pluck('category')
                ->map(fn ($c) => $c instanceof IssueCategory ? $c->value : $c)->all();

            foreach (self::CRITERIA as $criterion) {
                $score = (int) $ev->{$criterion};
                if ($score >= $threshold) {
                    continue;
                }
                $category = IssueCategory::forCriterion($criterion);
                if (in_array($category->value, $openCategories, true)) {
                    continue;
                }
                $out[] = [
                    'student_id' => $ev->student_id,
                    'evaluation_id' => $ev->id,
                    'lesson_id' => $ev->lesson_id,
                    'criterion' => $criterion,
                    'score' => $score,
                    'category' => $category->value,
                    'category_label' => $category->label($locale),
                    'message' => __('evaluations.suggestion', [
                        'criterion' => __("evaluations.criteria.{$criterion}", [], $locale),
                        'score' => $score,
                        'category' => $category->label($locale),
                    ], $locale),
                ];
            }
        }

        return $out;
    }

    /** Send the evaluation to the guardian (and the student's own phone when different). */
    public function sendToGuardian(Evaluation $evaluation): int
    {
        $student = $evaluation->student;
        $locale = $student->locale?->value ?? 'ar';
        $vars = ['date' => $evaluation->evaluated_on->toDateString(), 'score' => $this->scoreText($evaluation, $locale)];

        $sent = 0;
        foreach (array_unique(array_filter([$student->guardian_phone, $student->student_phone])) as $phone) {
            $this->messages->send($phone, MessageType::EvaluationResult, $vars, $locale, $student, null,
                $phone === $student->guardian_phone ? RecipientType::Guardian : RecipientType::Student);
            $sent++;
        }
        $evaluation->update(['sent_to_guardian_at' => now()]);

        return $sent;
    }

    public function scoreText(Evaluation $e, string $locale): string
    {
        if (! $e->isQuran()) {
            $names = EvaluationCriterion::whereIn('id', $e->scores()->pluck('criterion_id'))->ordered()->get()->keyBy('id');

            return $e->scores()->get()->sortBy(fn ($s) => $names->get($s->criterion_id)?->sort)
                ->map(fn ($s) => ($names->get($s->criterion_id)?->name($locale) ?? '').': '.$s->score.'/'.($names->get($s->criterion_id)?->max_score ?? 10))
                ->implode('، ');
        }

        return __('evaluations.score_text', ['m' => $e->memorization, 't' => $e->tajweed, 'r' => $e->revision, 'b' => $e->behavior, 'total' => $e->total()], $locale);
    }

    /** Evaluation block of the student profile. */
    public function summary(Student $student, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $daily = $student->evaluations()->quran()->where('type', EvaluationType::Daily->value)
            ->where('evaluated_on', '>=', today()->subMonths(6)->startOfMonth()->toDateString())
            ->with('evaluator:id,name')->orderByDesc('evaluated_on')->orderByDesc('id')->get();
        $monthly = $student->evaluations()->quran()->where('type', EvaluationType::Monthly->value)
            ->orderByDesc('period')->limit(6)->get()->keyBy('period');

        // Monthly averages of daily scores, grouped in PHP (portable across drivers).
        $averages = $daily->groupBy(fn ($e) => $e->evaluated_on->format('Y-m'))
            ->map(fn (Collection $g, string $period) => ['period' => $period, 'count' => $g->count()] + $this->averages($g) + [
                'monthly_evaluation' => ($m = $monthly->get($period)) ? $this->scoreRow($m) : null,
            ])->sortKeysDesc()->values()->all();

        // Weekly trend for the last 8 weeks (weeks start on Saturday).
        $weekStart = today()->startOfWeek(Carbon::SATURDAY)->subWeeks(7);
        $trend = [];
        for ($i = 0; $i < 8; $i++) {
            $from = $weekStart->copy()->addWeeks($i);
            $to = $from->copy()->addDays(6);
            $week = $daily->filter(fn ($e) => $e->evaluated_on->betweenIncluded($from, $to));
            $trend[] = ['week_start' => $from->toDateString(), 'count' => $week->count()] + ($week->isEmpty()
                ? array_fill_keys([...self::CRITERIA, 'total'], null)
                : $this->averages($week));
        }

        $latestNote = $student->evaluations()->whereNotNull('note')->where('note', '!=', '')
            ->with('evaluator:id,name')->orderByDesc('evaluated_on')->orderByDesc('id')->first();

        return [
            'latest_daily' => $daily->take(5)->map(fn ($e) => $this->scoreRow($e))->values()->all(),
            'monthly_averages' => $averages,
            'trend' => $trend,
            'rank' => $this->rankInCircle($student),
            'latest_note' => $latestNote ? [
                'note' => $latestNote->note,
                'date' => $latestNote->evaluated_on->toDateString(),
                'teacher' => $latestNote->evaluator?->name,
            ] : null,
        ];
    }

    /**
     * Dense rank of the student's average daily total (last 30 days) among active students in their circle.
     *
     * @return array{rank:int|null, of:int, average:float|null, lesson_id:int|null}|null
     */
    public function rankInCircle(Student $student): ?array
    {
        $lessonId = LessonStudent::where('student_id', $student->id)->where('status', LessonStudentStatus::Active->value)
            ->orderBy('joined_at')->value('lesson_id');
        if (! $lessonId) {
            return null;
        }

        $studentIds = LessonStudent::where('lesson_id', $lessonId)->where('status', LessonStudentStatus::Active->value)->pluck('student_id');
        $averages = Evaluation::quran()->where('lesson_id', $lessonId)->where('type', EvaluationType::Daily->value)
            ->whereIn('student_id', $studentIds)
            ->where('evaluated_on', '>=', today()->subDays(30)->toDateString())
            ->get(['student_id', ...self::CRITERIA])
            ->groupBy('student_id')
            ->map(fn (Collection $g) => round($g->avg(fn ($e) => $e->total()), 1));

        $mine = $averages->get($student->id);
        $distinct = $averages->values()->unique()->sortDesc()->values();

        return [
            'lesson_id' => $lessonId,
            'rank' => $mine === null ? null : $distinct->search($mine) + 1,
            'of' => $studentIds->count(),
            'average' => $mine,
        ];
    }

    /** @return array{memorization:float, tajweed:float, revision:float, behavior:float, total:float} */
    public function averages(Collection $evaluations): array
    {
        $out = [];
        foreach (self::CRITERIA as $c) {
            $out[$c] = round((float) $evaluations->avg($c), 1);
        }
        $out['total'] = round((float) $evaluations->avg(fn ($e) => $e->total()), 1);

        return $out;
    }

    public function scoreRow(Evaluation $e): array
    {
        return [
            'id' => $e->id,
            'student_id' => $e->student_id,
            'sent_to_guardian_at' => display_tz($e->sent_to_guardian_at)?->toIso8601String(),
            'type' => $e->type->value,
            'subject_id' => $e->subject_id,
            'division_id' => $e->division_id,
            'date' => $e->evaluated_on?->toDateString(),
            'period' => $e->period,
            'memorization' => $e->memorization,
            'tajweed' => $e->tajweed,
            'revision' => $e->revision,
            'behavior' => $e->behavior,
            'total' => $e->total(),
            'note' => $e->note,
            'teacher' => $e->relationLoaded('evaluator') ? $e->evaluator?->name : null,
            // U5: one score per criterion id (for Quran the four system criteria hold the column values).
            'scores' => (object) ($e->relationLoaded('scores') ? $e->scores : $e->scores()->get())->pluck('score', 'criterion_id')->all(),
        ];
    }

    /** The four columns: Quran's scores; empty for other subjects (their scores are in evaluation_scores only). */
    private function scores(array $e, bool $quran = true): array
    {
        return $quran ? [
            'memorization' => (int) $e['memorization'],
            'tajweed' => (int) $e['tajweed'],
            'revision' => (int) $e['revision'],
            'behavior' => (int) $e['behavior'],
            'note' => $e['note'] ?? null,
        ] : array_fill_keys(self::CRITERIA, null) + ['note' => $e['note'] ?? null];
    }

    private function appendProgress(Student $student, array $entries, int $lessonId, string $date, int $by): void
    {
        foreach ($entries as $p) {
            $this->progress->append($student, $p + ['lesson_id' => $lessonId, 'recorded_on' => $date], $by);
        }
    }

    /** Grades are audited: record the change whenever scores differ from the stored values. */
    private function auditChange(Evaluation $evaluation): void
    {
        $changes = $evaluation->wasRecentlyCreated ? $evaluation->only([...self::CRITERIA, 'note']) : $evaluation->getChanges();
        $changes = array_intersect_key($changes, array_flip([...self::CRITERIA, 'note']));
        if (! $changes) {
            return;
        }
        $old = $evaluation->wasRecentlyCreated ? [] : array_intersect_key($evaluation->getPrevious(), $changes);
        $this->audit->record($evaluation->wasRecentlyCreated ? 'evaluation.created' : 'evaluation.updated', $evaluation, $old, $changes);
    }

    /** @param  list<int>  $studentIds */
    private function assertEnrolled(int $lessonId, array $studentIds): void
    {
        $enrolled = LessonStudent::where('lesson_id', $lessonId)->where('status', LessonStudentStatus::Active->value)
            ->whereIn('student_id', $studentIds)->pluck('student_id')->all();
        $missing = array_diff($studentIds, $enrolled);
        if ($missing) {
            throw ValidationException::withMessages(['entries' => __('evaluations.student_not_in_circle')]);
        }
    }
}
