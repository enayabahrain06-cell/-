<?php

namespace App\Services\Distribution;

use App\Enums\LessonStatus;
use App\Enums\LessonStudentStatus;
use App\Enums\StudentStatus;
use App\Models\AcademicTerm;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Level;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Circles\CircleEnrollmentService;
use App\Services\Lessons\LessonService;
use App\Services\Registration\PackageSuitability;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * توزيع المستويات / ترفيع الطلبة / تحديث المستوى: placing students in a class of a level in a term.
 * The writes go through the existing enrollment logic only (LessonService::enroll for a first class in the term,
 * CircleEnrollmentService::move inside the term), so capacity, gender and age rules apply and history is kept.
 */
class ClassPlacement
{
    public function __construct(private LessonService $lessons, private CircleEnrollmentService $circles, private AuditLogger $audit) {}

    /** Active classes of a term within the user's track, optionally of one level, with their active counts. */
    public function termClasses(AcademicTerm $term, User $user, ?int $levelId = null): Collection
    {
        return Lesson::with(['level', 'teacher:id,name', 'package', 'ageGroup'])
            ->withCount(['lessonStudents as active_count' => fn ($q) => $q->where('status', LessonStudentStatus::Active->value)])
            ->where('status', LessonStatus::Active->value)
            ->tap(fn ($q) => TermScope::via($q, $term->id))
            ->tap(fn ($q) => Track::scope($q, $user))
            ->when($levelId, fn ($q) => $q->where('level_id', $levelId))
            ->orderBy('name')->get();
    }

