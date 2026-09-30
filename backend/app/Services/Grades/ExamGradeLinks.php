<?php

namespace App\Services\Grades;

use App\Enums\ExamType;
use App\Models\Exam;
use App\Models\GradeComponent;
use App\Models\Lesson;
use App\Models\SubjectLesson;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * U10: an exam of a class or package in the term can count for a grade component (grade_components.exam_id) and name
 * the subject lessons it covers (exam_required_lessons). The exam keeps its subject; the free-text syllabus stays as
 * a note. Placement tests take neither.
 */
class ExamGradeLinks
{
    public function __construct(private AuditLogger $audit) {}

    /** Extra rules for the exam form. */
    public static function rules(): array
    {
        return [
            'grade_component_id' => ['sometimes', 'nullable', 'integer', 'exists:grade_components,id'],
            'required_lesson_ids' => ['sometimes', 'nullable', 'array', 'max:300'],
            'required_lesson_ids.*' => ['integer', 'distinct', 'exists:subject_lessons,id'],
        ];
    }

    /** Subject of the chosen component, used when the form sends no subject (the component names the subject). */
    public static function subjectOf(?int $componentId): ?int
    {
        return $componentId ? GradeComponent::with('levelSubject')->find($componentId)?->levelSubject?->subject_id : null;
    }

    /** Apply the form's link fields to a saved exam. Throws (the caller's transaction rolls back) when they do not fit. */
    public function apply(Exam $exam, array $data): void
    {
        $exam->refresh();
        $hasComponent = array_key_exists('grade_component_id', $data);
        $hasLessons = array_key_exists('required_lesson_ids', $data);

        if ($exam->type === ExamType::Placement) {
            if (($hasComponent && $data['grade_component_id']) || ($hasLessons && ! empty($data['required_lesson_ids']))) {
                throw ValidationException::withMessages(['grade_component_id' => __('grades.errors.placement')]);
            }

            return;
        }

        if ($hasComponent) {
            $this->link($exam, $data['grade_component_id'] ? (int) $data['grade_component_id'] : null);
        } elseif (($current = $exam->gradeComponent()->with('levelSubject')->first()) && ! Gradebook::examFits($exam, $current->levelSubject)) {
            throw ValidationException::withMessages(['grade_component_id' => __('grades.errors.exam_no_longer_fits')]);
        }
        if ($hasLessons) {
            $this->syncRequired($exam, array_map('intval', $data['required_lesson_ids'] ?? []));
        }
    }

    /** Link the exam to a component (null = unlink). */
    public function link(Exam $exam, ?int $componentId): void
    {
        $current = $exam->gradeComponent()->first();
        if ($componentId === null) {
            if ($current) {
                $current->update(['exam_id' => null]);
                $this->audit->record('grade_component.exam_unlinked', $current, ['exam_id' => $exam->id], ['exam_id' => null]);
            }

            return;
        }
        $component = GradeComponent::with('levelSubject')->findOrFail($componentId);
        if (! $component->isExam()) {
            throw ValidationException::withMessages(['grade_component_id' => __('grades.errors.not_exam_component')]);
        }
        if ($component->exam_id && (int) $component->exam_id !== (int) $exam->id) {
            throw ValidationException::withMessages(['grade_component_id' => __('grades.errors.component_taken')]);
        }
        if (! Gradebook::examFits($exam, $component->levelSubject)) {
            throw ValidationException::withMessages(['grade_component_id' => __('grades.errors.exam_does_not_fit')]);
        }
        if ($current && $current->id !== $component->id) {
            $current->update(['exam_id' => null]);
        }
        $old = $component->only(['exam_id', 'max_marks']);
        $component->update(['exam_id' => $exam->id, 'max_marks' => $exam->total_marks]);
        $this->audit->record('grade_component.exam_linked', $component, $old, $component->only(['exam_id', 'max_marks']));
    }

    /** Levels whose subject lessons may be required: the class's level, or the levels of the package's classes. */
    public static function examLevels(Exam $exam): array
    {
        if ($exam->lesson_id) {
            return array_filter([Lesson::whereKey($exam->lesson_id)->value('level_id')]);
        }

        return Lesson::where('package_id', $exam->package_id)->whereNotNull('level_id')->distinct()->pluck('level_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Subject lessons of the exam's subject for its level(s) or every level. */
    public static function availableLessons(Exam $exam): \Illuminate\Support\Collection
    {
        $levels = self::examLevels($exam);

        return SubjectLesson::with('level:id,name_ar,name_en')->where('subject_id', $exam->subject_id)
            ->where(fn ($w) => $w->whereNull('level_id')->orWhereIn('level_id', $levels ?: [0]))
            ->ordered()->get();
    }

    /** @param  list<int>  $ids */
    public function syncRequired(Exam $exam, array $ids): void
    {
        $allowed = self::availableLessons($exam)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $bad = array_values(array_diff($ids, $allowed));
        if ($bad) {
            throw ValidationException::withMessages(['required_lesson_ids' => __('grades.errors.lesson_not_of_exam')]);
        }
        $old = $exam->requiredLessons()->pluck('subject_lessons.id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $exam->requiredLessons()->sync($ids);
        $new = collect($ids)->sort()->values()->all();
        if ($old !== $new) {
            $this->audit->record('exam.required_lessons', $exam, ['required_lesson_ids' => $old], ['required_lesson_ids' => $new]);
        }
    }

    public static function presentLesson(SubjectLesson $l): array
    {
        return ['id' => $l->id, 'title' => $l->title, 'description' => $l->description, 'level' => $l->level ? ['id' => $l->level->id, 'name' => $l->level->name()] : null];
    }
}
