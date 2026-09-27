<?php

namespace App\Services\Exams;

use App\Enums\AttemptStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamType;
use App\Enums\QuestionType;
use App\Models\Exam;
use App\Models\ExamAnswer;
use App\Models\ExamAttempt;
use App\Models\ExamQuestion;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExamService
{
    public function __construct(private AutoGrader $grader, private AuditLogger $audit) {}

    // ------------------------------------------------------------------ eligibility

    public function eligibleStudentsQuery(Exam $exam): Builder
    {
        return Student::query()->where('status', 'active')->whereHas('lessonStudents', function ($q) use ($exam) {
            $q->where('status', 'active');
            if ($exam->lesson_id) {
                $q->where('lesson_id', $exam->lesson_id);
            } else {
                $q->whereIn('lesson_id', Lesson::where('package_id', $exam->package_id)->select('id'));
            }
        });
    }

    /** @return Collection<int, Student> */
    public function eligibleStudents(Exam $exam): Collection
    {
        return $this->eligibleStudentsQuery($exam)->orderBy('full_name')->get();
    }

    public function isEligible(Exam $exam, Student $student): bool
    {
        return $this->eligibleStudentsQuery($exam)->whereKey($student->id)->exists();
    }

    public function teacherOwns(User $user, Exam $exam): bool
    {
        if ($exam->lesson_id) {
            return Lesson::whereKey($exam->lesson_id)->where('teacher_id', $user->id)->exists();
        }

        return $exam->package_id && Lesson::where('package_id', $exam->package_id)->where('teacher_id', $user->id)->exists();
    }

    // ------------------------------------------------------------------ lifecycle

    public function publish(Exam $exam): Exam
    {
        if ($exam->type === ExamType::Online) {
            $questions = $exam->questions()->get();
            if ($questions->isEmpty()) {
                throw ValidationException::withMessages(['questions' => __('exams.publish_no_questions')]);
            }
            if ((int) $questions->sum('marks') !== (int) $exam->total_marks) {
                throw ValidationException::withMessages(['total_marks' => __('exams.publish_marks_mismatch', ['sum' => $questions->sum('marks'), 'total' => $exam->total_marks])]);
            }
        }

        $exam->update(['status' => ExamStatus::Published]);

        return $exam;
    }

    public function close(Exam $exam): Exam
    {
        $exam->update(['status' => ExamStatus::Closed]);

        // Finalise any in-progress attempts.
        $exam->attempts()->where('status', AttemptStatus::InProgress->value)->get()->each(fn (ExamAttempt $a) => $this->submit($a, now(), true));

        return $exam;
    }

    // ------------------------------------------------------------------ online attempts

    public function startAttempt(Exam $exam, Student $student, CarbonInterface $now): ExamAttempt
    {
        if ($exam->type !== ExamType::Online) {
            throw ValidationException::withMessages(['exam' => __('exams.not_online')]);
        }
        if (! $exam->isOpenAt($now)) {
            throw ValidationException::withMessages(['exam' => __('exams.window_closed')]);
        }
        if (! $this->isEligible($exam, $student)) {
            throw ValidationException::withMessages(['exam' => __('exams.not_eligible')]);
        }

        $attempt = ExamAttempt::firstOrCreate(
            ['exam_id' => $exam->id, 'student_id' => $student->id],
            [
                'started_at' => $now,
                'expires_at' => min($now->copy()->addMinutes($exam->duration_minutes), $exam->closes_at),
                'status' => AttemptStatus::InProgress,
                'question_order' => $this->buildOrder($exam),
            ]
        );

        return $this->refreshIfExpired($attempt, $now);
    }

    /** Auto-submits an expired in-progress attempt. */
    public function refreshIfExpired(ExamAttempt $attempt, CarbonInterface $now): ExamAttempt
    {
        if ($attempt->status === AttemptStatus::InProgress && $attempt->isExpiredAt($now)) {
            $this->submit($attempt, $attempt->expires_at, true);
        }

        return $attempt->fresh();
    }

    /** @return list<int> */
    private function buildOrder(Exam $exam): array
    {
        $ids = $exam->questions()->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($exam->randomize) {
            shuffle($ids);
        }

        return $ids;
    }

    /**
     * Questions for the student's screen, in attempt order, without correct answers.
     * Option order is shuffled deterministically per attempt (stable across reloads).
     *
     * @return Collection<int, ExamQuestion>
     */
    public function questionsForAttempt(ExamAttempt $attempt): Collection
    {
        $order = $attempt->question_order ?: $attempt->exam->questions()->pluck('id')->all();
        $questions = ExamQuestion::whereIn('id', $order)->get()->keyBy('id');

        $out = new Collection;
        foreach ($order as $i => $id) {
            if (! $q = $questions->get($id)) {
                continue;
            }
            if ($attempt->exam->randomize && in_array($q->type, [QuestionType::Mcq, QuestionType::OrderVerses], true) && is_array($q->options)) {
                $opts = $q->options;
                mt_srand($attempt->id * 1000 + $q->id);
                shuffle($opts);
                mt_srand();
                $q->setAttribute('display_options', $opts);
            } else {
                $q->setAttribute('display_options', $q->options);
            }
            $q->setAttribute('position', $i + 1);
            $out->push($q);
        }

        return $out;
    }

    public function remainingSeconds(ExamAttempt $attempt, CarbonInterface $now): int
    {
        if ($attempt->status !== AttemptStatus::InProgress || ! $attempt->expires_at) {
            return 0;
        }

        return max(0, (int) $now->diffInSeconds($attempt->expires_at, false));
    }

    /** @param array<int, array{question_id:int, answer:mixed}> $answers */
    public function saveAnswers(ExamAttempt $attempt, array $answers, CarbonInterface $now): ExamAttempt
    {
        $attempt = $this->refreshIfExpired($attempt, $now);
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw ValidationException::withMessages(['attempt' => __('exams.attempt_closed')]);
        }

        $allowed = array_map('intval', $attempt->question_order ?? []);

        DB::transaction(function () use ($attempt, $answers, $allowed, $now) {
            foreach ($answers as $row) {
                $qid = (int) $row['question_id'];
                if (! in_array($qid, $allowed, true)) {
                    continue;
                }
                ExamAnswer::updateOrCreate(
                    ['exam_attempt_id' => $attempt->id, 'exam_question_id' => $qid],
                    ['answer' => is_array($row['answer'] ?? null) ? $row['answer'] : ($row['answer'] === null ? null : ['value' => $row['answer']]), 'saved_at' => $now]
                );
            }
        });

        return $attempt->fresh();
    }

    public function submit(ExamAttempt $attempt, ?CarbonInterface $at = null, bool $auto = false): ExamAttempt
    {
        $at ??= now();

        if (in_array($attempt->status, [AttemptStatus::Submitted, AttemptStatus::Graded], true)) {
            return $attempt;
        }

        return DB::transaction(function () use ($attempt, $at, $auto) {
            $exam = $attempt->exam;
            $questions = $exam->questions()->get()->keyBy('id');
            $answers = $attempt->answers()->get()->keyBy('exam_question_id');

            $autoScore = 0;
            $needsManual = false;

            foreach ($questions as $q) {
                $ans = $answers->get($q->id);
                if ($q->type === QuestionType::Recitation) {
                    $needsManual = true;
                    if (! $ans) {
                        ExamAnswer::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $q->id, 'answer' => null]);
                    }

                    continue;
                }

                $result = $this->grader->grade($q, $ans?->answer);
                $autoScore += (int) $result['score'];

                if ($ans) {
                    $ans->update(['is_correct' => $result['is_correct'], 'score' => $result['score']]);
                } else {
                    ExamAnswer::create(['exam_attempt_id' => $attempt->id, 'exam_question_id' => $q->id, 'answer' => null, 'is_correct' => false, 'score' => 0]);
                }
            }

            $data = [
                'submitted_at' => $at,
                'auto_score' => $autoScore,
                'status' => $needsManual ? AttemptStatus::Submitted : AttemptStatus::Graded,
            ];

            if (! $needsManual) {
                $data['manual_score'] = 0;
                $data['total_score'] = $autoScore;
                $data['passed'] = $autoScore >= $exam->pass_mark;
                $data['graded_at'] = $at;
            }

            $attempt->update($data);

            return $attempt->fresh();
        });
    }

    // ------------------------------------------------------------------ grading

    /** @param array<int, array{answer_id:int, score:int, grader_note?:string|null}> $rows */
    public function gradeManually(ExamAttempt $attempt, array $rows, User $grader): ExamAttempt
    {
        if ($attempt->status === AttemptStatus::InProgress) {
            throw ValidationException::withMessages(['attempt' => __('exams.attempt_in_progress')]);
        }

        $old = ['manual_score' => $attempt->manual_score, 'total_score' => $attempt->total_score, 'passed' => $attempt->passed];

        DB::transaction(function () use ($attempt, $rows, $grader) {
            $answers = $attempt->answers()->with('question')->get()->keyBy('id');

            foreach ($rows as $row) {
                $ans = $answers->get((int) $row['answer_id']);
                if (! $ans || $ans->question->type !== QuestionType::Recitation) {
                    continue;
                }
                $score = min((int) $row['score'], (int) $ans->question->marks);
                $ans->update(['score' => $score, 'is_correct' => $score > 0, 'graded_by' => $grader->id, 'grader_note' => $row['grader_note'] ?? null]);
            }

            $manual = (int) $attempt->answers()->whereHas('question', fn ($q) => $q->where('type', QuestionType::Recitation->value))->sum('score');
            $total = (int) $attempt->auto_score + $manual;

            $attempt->update([
                'manual_score' => $manual,
                'total_score' => $total,
                'passed' => $total >= $attempt->exam->pass_mark,
                'status' => AttemptStatus::Graded,
                'graded_by' => $grader->id,
                'graded_at' => now(),
            ]);
        });

        $attempt = $attempt->fresh();
        $this->audit->record('exam.graded', $attempt, $old, ['manual_score' => $attempt->manual_score, 'total_score' => $attempt->total_score, 'passed' => $attempt->passed], $grader->id);

        return $attempt;
    }

    /** Paper exams: manual score entry per student. @param array<int, array{student_id:int, score:int, note?:string|null}> $scores */
    public function recordPaperScores(Exam $exam, array $scores, User $grader): Collection
    {
        $eligible = $this->eligibleStudentsQuery($exam)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $out = new Collection;

        DB::transaction(function () use ($exam, $scores, $grader, $eligible, $out) {
            foreach ($scores as $row) {
                $sid = (int) $row['student_id'];
                if (! in_array($sid, $eligible, true)) {
                    continue;
                }
                $score = min((int) $row['score'], (int) $exam->total_marks);

                $attempt = ExamAttempt::firstOrNew(['exam_id' => $exam->id, 'student_id' => $sid]);
                $old = $attempt->exists ? ['total_score' => $attempt->total_score, 'passed' => $attempt->passed] : [];

                $attempt->fill([
                    'started_at' => $attempt->started_at ?? $exam->opens_at,
                    'submitted_at' => $attempt->submitted_at ?? now(),
                    'status' => AttemptStatus::Graded,
                    'auto_score' => 0,
                    'manual_score' => $score,
                    'total_score' => $score,
                    'passed' => $score >= $exam->pass_mark,
                    'graded_by' => $grader->id,
                    'graded_at' => now(),
                ])->save();

                $this->audit->record('exam.graded', $attempt, $old, ['total_score' => $score, 'passed' => $attempt->passed, 'note' => $row['note'] ?? null], $grader->id);
                $out->push($attempt);
            }
        });

        return $out;
    }

    // ------------------------------------------------------------------ results

    /** @return array{rows: array, pass_rate: float, average: float, graded: int, eligible: int, top: array} */
    public function results(Exam $exam): array
    {
        $students = $this->eligibleStudents($exam);
        $attempts = $exam->attempts()->get()->keyBy('student_id');

        $rows = [];
        $graded = [];
        foreach ($students as $s) {
            $a = $attempts->get($s->id);
            $rows[] = [
                'student_id' => $s->id,
                'student_no' => $s->student_no,
                'full_name' => $s->full_name,
                'attempt_id' => $a?->id,
                'status' => $a?->status?->value,
                'score' => $a?->total_score,
                'passed' => $a?->passed,
            ];
            if ($a && $a->status === AttemptStatus::Graded) {
                $graded[] = ['student_id' => $s->id, 'full_name' => $s->full_name, 'score' => (int) $a->total_score, 'passed' => (bool) $a->passed];
            }
        }

        $count = count($graded);
        $passed = count(array_filter($graded, fn ($g) => $g['passed']));
        usort($graded, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'rows' => $rows,
            'eligible' => $students->count(),
            'graded' => $count,
            'passed' => $passed,
            'pass_rate' => $count ? round($passed * 100 / $count, 1) : 0.0,
            'average' => $count ? round(array_sum(array_column($graded, 'score')) / $count, 2) : 0.0,
            'top' => array_slice($graded, 0, 3),
        ];
    }
}