    public static function presentClass(Lesson $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'level_id' => $l->level_id,
            'level' => $l->level?->name(),
            'teacher' => $l->teacher?->name,
            'gender' => $l->gender?->value,
            'capacity' => $l->capacity,
            'free_seats' => max(0, $l->capacity - (int) ($l->active_count ?? $l->activeStudentCount())),
        ];
    }

    public static function presentLevel(Level $l): array
    {
        return ['id' => $l->id, 'name' => $l->name(), 'sort' => $l->sort, 'min_age' => $l->min_age, 'max_age' => $l->max_age, 'memorization_levels' => $l->memorization_levels ?? []];
    }

    /** The student's active stay in one of the term's classes. */
    public function currentRow(Student $student, AcademicTerm $term): ?LessonStudent
    {
        return LessonStudent::with('lesson.level')->where('student_id', $student->id)
            ->where('status', LessonStudentStatus::Active->value)
            ->whereHas('lesson', fn ($q) => TermScope::via($q, $term->id))
            ->first();
    }

    /** Age on the term's first day (today when the term has no dates). */
    public static function ageAtStart(Student $student, AcademicTerm $term): ?int
    {
        return $student->birth_date ? PackageSuitability::ageOn($student->birth_date, $term->start_date ?? today()) : null;
    }

    /** The first level (by sort) whose own rules accept the student; levels without rules are never suggested. */
    public static function suggestLevel(Collection $levels, Student $student, ?int $age): ?Level
    {
        return $levels->first(fn (Level $l) => ($l->min_age !== null || $l->max_age !== null || ! empty($l->memorization_levels))
            && $l->accepts($age, $student->memorization_level?->value));
    }

    /** The class of the level with the most free seats that this student may join (not the one they are in). */
    public function autoClass(Student $student, AcademicTerm $term, int $levelId, User $actor): Lesson
    {
        $current = $this->currentRow($student, $term)?->lesson_id;
        $pick = $this->termClasses($term, $actor, $levelId)
            ->filter(fn (Lesson $l) => $l->id !== $current && $l->capacity - (int) $l->active_count > 0 && $this->lessons->ineligibility($l, $student, $actor) === null)
            ->sortByDesc(fn (Lesson $l) => $l->capacity - (int) $l->active_count)
            ->first();
        if (! $pick) {
            throw ValidationException::withMessages(['lesson_id' => __('distribution.errors.no_class', ['name' => $student->full_name])]);
        }

        return $pick;
    }

    /**
     * Put the student in the class: a move when they already have a class in the same term (history kept),
     * else a first enrollment in the term (an older term's stay is closed as history, never deleted).
     */
    public function place(Student $student, Lesson $lesson, AcademicTerm $term, User $actor, ?string $reason = null): LessonStudent
    {
        if (! TermScope::matches($lesson->package, $term->id)) {
            throw ValidationException::withMessages(['lesson_id' => __('distribution.errors.class_not_in_term')]);
        }
        if (! Track::allows($actor, $lesson->gender)) {
            throw ValidationException::withMessages(['lesson_id' => __('gender.outside_track')]);
        }
        if ($why = $this->lessons->ineligibility($lesson, $student, $actor)) {
            throw ValidationException::withMessages(['student' => $this->lessons->ineligibilityMessage($why, $lesson, $student)]);
        }

        if ($this->currentRow($student, $term)) {
            return $this->circles->move($student, $lesson, $actor, today(), $reason);
        }
        $this->lessons->enroll($lesson, [$student->id], $actor->id, true);

        return LessonStudent::where('lesson_id', $lesson->id)->where('student_id', $student->id)
            ->where('status', LessonStudentStatus::Active->value)->firstOrFail();
    }

    /**
     * Place several students in one level (a chosen class, or "auto" per student). One refusal never blocks the others.
     *
     * @param  list<int>  $studentIds
     * @return list<array{student_id: int, full_name: string, ok: bool, lesson: ?array, message: string}>
     */
    public function placeMany(array $studentIds, int $levelId, ?int $lessonId, AcademicTerm $term, User $actor): array
    {
        $chosen = $lessonId ? Lesson::with('package')->findOrFail($lessonId) : null;
        if ($chosen && (int) $chosen->level_id !== $levelId) {
            throw ValidationException::withMessages(['lesson_id' => __('distribution.errors.class_not_in_level')]);
        }
        $out = [];
        foreach (Student::whereIn('id', $studentIds)->orderBy('full_name')->get() as $student) {
            try {
                $lesson = $chosen ?? $this->autoClass($student, $term, $levelId, $actor);
                $this->place($student, $lesson, $term, $actor);
                $out[] = self::result($student, true, $lesson, __('distribution.placed', ['name' => $student->full_name, 'class' => $lesson->name]));
            } catch (ValidationException $e) {
                $out[] = self::result($student, false, null, collect($e->errors())->flatten()->first() ?? $e->getMessage());
            }
        }

        return $out;
    }

    /**
     * End-of-term decisions for students of a level (ترفيع الطلبة). promote/repeat enroll into the target term's class
     * of the next/same level; graduate records only (and sets the status when asked). Every decision is recorded.
     *
     * @param  list<array{student_id: int, decision: string, lesson_id?: ?int, reason?: ?string}>  $decisions
     */
    public function promote(AcademicTerm $from, Level $fromLevel, AcademicTerm $to, Level $toLevel, array $decisions, bool $markGraduated, User $actor): array
    {
        $sourceIds = $this->levelLessonIds($from, $fromLevel->id);
        $out = [];
        foreach ($decisions as $d) {
            $student = Student::find($d['student_id']);
            if (! $student) {
                continue;
            }
            try {
                if (! Track::allows($actor, $student->gender)) {
                    throw ValidationException::withMessages(['student' => __('gender.outside_track')]);
                }
                if (StudentPromotion::where('student_id', $student->id)->where('from_term_id', $from->id)->whereIn('decision', ['promote', 'repeat', 'graduate'])->exists()) {
                    throw ValidationException::withMessages(['student' => __('distribution.errors.already_decided', ['name' => $student->full_name])]);
                }
                $fromRow = LessonStudent::where('student_id', $student->id)->whereIn('lesson_id', $sourceIds)
                    ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")->orderByDesc('id')->first();
                if (! $fromRow) {
                    throw ValidationException::withMessages(['student' => __('distribution.errors.not_in_level', ['name' => $student->full_name])]);
                }
                $record = ['student_id' => $student->id, 'from_term_id' => $from->id, 'from_level_id' => $fromLevel->id, 'from_lesson_id' => $fromRow->lesson_id,
                    'decision' => $d['decision'], 'reason' => $d['reason'] ?? null, 'decided_by' => $actor->id];

                $lesson = DB::transaction(function () use ($d, $student, $to, $toLevel, $fromLevel, $markGraduated, $actor, $record) {
                    if ($d['decision'] === 'graduate') {
                        $row = StudentPromotion::create($record);
                        if ($markGraduated && $student->status !== StudentStatus::Graduated) {
                            $old = $student->status?->value;
                            $student->update(['status' => StudentStatus::Graduated]);
                            $this->audit->record('student.graduated', $student, ['status' => $old], ['status' => StudentStatus::Graduated->value], $actor->id);
                        }
                        $this->audit->record('student.promotion', $row, [], $row->only(['student_id', 'decision', 'from_term_id', 'from_level_id']), $actor->id);

                        return null;
                    }
                    $levelId = $d['decision'] === 'promote' ? $toLevel->id : $fromLevel->id;
                    $lesson = ! empty($d['lesson_id']) ? Lesson::with('package')->findOrFail($d['lesson_id']) : $this->autoClass($student, $to, $levelId, $actor);
                    if ((int) $lesson->level_id !== $levelId) {
                        throw ValidationException::withMessages(['lesson_id' => __('distribution.errors.class_not_in_level')]);
                    }
                    $this->place($student, $lesson, $to, $actor, $d['reason'] ?? null);
                    $row = StudentPromotion::create($record + ['to_term_id' => $to->id, 'to_level_id' => $levelId, 'to_lesson_id' => $lesson->id]);
                    $this->audit->record('student.promotion', $row, [], $row->only(['student_id', 'decision', 'from_term_id', 'to_term_id', 'to_level_id', 'to_lesson_id']), $actor->id);

                    return $lesson;
                });
                $out[] = self::result($student, true, $lesson, __('distribution.decided.'.$d['decision'], ['name' => $student->full_name, 'class' => $lesson?->name]));
            } catch (ValidationException $e) {
                $out[] = self::result($student, false, null, collect($e->errors())->flatten()->first() ?? $e->getMessage());
            }
        }

        return $out;
    }

    /** تحديث المستوى: move one student to a class of another level in the term and record it. */
    public function changeLevel(Student $student, AcademicTerm $term, Level $level, ?int $lessonId, ?string $reason, User $actor): StudentPromotion
    {
        $current = $this->currentRow($student, $term);
        if ($current && (int) $current->lesson?->level_id === $level->id && ! $lessonId) {
            throw ValidationException::withMessages(['level_id' => __('distribution.errors.same_level')]);
        }

        return DB::transaction(function () use ($student, $term, $level, $lessonId, $reason, $actor, $current) {
            $lesson = $lessonId ? Lesson::with('package')->findOrFail($lessonId) : $this->autoClass($student, $term, $level->id, $actor);
            if ((int) $lesson->level_id !== $level->id) {
                throw ValidationException::withMessages(['lesson_id' => __('distribution.errors.class_not_in_level')]);
            }
            $this->place($student, $lesson, $term, $actor, $reason);
            $row = StudentPromotion::create([
                'student_id' => $student->id, 'from_term_id' => $term->id, 'from_level_id' => $current?->lesson?->level_id, 'from_lesson_id' => $current?->lesson_id,
                'to_term_id' => $term->id, 'to_level_id' => $level->id, 'to_lesson_id' => $lesson->id,
                'decision' => 'level_change', 'reason' => $reason, 'decided_by' => $actor->id,
            ]);
            $this->audit->record('student.level_changed', $row, ['level_id' => $current?->lesson?->level_id, 'lesson_id' => $current?->lesson_id], ['level_id' => $level->id, 'lesson_id' => $lesson->id, 'reason' => $reason], $actor->id);

            return $row;
        });
    }

    /** @return list<int> */
    public function levelLessonIds(AcademicTerm $term, int $levelId): array
    {
        return Lesson::where('level_id', $levelId)->tap(fn ($q) => TermScope::via($q, $term->id))->pluck('id')->all();
    }

    public static function presentPromotion(StudentPromotion $p): array
    {
        return [
            'id' => $p->id,
            'decision' => $p->decision,
            'decision_label' => __('distribution.decisions.'.$p->decision),
            'from_term' => $p->fromTerm?->name(),
            'from_level' => $p->fromLevel?->name(),
            'from_lesson' => $p->fromLesson?->name,
            'to_term' => $p->toTerm?->name(),
            'to_level' => $p->toLevel?->name(),
            'to_lesson' => $p->toLesson?->name,
            'reason' => $p->reason,
            'decided_by' => $p->decider?->name,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }

    private static function result(Student $student, bool $ok, ?Lesson $lesson, string $message): array
    {
        return ['student_id' => $student->id, 'full_name' => $student->full_name, 'ok' => $ok, 'lesson' => $lesson ? ['id' => $lesson->id, 'name' => $lesson->name] : null, 'message' => $message];
    }
}
