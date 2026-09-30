<?php

namespace App\Http\Controllers\Api\Grades;

use App\Http\Controllers\Controller;
use App\Models\Exam;
use App\Models\GradeComponent;
use App\Models\GradeEntry;
use App\Models\LevelSubject;
use App\Services\AuditLogger;
use App\Services\Grades\Gradebook;
use App\Support\TermScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Grades
 * @subgroup Grade distribution
 *
 * توزيع الدرجات: the grade components of each level subject (مواد المستويات) of the term. Weights are percent shares
 * of the subject's total: they may not add up to more than 100 (a warning is returned while they are not exactly 100).
 * A component of kind "exam" links one exam of that subject in a class or package of the level this term.
 * grades.manage.
 */
class GradeComponentController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    /** The term's level subjects; with level_subject_id, its components, weight total and the exams that can be linked. */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $term = TermScope::single($request);
        $request->validate(['level_subject_id' => ['nullable', 'integer']]);

        $subjects = LevelSubject::with(['level', 'subject'])->where('academic_term_id', $term->id)->get()
            ->sortBy(fn (LevelSubject $ls) => sprintf('%05d-%05d-%05d-%05d', $ls->level?->sort ?? 0, $ls->level_id, $ls->sort, $ls->id))->values();
        $payload = [
            'term' => ['id' => $term->id, 'name' => $term->name()],
            'level_subjects' => $subjects->map(fn (LevelSubject $ls) => [
                'id' => $ls->id, 'level' => ['id' => $ls->level->id, 'name' => $ls->level->name()], 'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()],
            ])->values(),
        ];
        if (! $request->filled('level_subject_id')) {
            return response()->json($payload);
        }
        $ls = $subjects->firstWhere('id', $request->integer('level_subject_id'));
        abort_if(! $ls, 404);

        return response()->json($payload + $this->detail($ls));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request);
        $ls = LevelSubject::findOrFail($data['level_subject_id']);
        $data = $this->checked($data, $ls, null);
        $data['sort'] ??= (int) GradeComponent::where('level_subject_id', $ls->id)->max('sort') + 1;

        $component = GradeComponent::create($data);
        $this->audit->record('grade_component.created', $component, [], $component->only(['level_subject_id', 'name_ar', 'kind', 'max_marks', 'weight', 'exam_id']));

        return response()->json(['message' => __('grades.saved'), 'data' => Gradebook::presentComponent($component->load('exam')), 'summary' => $this->summary($ls)], 201);
    }

    public function update(Request $request, GradeComponent $component): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request, $component);
        $ls = $component->levelSubject;
        $data = $this->checked($data, $ls, $component);

        $old = $component->only(['name_ar', 'name_en', 'kind', 'max_marks', 'weight', 'exam_id', 'sort']);
        $component->update($data);
        $this->audit->record('grade_component.updated', $component, $old, $component->only(array_keys($old)));

        return response()->json(['message' => __('grades.saved'), 'data' => Gradebook::presentComponent($component->fresh('exam')), 'summary' => $this->summary($ls)]);
    }

    /** Refused while students have scores in it. */
    public function destroy(Request $request, GradeComponent $component): JsonResponse
    {
        $this->authorizeManage($request);
        if (GradeEntry::where('grade_component_id', $component->id)->exists()) {
            throw ValidationException::withMessages(['component' => __('grades.errors.component_has_grades')]);
        }
        $ls = $component->levelSubject;
        $this->audit->record('grade_component.deleted', $component, $component->only(['level_subject_id', 'name_ar', 'kind', 'max_marks', 'weight', 'exam_id']), []);
        $component->delete();

        return response()->json(['message' => __('grades.deleted'), 'summary' => $this->summary($ls)]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['level_subject_id' => ['required', 'integer', 'exists:level_subjects,id'], 'ids' => ['required', 'array'], 'ids.*' => ['integer', 'distinct']]);
        $ids = GradeComponent::where('level_subject_id', $data['level_subject_id'])->pluck('id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        if (collect($data['ids'])->map(fn ($v) => (int) $v)->sort()->values()->all() !== $ids) {
            throw ValidationException::withMessages(['ids' => __('grades.errors.reorder')]);
        }
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $i => $id) {
                GradeComponent::whereKey($id)->update(['sort' => $i + 1]);
            }
        });

        return response()->json(['message' => __('grades.saved')]);
    }

    // ---------------------------------------------------------------------------------------------

    private function detail(LevelSubject $ls): array
    {
        $components = Gradebook::components($ls);
        $used = GradeEntry::whereIn('grade_component_id', $components->pluck('id')->all() ?: [0])
            ->selectRaw('grade_component_id, count(*) as n')->groupBy('grade_component_id')->pluck('n', 'grade_component_id');

        return [
            'level_subject' => ['id' => $ls->id, 'level' => ['id' => $ls->level->id, 'name' => $ls->level->name()], 'subject' => ['id' => $ls->subject->id, 'name' => $ls->subject->name()]],
            'components' => $components->map(fn (GradeComponent $c) => Gradebook::presentComponent($c) + ['entries' => (int) ($used[$c->id] ?? 0)])->values(),
            'summary' => $this->summary($ls),
            'exams' => Gradebook::linkableExams($ls)->map(fn (Exam $e) => [
                'id' => $e->id, 'name' => $e->name, 'type' => $e->type?->value, 'total_marks' => (int) $e->total_marks, 'exam_date' => $e->exam_date?->toDateString(),
                'where' => $e->lesson?->name ?? $e->package?->localizedName(app()->getLocale()),
                'component_id' => $e->gradeComponent?->id,
            ])->values(),
        ];
    }

    /** Weight total with a warning while it is not exactly 100. */
    private function summary(LevelSubject $ls): array
    {
        $total = round((float) GradeComponent::where('level_subject_id', $ls->id)->sum('weight'), 2);

        return ['weight_total' => $total, 'warning' => abs($total - 100) > 0.001 ? __('grades.weights_not_100', ['total' => rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.')]) : null];
    }

    private function validated(Request $request, ?GradeComponent $c = null): array
    {
        $req = $c ? 'sometimes' : 'required';

        return $request->validate([
            'level_subject_id' => [$c ? 'prohibited' : 'required', 'integer', 'exists:level_subjects,id'],
            'name_ar' => [$req, 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'kind' => [$req, Rule::in(GradeComponent::KINDS)],
            'max_marks' => ['nullable', 'numeric', 'min:0.5', 'max:10000'],
            'weight' => [$req, 'numeric', 'min:0', 'max:100'],
            'exam_id' => ['nullable', 'integer', 'exists:exams,id'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    /** The rules that need the level subject: weights ≤ 100, the linked exam, and not hiding saved scores. */
    private function checked(array $data, LevelSubject $ls, ?GradeComponent $c): array
    {
        unset($data['level_subject_id']);
        $kind = $data['kind'] ?? $c?->kind;
        $data['level_subject_id'] = $ls->id;

        $others = (float) GradeComponent::where('level_subject_id', $ls->id)->when($c, fn ($q) => $q->whereKeyNot($c->id))->sum('weight');
        $weight = (float) ($data['weight'] ?? $c?->weight ?? 0);
        if ($others + $weight > 100.001) {
            throw ValidationException::withMessages(['weight' => __('grades.errors.weights_over', ['left' => rtrim(rtrim(number_format(max(0, 100 - $others), 2, '.', ''), '0'), '.')])]);
        }

        $maxEntry = $c ? GradeEntry::where('grade_component_id', $c->id)->max('score') : null;
        if ($kind === 'exam') {
            if ($maxEntry !== null) {
                throw ValidationException::withMessages(['kind' => __('grades.errors.kind_has_grades')]);
            }
            $examId = array_key_exists('exam_id', $data) ? $data['exam_id'] : $c?->exam_id;
            if ($examId) {
                $exam = Exam::findOrFail($examId);
                $taken = GradeComponent::where('exam_id', $exam->id)->when($c, fn ($q) => $q->whereKeyNot($c->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['exam_id' => __('grades.errors.exam_taken')]);
                }
                if (! Gradebook::examFits($exam, $ls)) {
                    throw ValidationException::withMessages(['exam_id' => __('grades.errors.exam_does_not_fit')]);
                }
                $data['max_marks'] = $exam->total_marks;
            }
            $data['exam_id'] = $examId;
        } else {
            $data['exam_id'] = null;
        }

        $max = $data['max_marks'] ?? $c?->max_marks;
        if ($max === null || (float) $max <= 0) {
            throw ValidationException::withMessages(['max_marks' => __('grades.errors.max_required')]);
        }
        if ($maxEntry !== null && (float) $maxEntry > (float) $max) {
            throw ValidationException::withMessages(['max_marks' => __('grades.errors.max_below_scores', ['score' => (float) $maxEntry])]);
        }

        return $data;
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('grades.manage'), 403);
    }
}
