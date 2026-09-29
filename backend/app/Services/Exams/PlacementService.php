<?php

namespace App\Services\Exams;

use App\Enums\AttemptStatus;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\RegistrationRequest;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Placement tests during public registration. They reuse the exams system end to end (question order,
 * option shuffling, answer saving, the time limit and the AutoGrader via ExamService); what differs is that
 * the candidate is not a student yet, so an attempt is anonymous and reached by a random token.
 */
class PlacementService
{
    public function __construct(private ExamService $exams) {}

    /**
     * Start a new attempt for the package's active placement test. Every start is a new attempt, numbered
     * per guardian phone, up to config('ahl.placement_max_attempts').
     *
     * @return array{0: ExamAttempt, 1: string} the attempt and the plain token (shown once)
     */
    public function start(Exam $exam, string $candidateName, string $candidatePhone, CarbonInterface $now): array
    {
        if (! $exam->isPlacement() || ! $exam->isOpenAt($now)) {
            throw ValidationException::withMessages(['exam' => __('exams.window_closed')]);
        }

        $previous = $exam->attempts()->where('candidate_phone', $candidatePhone)->count();
        if ($previous >= (int) config('ahl.placement_max_attempts', 3)) {
            throw ValidationException::withMessages(['exam' => __('exams.placement.too_many_attempts')]);
        }

        $token = Str::random(48);
        $attempt = ExamAttempt::create([
            'exam_id' => $exam->id,
            'student_id' => null,
            'access_token' => ExamAttempt::hashToken($token),
            'candidate_name' => $candidateName,
            'candidate_phone' => $candidatePhone,
            'attempt_no' => $previous + 1,
            'started_at' => $now,
            'expires_at' => min($now->copy()->addMinutes($exam->duration_minutes), $exam->closes_at),
            'status' => AttemptStatus::InProgress,
            'question_order' => $this->exams->buildOrder($exam),
        ]);

        return [$attempt, $token];
    }

    /** Resolve a token to its placement attempt, auto-submitting it (and scoring it) once the time is up. */
    public function resolve(string $token, CarbonInterface $now): ExamAttempt
    {
        $attempt = ExamAttempt::findByToken($token);
        if (! $attempt || ! $attempt->exam?->isPlacement()) {
            abort(404, __('exams.placement.not_found'));
        }

        $attempt = $this->exams->refreshIfExpired($attempt, $now);
        if ($attempt->status === AttemptStatus::Graded && ! $attempt->recommended_level) {
            $this->recommend($attempt);
        }

        return $attempt->fresh();
    }

    /**
     * Submit. Every question must be answered while time remains; an expired attempt is scored as it stands.
     * A second submit returns the same result (ExamService::submit ignores finished attempts).
     */
    public function submit(ExamAttempt $attempt, CarbonInterface $now): ExamAttempt
    {
        if ($attempt->status === AttemptStatus::InProgress && ! $attempt->isExpiredAt($now)) {
            $answered = $attempt->answers()->whereNotNull('answer')->pluck('exam_question_id')->map(fn ($id) => (int) $id)->all();
            $missing = array_diff(array_map('intval', $attempt->question_order ?? []), $answered);
            if ($missing) {
                throw ValidationException::withMessages(['answers' => __('exams.placement.incomplete', ['n' => count($missing)])]);
            }
        }

        $attempt = $this->exams->submit($attempt, $attempt->isExpiredAt($now) ? $attempt->expires_at : $now, $attempt->isExpiredAt($now));
        $this->recommend($attempt);

        return $attempt->fresh();
    }

    private function recommend(ExamAttempt $attempt): void
    {
        if ($attempt->status !== AttemptStatus::Graded || $attempt->total_score === null) {
            return;
        }
        $level = $attempt->exam->levelForPercent($this->exams->percent($attempt->total_score, $attempt->exam));
        $attempt->update(['recommended_level' => $level]);
    }

    /** The result as the family sees it: counts, percentage, level, and right/wrong per question (never the answer key). */
    public function result(ExamAttempt $attempt): array
    {
        $exam = $attempt->exam;
        $answers = $attempt->answers()->get()->keyBy('exam_question_id');
        $questions = ExamQuestion::whereIn('id', $attempt->question_order ?? [])->get()->keyBy('id');

        $rows = [];
        foreach (array_values($attempt->question_order ?? []) as $i => $id) {
            if (! $q = $questions->get($id)) {
                continue;
            }
            $a = $answers->get($id);
            $rows[] = ['position' => $i + 1, 'question_id' => (int) $id, 'prompt' => $q->prompt, 'type' => $q->type->value, 'answered' => $a?->answer !== null, 'is_correct' => (bool) $a?->is_correct];
        }

        $correct = count(array_filter($rows, fn ($r) => $r['is_correct']));
        $score = (int) ($attempt->total_score ?? 0);

        return [
            'exam_name' => $exam->name,
            'attempt_no' => $attempt->attempt_no,
            'status' => $attempt->status->value,
            'submitted_at' => display_tz($attempt->submitted_at)?->toIso8601String(),
            'total_questions' => count($rows),
            'correct' => $correct,
            'incorrect' => count($rows) - $correct,
            'score' => $score,
            'total_marks' => $exam->total_marks,
            'percent' => $this->exams->percent($score, $exam),
            'recommended_level' => $attempt->recommended_level?->value,
            'recommended_level_label' => $attempt->recommended_level?->label(),
            'questions' => $rows,
        ];
    }

