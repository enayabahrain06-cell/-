<?php

namespace App\Http\Controllers\Api\Grades;

use App\Exports\GradebookExport;
use App\Http\Controllers\Controller;
use App\Models\AcademicTerm;
use App\Models\GradeComponent;
use App\Models\GradeEntry;
use App\Models\Lesson;
use App\Models\LessonStudent;
use App\Models\LevelSubject;
use App\Models\Student;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Grades\Gradebook;
use App\Support\TermScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @group Grades
 * @subgroup Gradebook
 *
 * الدرجات / عرض الدرجات / تنزيل الدرجات: a class's gradebook for a level subject of the term. Teachers of that subject
 * in that class (TeacherScope with the subject; the class teacher always) and managers (lessons.manage, own track)
 * reach it. grades.view reads it, grades.record enters the non-exam components; exam components are read from the
 * linked exam's attempts and entered through the exam (رفع درجات الامتحان).
 */
class GradebookController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** The term's classes the user reaches; with lesson_id the subjects and (subject_id or the first) the book. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('grades.view') || $user->can('grades.record'), 403);
        $term = TermScope::single($request);
        $request->validate(['lesson_id' => ['nullable', 'integer'], 'subject_id' => ['nullable', 'integer'], 'mode' => ['nullable', 'in:view,record']]);
        $record = $request->input('mode') === 'record';

        $classes = Gradebook::termLessons($user, $term)->with('level')->orderBy('name')->get(['lessons.id', 'lessons.name', 'lessons.level_id', 'lessons.gender']);
        $payload = [
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'classes' => $classes->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'level_id' => $l->level_id, 'level' => $l->level?->name()])->values(),
        ];
        if (! $request->filled('lesson_id')) {
            return response()->json($payload);
        }

        $lesson = $classes->firstWhere('id', $request->integer('lesson_id'));
        abort_if(! $lesson, 403);
        $subjects = Gradebook::levelSubjects($lesson, $term, $user, $record);
        $payload['lesson'] = ['id' => $lesson->id, 'name' => $lesson->name, 'level' => $lesson->level?->name()];
        $payload['subjects'] = $subjects->map(fn (LevelSubject $ls) => ['id' => $ls->subject->id, 'name' => $ls->subject->name(), 'level_subject_id' => $ls->id])->values();
        $ls = $request->filled('subject_id') ? $subjects->firstWhere('subject_id', $request->integer('subject_id')) : $subjects->first();
        abort_if($request->filled('subject_id') && ! $ls, 403);
        if (! $ls) {
            return response()->json($payload + ['level_subject' => null]);
        }

        return response()->json($payload + [
            'level_subject' => ['id' => $ls->id, 'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()]],
            'can_record' => Gradebook::canRecord($user, $lesson, $ls->subject_id),
        ] + Gradebook::book($lesson, $ls));
    }

    /** Enter (or clear, score null) the scores of one non-exam component for students of the class. */
    public function save(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'lesson_id' => ['required', 'integer', 'exists:lessons,id'],
            'grade_component_id' => ['required', 'integer', 'exists:grade_components,id'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.student_id' => ['required', 'integer', 'distinct'],
            'entries.*.score' => ['present', 'nullable', 'numeric', 'min:0'],
            'entries.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        $lesson = Lesson::with('package')->findOrFail($data['lesson_id']);
        $component = GradeComponent::with('levelSubject')->findOrFail($data['grade_component_id']);
        $ls = $component->levelSubject;
        if ((int) $ls->level_id !== (int) $lesson->level_id || ! TermScope::matches($lesson->package, (int) $ls->academic_term_id)) {
            throw ValidationException::withMessages(['grade_component_id' => __('grades.errors.not_class_component')]);
        }
        abort_unless(Gradebook::canRecord($user, $lesson, $ls->subject_id), 403);
        if ($component->isExam()) {
            throw ValidationException::withMessages(['grade_component_id' => __('grades.errors.exam_component_read_only')]);
        }
        $enrolled = LessonStudent::where('lesson_id', $lesson->id)->where('status', 'active')->pluck('student_id')->map(fn ($v) => (int) $v)->all();
        foreach ($data['entries'] as $i => $row) {
            if (! in_array((int) $row['student_id'], $enrolled, true)) {
                throw ValidationException::withMessages(["entries.$i.student_id" => __('grades.errors.not_in_class')]);
            }
            if ($row['score'] !== null && (float) $row['score'] > (float) $component->max_marks) {
                throw ValidationException::withMessages(["entries.$i.score" => __('grades.errors.score_over', ['max' => (float) $component->max_marks])]);
            }
        }

        $saved = 0;
        DB::transaction(function () use ($data, $component, $user, &$saved) {
            foreach ($data['entries'] as $row) {
                $entry = GradeEntry::firstOrNew(['grade_component_id' => $component->id, 'student_id' => (int) $row['student_id']]);
                $old = $entry->exists ? ['score' => $entry->score, 'notes' => $entry->notes] : [];
                if ($row['score'] === null) {
                    if ($entry->exists) {
                        $entry->delete();
                        $this->audit->record('grade_entry.cleared', $entry, $old, []);
                    }

                    continue;
                }
                $new = ['score' => round((float) $row['score'], 2), 'notes' => $row['notes'] ?? null];
                if ($entry->exists && (float) $entry->score === $new['score'] && $entry->notes === $new['notes']) {
                    continue;
                }
                $entry->fill($new + ['entered_by' => $user->id, 'entered_at' => now()])->save();
                $this->audit->record($old ? 'grade_entry.updated' : 'grade_entry.recorded', $entry, $old, $new + ['grade_component_id' => $component->id, 'student_id' => $entry->student_id]);
                $saved++;
            }
        });

        return response()->json(['message' => __('grades.entries_saved'), 'saved' => $saved] + Gradebook::book($lesson, $ls));
    }

    /** One student's grades this term: every subject of their class with its components and weighted total. */
    public function student(Request $request, Student $student): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('grades.view'), 403);
        $term = TermScope::single($request);
        $lesson = $this->classOf($student, $user, $term, $request->integer('lesson_id') ?: null);
        abort_if(! $lesson, 404);

        $subjects = Gradebook::levelSubjects($lesson, $term, $user)->map(function (LevelSubject $ls) use ($student) {
            $components = Gradebook::components($ls);
            $cells = Gradebook::cells($components, [$student->id])[$student->id] ?? [];

            return [
                'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()],
                'components' => $components->map(fn (GradeComponent $c) => Gradebook::presentComponent($c) + ['cell' => $cells[$c->id] ?? null])->values(),
                'total' => Gradebook::total($components, $cells),
            ];
        })->values();
        $totals = $subjects->pluck('total')->filter(fn ($v) => $v !== null);

        return response()->json([
            'student' => ['id' => $student->id, 'student_no' => $student->student_no, 'full_name' => $student->full_name],
            'lesson' => ['id' => $lesson->id, 'name' => $lesson->name, 'level' => $lesson->level?->name()],
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'subjects' => $subjects,
            'average' => $totals->isEmpty() ? null : round($totals->avg(), 2),
        ]);
    }

    /** Excel: one subject's gradebook, or (no subject_id) every subject of the class, one sheet each plus a summary. */
    public function export(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->can('grades.view'), 403);
        $term = TermScope::single($request);
        $request->validate(['lesson_id' => ['required', 'integer'], 'subject_id' => ['nullable', 'integer']]);
        $lesson = Gradebook::termLessons($user, $term)->with('level')->find($request->integer('lesson_id'));
        abort_if(! $lesson, 403);
        $subjects = Gradebook::levelSubjects($lesson, $term, $user);
        if ($request->filled('subject_id')) {
            $subjects = $subjects->where('subject_id', $request->integer('subject_id'))->values();
            abort_if($subjects->isEmpty(), 403);
        }
        $books = $subjects->map(fn (LevelSubject $ls) => ['subject' => $ls->subject->name(), 'book' => Gradebook::book($lesson, $ls)])->all();
        $name = 'grades-'.$lesson->id.($request->filled('subject_id') ? '-'.$request->integer('subject_id') : '').'.xlsx';

        return Excel::download(new GradebookExport($lesson->name, $books, ! $request->filled('subject_id')), $name);
    }

    /** The student's active class this term the user reaches (a given lesson_id, else the first). */
    private function classOf(Student $student, User $user, AcademicTerm $term, ?int $lessonId): ?Lesson
    {
        return Gradebook::termLessons($user, $term)->with('level')
            ->whereIn('lessons.id', LessonStudent::where('student_id', $student->id)->where('status', 'active')->select('lesson_id'))
            ->when($lessonId, fn ($q) => $q->whereKey($lessonId))->orderBy('lessons.id')->first();
    }
}
