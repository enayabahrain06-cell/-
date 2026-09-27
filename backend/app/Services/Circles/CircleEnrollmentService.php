<?php

namespace App\Services\Circles;

use App\Enums\LessonStudentStatus;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Writes lesson_students. One row per stay in a circle: status 'active' means "currently in the circle";
 * leaving or moving closes the row (left_at, moved_by, reason, moved_to_lesson_id) and a move opens a new one.
 * A student is active in at most one circle.
 */
class CircleEnrollmentService
{
    public function __construct(private CircleMatcher $matcher, private AuditLogger $audit) {}

    /**
     * Put a student in a circle now (call inside a transaction when part of a larger write).
     * Checks gender, age range and a free seat under a row lock. Moves the student when $move is set
     * and they are active elsewhere; refuses otherwise.
     */
    public function join(Lesson $lesson, Student $student, ?User $by = null, bool $move = false, ?string $reason = null, ?CarbonInterface $on = null): LessonStudent
    {
        return DB::transaction(function () use ($lesson, $student, $by, $move, $reason, $on) {
            $locked = Lesson::whereKey($lesson->id)->lockForUpdate()->firstOrFail();
            $on ??= today();

            if ($this->activeRow($student, $locked->id)) {
                throw ValidationException::withMessages(['lesson_id' => __('circles.errors.already_in', ['name' => $student->full_name])]);
            }
            $this->assertFits($locked, $student);

            $current = $this->activeRows($student);
            if ($current->isNotEmpty() && ! $move) {
                throw ValidationException::withMessages(['lesson_id' => __('circles.errors.in_other_circle', ['name' => $student->full_name, 'circle' => $current->first()->lesson?->name])]);
            }
            $current->each(fn (LessonStudent $row) => $this->close($row, $by, $reason, $on, $locked->id));

            return LessonStudent::create([
                'lesson_id' => $locked->id,
                'student_id' => $student->id,
                'status' => LessonStudentStatus::Active,
                'joined_at' => $on->toDateString(),
            ]);
        });
    }

    /** Move a student to another circle from a given date, keeping the history. */
    public function move(Student $student, Lesson $to, User $by, CarbonInterface $effective, ?string $reason = null): LessonStudent
    {
        $from = $this->activeRows($student)->first();
        $row = $this->join($to, $student, $by, true, $reason, $effective);

        $this->audit->record('student.circle_moved', $student, ['lesson_id' => $from?->lesson_id], [
            'lesson_id' => $to->id, 'effective_date' => $effective->toDateString(), 'reason' => $reason,
        ], $by->id);

        return $row;
    }

    /** Take a student out of a circle (no new circle). */
    public function leave(Lesson $lesson, Student $student, ?User $by = null, ?string $reason = null): void
    {
        if ($row = $this->activeRow($student, $lesson->id)) {
            $this->close($row, $by, $reason, today());
        }
    }

    /** @return Collection<int, LessonStudent> newest first, with circle, teacher and who moved them */
    public function history(Student $student): Collection
    {
        return LessonStudent::with(['lesson:id,name,teacher_id,age_group_id', 'lesson.teacher:id,name', 'lesson.ageGroup', 'mover:id,name', 'movedTo:id,name'])
            ->where('student_id', $student->id)
            ->orderByDesc('joined_at')->orderByDesc('id')->get();
    }

    public function assertFits(Lesson $lesson, Student $student): void
    {
        if (! $student->gender || ! $student->birth_date) {
            return;
        }
        $fit = $this->matcher->fit($lesson->loadMissing('package', 'ageGroup'), $student->gender, $student->birth_date);
        if (! $fit['fits'] && $fit['reason'] !== 'teacher') {
            [$min, $max] = CircleMatcher::range($lesson);
            throw ValidationException::withMessages(['lesson_id' => __('circles.errors.'.$fit['reason'], [
                'name' => $student->full_name, 'age' => $fit['age'], 'min' => $min, 'max' => $max ?? '+',
            ])]);
        }
    }

    /** @return Collection<int, LessonStudent> */
    public function activeRows(Student $student): Collection
    {
        return LessonStudent::with('lesson:id,name,teacher_id,gender')->where('student_id', $student->id)
            ->where('status', LessonStudentStatus::Active->value)->get();
    }

    private function activeRow(Student $student, int $lessonId): ?LessonStudent
    {
        return LessonStudent::where('student_id', $student->id)->where('lesson_id', $lessonId)
            ->where('status', LessonStudentStatus::Active->value)->first();
    }

    private function close(LessonStudent $row, ?User $by, ?string $reason, CarbonInterface $on, ?int $movedTo = null): void
    {
        $row->update([
            'status' => LessonStudentStatus::Left,
            'left_at' => $on->toDateString(),
            'moved_by' => $by?->id,
            'reason' => $reason,
            'moved_to_lesson_id' => $movedTo,
        ]);
    }
}
