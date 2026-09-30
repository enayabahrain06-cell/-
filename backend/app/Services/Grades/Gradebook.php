<?php

namespace App\Services\Grades;

use App\Enums\AttemptStatus;
use App\Enums\ExamType;
use App\Models\AcademicTerm;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\GradeComponent;
use App\Models\GradeEntry;
use App\Models\Lesson;
use App\Models\LevelSubject;
use App\Models\Student;
use App\Models\User;
use App\Policies\LessonPolicy;
use App\Support\TeacherScope;
use App\Support\TermScope;
use App\Support\Track;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * الدرجات: a class's gradebook for one level subject of the term.
 *
 * Columns are the level subject's grade components (توزيع الدرجات). Scores of an exam component are READ from the
 * linked exam's graded attempt (exam_attempts.total_score, the only place exam scores live); every other component
 * keeps its scores in grade_entries.
 *
 * Weighted total (%) = Σ(score / full marks × weight) / Σ weight × 100 over the weighted components; a missing score
 * counts as zero. A student is "complete" when every component has a score.
 */
class Gradebook
{
    // ------------------------------------------------------------------ reach

    public static function canView(User $user, ?Lesson $lesson, ?int $subjectId): bool
    {
        return $lesson !== null && $user->can('grades.view') && LessonPolicy::ownsOrManages($user, $lesson, $subjectId);
    }

    public static function canRecord(User $user, ?Lesson $lesson, ?int $subjectId): bool
    {
        return $lesson !== null && $user->can('grades.record') && LessonPolicy::ownsOrManages($user, $lesson, $subjectId);
    }

    /** Classes of the term the user reaches: managers (lessons.manage) their track's, teachers those they teach. */
    public static function termLessons(User $user, AcademicTerm $term): Builder
    {
        return Lesson::query()->tap(fn ($q) => TermScope::via($q, $term->id))->tap(fn ($q) => Track::scope($q, $user))
            ->when(! $user->can('lessons.manage'), fn ($q) => $q->whereIn('lessons.id', TeacherScope::lessonIds($user)));
    }

    /** The class's level subjects this term (optionally only those the user may view / record). */
    public static function levelSubjects(Lesson $lesson, AcademicTerm $term, ?User $user = null, bool $record = false): Collection
    {
        if (! $lesson->level_id) {
            return collect();
        }

        return LevelSubject::with(['subject', 'level'])->where('academic_term_id', $term->id)->where('level_id', $lesson->level_id)
            ->orderBy('sort')->orderBy('id')->get()
            ->filter(fn (LevelSubject $ls) => $user === null || ($record ? self::canRecord($user, $lesson, $ls->subject_id) : self::canView($user, $lesson, $ls->subject_id)))
            ->values();
    }

    // ------------------------------------------------------------------ exams ↔ components

    /** Can this exam count for the level subject: same subject, not a placement test, a class or package of that level and term. */
    public static function examFits(Exam $exam, LevelSubject $ls): bool
    {
        if ($exam->type === ExamType::Placement || (int) $exam->subject_id !== (int) $ls->subject_id) {
            return false;
        }
        if ($exam->lesson_id) {
            return Lesson::whereKey($exam->lesson_id)->where('level_id', $ls->level_id)
                ->whereHas('package', fn ($p) => $p->where('academic_term_id', $ls->academic_term_id))->exists();
        }

        return $exam->package_id && Lesson::where('package_id', $exam->package_id)->where('level_id', $ls->level_id)
            ->whereHas('package', fn ($p) => $p->where('academic_term_id', $ls->academic_term_id))->exists();
    }

    /** Exams that may be linked to a component of the level subject (their subject, a class/package of that level and term). */
    public static function linkableExams(LevelSubject $ls): Collection
    {
        $lessons = Lesson::where('level_id', $ls->level_id)->whereHas('package', fn ($p) => $p->where('academic_term_id', $ls->academic_term_id));

        return Exam::query()->where('type', '!=', ExamType::Placement->value)->where('subject_id', $ls->subject_id)
            ->where(fn ($w) => $w->whereIn('lesson_id', (clone $lessons)->select('id'))
                ->orWhere(fn ($p) => $p->whereNull('lesson_id')->whereIn('package_id', (clone $lessons)->select('package_id'))))
            ->with(['lesson:id,name', 'package', 'gradeComponent:id,exam_id,level_subject_id'])
            ->orderByDesc('exam_date')->orderByDesc('id')->get();
    }