    /** Short form for staff lists (registration requests): no per-question rows. */
    public function summary(?ExamAttempt $attempt): ?array
    {
        if (! $attempt) {
            return null;
        }
        // Built from the (eager-loadable) exam and answers relations, so a list of requests stays two queries.
        $total = count($attempt->question_order ?? []);
        $correct = $attempt->answers->where('is_correct', true)->count();
        $score = (int) ($attempt->total_score ?? 0);

        return [
            'attempt_id' => $attempt->id,
            'exam_name' => $attempt->exam->name,
            'attempt_no' => $attempt->attempt_no,
            'status' => $attempt->status->value,
            'submitted_at' => display_tz($attempt->submitted_at)?->toIso8601String(),
            'total_questions' => $total,
            'correct' => $correct,
            'incorrect' => $total - $correct,
            'score' => $score,
            'total_marks' => $attempt->exam->total_marks,
            'percent' => $this->exams->percent($score, $attempt->exam),
            'recommended_level' => $attempt->recommended_level?->value,
            'recommended_level_label' => $attempt->recommended_level?->label(),
        ];
    }

    /**
     * The attempt a registration must carry, checked on submit: a finished placement attempt for this
     * package that no other request has used yet. Returns null when the token is not usable.
     */
    public function usableAttempt(?string $token, int $packageId): ?ExamAttempt
    {
        if (! $token) {
            return null;
        }
        $attempt = ExamAttempt::findByToken($token);
        if (! $attempt || $attempt->registration_request_id || $attempt->status !== AttemptStatus::Graded) {
            return null;
        }

        return $attempt->exam?->isPlacement() && $attempt->exam->package_id === $packageId ? $attempt : null;
    }

    /** Link once: the conditional update loses a race between two submissions carrying the same token. */
    public function attachToRequest(ExamAttempt $attempt, RegistrationRequest $request): void
    {
        $linked = ExamAttempt::whereKey($attempt->id)->whereNull('registration_request_id')
            ->update(['registration_request_id' => $request->id, 'access_token' => null]);
        if (! $linked) {
            throw ValidationException::withMessages(['placement_token' => __('exams.placement.invalid_token')]);
        }
        $request->update(['placement_attempt_id' => $attempt->id, 'recommended_level' => $attempt->recommended_level]);
    }

    /**
     * Placement history on the student profile (staff): each registration that carried a test, with the
     * recommended and confirmed levels and every answer. Staff see the answer key here; families never do.
     */
    public function historyFor(Student $student): array
    {
        return RegistrationRequest::where('student_id', $student->id)->whereNotNull('placement_attempt_id')
            ->with(['placementAttempt.exam', 'placementAttempt.answers', 'levelConfirmer:id,name'])->orderByDesc('id')->get()
            ->map(function (RegistrationRequest $r) {
                $a = $r->placementAttempt;
                $questions = ExamQuestion::whereIn('id', $a->question_order ?? [])->get()->keyBy('id');
                $answers = $a->answers->keyBy('exam_question_id');

                return $this->summary($a) + [
                    'request_no' => $r->request_no,
                    'declared_level' => $r->memorization_level->value,
                    'declared_level_label' => $r->memorization_level->label(),
                    'final_level' => $r->final_level?->value,
                    'final_level_label' => $r->final_level?->label(),
                    'level_confirmed_by' => $r->levelConfirmer?->name,
                    'level_confirmed_at' => display_tz($r->level_confirmed_at)?->toIso8601String(),
                    'answers' => collect(array_values($a->question_order ?? []))->map(function ($id, $i) use ($questions, $answers) {
                        $q = $questions->get($id);
                        $ans = $answers->get($id);

                        return $q ? [
                            'position' => $i + 1, 'prompt' => $q->prompt, 'type' => $q->type->value, 'options' => $q->options,
                            'category' => $q->category, 'difficulty' => $q->difficulty, 'marks' => $q->marks,
                            'answer' => $ans?->answer, 'correct_answer' => $q->correct_answer, 'is_correct' => (bool) $ans?->is_correct, 'score' => (int) ($ans?->score ?? 0),
                        ] : null;
                    })->filter()->values()->all(),
                ];
            })->values()->all();
    }

    /** On acceptance the attempt joins the new student's exam history. */
    public function attachToStudent(RegistrationRequest $request, Student $student): void
    {
        if ($request->placement_attempt_id) {
            ExamAttempt::whereKey($request->placement_attempt_id)->update(['student_id' => $student->id]);
        }
    }
}