    // ------------------------------------------------------------------ the book

    /** Active students of the class, by name. */
    public static function students(Lesson $lesson): Collection
    {
        return Student::query()->where('status', 'active')
            ->whereHas('lessonStudents', fn ($q) => $q->where('lesson_id', $lesson->id)->where('status', 'active'))
            ->orderBy('full_name')->get(['id', 'student_no', 'full_name', 'gender']);
    }

    /** @return Collection<int, GradeComponent> */
    public static function components(LevelSubject $ls): Collection
    {
        return GradeComponent::where('level_subject_id', $ls->id)->with('exam:id,name,total_marks,status,type,lesson_id,package_id')->ordered()->get();
    }

    /**
     * Scores per student and component.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array<int, array{score: ?float, max: float, source: string, notes: ?string}>>
     */
    public static function cells(Collection $components, array $studentIds): array
    {
        $out = [];
        if ($components->isEmpty() || ! $studentIds) {
            return $out;
        }
        $entries = GradeEntry::whereIn('grade_component_id', $components->reject->isExam()->pluck('id')->all() ?: [0])
            ->whereIn('student_id', $studentIds)->get()->groupBy('grade_component_id');
        $examIds = $components->filter->isExam()->pluck('exam_id')->filter()->all();
        $attempts = $examIds ? ExamAttempt::whereIn('exam_id', $examIds)->whereIn('student_id', $studentIds)
            ->where('status', AttemptStatus::Graded->value)->get(['exam_id', 'student_id', 'total_score'])->groupBy('exam_id') : collect();

        foreach ($components as $c) {
            $max = $c->maxMarks();
            if ($c->isExam()) {
                $byStudent = $c->exam_id ? ($attempts->get($c->exam_id) ?? collect())->keyBy('student_id') : collect();
                foreach ($studentIds as $sid) {
                    $a = $byStudent->get($sid);
                    $out[$sid][$c->id] = ['score' => $a && $a->total_score !== null ? (float) $a->total_score : null, 'max' => $max, 'source' => 'exam', 'notes' => null];
                }
            } else {
                $byStudent = ($entries->get($c->id) ?? collect())->keyBy('student_id');
                foreach ($studentIds as $sid) {
                    $e = $byStudent->get($sid);
                    $out[$sid][$c->id] = ['score' => $e ? (float) $e->score : null, 'max' => $max, 'source' => 'entry', 'notes' => $e?->notes];
                }
            }
        }

        return $out;
    }

    /** Weighted total (%) of one student's cells; null when no component carries a weight. */
    public static function total(Collection $components, array $cells): ?float
    {
        $weights = 0.0;
        $sum = 0.0;
        foreach ($components as $c) {
            if ($c->weight <= 0) {
                continue;
            }
            $weights += $c->weight;
            $cell = $cells[$c->id] ?? null;
            if ($cell && $cell['score'] !== null && $cell['max'] > 0) {
                $sum += min($cell['score'] / $cell['max'], 1) * $c->weight;
            }
        }

        return $weights > 0 ? round($sum / $weights * 100, 2) : null;
    }

    /**
     * The gradebook of a class for one level subject.
     *
     * @return array{components: array, weight_total: float, students: array, averages: array}
     */
    public static function book(Lesson $lesson, LevelSubject $ls): array
    {
        $components = self::components($ls);
        $students = self::students($lesson);
        $cells = self::cells($components, $students->pluck('id')->all());

        $rows = $students->map(function (Student $s) use ($components, $cells) {
            $mine = $cells[$s->id] ?? [];

            return [
                'student' => ['id' => $s->id, 'student_no' => $s->student_no, 'full_name' => $s->full_name],
                'cells' => (object) $mine,
                'total' => self::total($components, $mine),
                'complete' => $components->isNotEmpty() && collect($mine)->every(fn ($c) => $c['score'] !== null),
            ];
        })->values();

        $averages = [];
        foreach ($components as $c) {
            $scores = collect($cells)->map(fn ($row) => $row[$c->id]['score'] ?? null)->filter(fn ($v) => $v !== null);
            $averages[$c->id] = $scores->isEmpty() ? null : round($scores->avg(), 2);
        }
        $totals = $rows->pluck('total')->filter(fn ($v) => $v !== null);

        return [
            'components' => $components->map(fn (GradeComponent $c) => self::presentComponent($c))->values()->all(),
            'weight_total' => round((float) $components->sum('weight'), 2),
            'students' => $rows->all(),
            'averages' => ['components' => (object) $averages, 'total' => $totals->isEmpty() ? null : round($totals->avg(), 2)],
        ];
    }

    public static function presentComponent(GradeComponent $c): array
    {
        return [
            'id' => $c->id, 'level_subject_id' => $c->level_subject_id, 'name' => $c->name(), 'name_ar' => $c->name_ar, 'name_en' => $c->name_en,
            'kind' => $c->kind, 'max_marks' => $c->maxMarks(), 'weight' => (float) $c->weight, 'sort' => $c->sort,
            'exam' => $c->exam ? ['id' => $c->exam->id, 'name' => $c->exam->name, 'total_marks' => (int) $c->exam->total_marks, 'status' => $c->exam->status?->value] : null,
        ];
    }

    // ------------------------------------------------------------------ ranking (تحديد المتفوقين)

    /**
     * Students of the classes ranked by their weighted grade totals of the term: one subject's total, or the average of
     * their subject totals. Ties share a rank (1, 1, 3). Students without any recorded score are left out; with a
     * gender only that track's students are ranked (one honor board per track).
     *
     * @param  Collection<int, Lesson>  $lessons
     * @return list<array>
     */
    public static function ranking(Collection $lessons, AcademicTerm $term, ?int $subjectId, ?string $gender = null): array
    {
        $rows = [];
        $levelSubjects = LevelSubject::with('subject')->where('academic_term_id', $term->id)
            ->whereIn('level_id', $lessons->pluck('level_id')->filter()->unique()->all() ?: [0])
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))->get()->groupBy('level_id');
        $components = GradeComponent::whereIn('level_subject_id', $levelSubjects->flatten()->pluck('id')->all() ?: [0])
            ->with('exam:id,total_marks')->ordered()->get()->groupBy('level_subject_id');

        foreach ($lessons as $lesson) {
            $students = self::students($lesson)
                ->when($gender, fn ($c) => $c->filter(fn (Student $s) => ($s->gender instanceof \BackedEnum ? $s->gender->value : $s->gender) === $gender))->values();
            if ($students->isEmpty()) {
                continue;
            }
            $perStudent = [];
            foreach ($levelSubjects->get($lesson->level_id) ?? [] as $ls) {
                $comps = $components->get($ls->id) ?? collect();
                if ($comps->isEmpty()) {
                    continue;
                }
                $cells = self::cells($comps, $students->pluck('id')->all());
                foreach ($students as $s) {
                    $mine = $cells[$s->id] ?? [];
                    $t = self::total($comps, $mine);
                    if ($t !== null && collect($mine)->contains(fn ($c) => $c['score'] !== null)) {
                        $perStudent[$s->id][] = ['subject' => $ls->subject?->name(), 'total' => $t];
                    }
                }
            }
            foreach ($students as $s) {
                if (empty($perStudent[$s->id])) {
                    continue;
                }
                $totals = array_column($perStudent[$s->id], 'total');
                $rows[] = [
                    'student' => ['id' => $s->id, 'student_no' => $s->student_no, 'full_name' => $s->full_name],
                    'lesson' => ['id' => $lesson->id, 'name' => $lesson->name],
                    'level' => $lesson->level ? ['id' => $lesson->level->id, 'name' => $lesson->level->name()] : null,
                    'score' => round(array_sum($totals) / count($totals), 2),
                    'subjects' => $perStudent[$s->id],
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$b['score'], $a['student']['full_name']] <=> [$a['score'], $b['student']['full_name']]);
        $rank = 0;
        $prev = null;
        foreach ($rows as $i => &$r) {
            if ($prev === null || $r['score'] < $prev) {
                $rank = $i + 1;
                $prev = $r['score'];
            }
            $r['rank'] = $rank;
        }
        unset($r);

        return $rows;
    }
}
