<?php

namespace App\Http\Controllers\Api\Evaluation;

use App\Http\Controllers\Controller;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationScore;
use App\Models\Subject;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * @group Education follow-up
 * @subgroup Evaluation criteria
 *
 * U5 التقييمات: the criteria each subject is evaluated on. evaluation_criteria.manage edits; evaluations.view reads.
 * Quran's four system criteria can be renamed and reordered only (never deleted, switched off or re-scaled), because
 * the four evaluation columns every existing reader uses mirror them.
 */
class CriteriaController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('evaluation_criteria.manage') || $request->user()->can('evaluations.view'), 403);
        $subjects = Subject::ordered()->get();
        $subjectId = $request->integer('subject_id') ?: Subject::quranId();
        $criteria = EvaluationCriterion::where('subject_id', $subjectId)->ordered()->get();
        $used = EvaluationScore::whereIn('criterion_id', $criteria->pluck('id')->all() ?: [0])->distinct()->pluck('criterion_id')->all();

        return response()->json([
            'subjects' => $subjects->map(fn (Subject $s) => ['id' => $s->id, 'name' => $s->name(), 'code' => $s->code, 'is_active' => $s->is_active,
                'criteria_count' => EvaluationCriterion::where('subject_id', $s->id)->where('is_active', true)->count()]),
            'subject_id' => $subjectId,
            'data' => $criteria->map(fn ($c) => self::row($c) + ['in_use' => in_array($c->id, $used, true)]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['subject_id' => ['required', 'integer', 'exists:subjects,id']] + $this->rules());
        $data['sort'] ??= (int) EvaluationCriterion::where('subject_id', $data['subject_id'])->max('sort') + 1;
        $c = EvaluationCriterion::create($data + ['is_system' => false, 'key' => null]);
        $this->audit->record('evaluation_criterion.created', $c, [], $c->only(['subject_id', 'name_ar', 'max_score', 'weight']));

        return response()->json(['message' => __('evaluation_criteria.saved'), 'data' => self::row($c->fresh())], 201);
    }

    public function update(Request $request, EvaluationCriterion $criterion): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate($this->rules(true));
        if ($criterion->is_system) {
            foreach (['max_score', 'weight', 'is_active'] as $k) {
                if (array_key_exists($k, $data) && $data[$k] != $criterion->{$k}) {
                    throw ValidationException::withMessages([$k => __('evaluation_criteria.errors.system')]);
                }
            }
            $data = array_intersect_key($data, array_flip(['name_ar', 'name_en', 'sort']));
        } elseif (array_key_exists('max_score', $data) && $data['max_score'] < $criterion->max_score
            && EvaluationScore::where('criterion_id', $criterion->id)->where('score', '>', $data['max_score'])->exists()) {
            throw ValidationException::withMessages(['max_score' => __('evaluation_criteria.errors.max_below_scores')]);
        }
        $old = $criterion->only(array_keys($data));
        $criterion->update($data);
        $this->audit->record('evaluation_criterion.updated', $criterion, $old, $criterion->only(array_keys($data)));

        return response()->json(['message' => __('evaluation_criteria.saved'), 'data' => self::row($criterion->fresh())]);
    }

    public function destroy(Request $request, EvaluationCriterion $criterion): JsonResponse
    {
        $this->authorizeManage($request);
        if ($criterion->is_system) {
            throw ValidationException::withMessages(['criterion' => __('evaluation_criteria.errors.system')]);
        }
        if (EvaluationScore::where('criterion_id', $criterion->id)->exists()) {
            throw ValidationException::withMessages(['criterion' => __('evaluation_criteria.errors.in_use')]);
        }
        $this->audit->record('evaluation_criterion.deleted', $criterion, $criterion->only(['subject_id', 'name_ar']), []);
        $criterion->delete();

        return response()->json(['message' => __('evaluation_criteria.deleted')]);
    }

    /** New order of a subject's criteria: ids in order. */
    public function reorder(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate(['subject_id' => ['required', 'integer', 'exists:subjects,id'], 'ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'distinct']]);
        $rows = EvaluationCriterion::where('subject_id', $data['subject_id'])->whereIn('id', $data['ids'])->get()->keyBy('id');
        if ($rows->count() !== count($data['ids'])) {
            throw ValidationException::withMessages(['ids' => __('evaluation_criteria.errors.subject')]);
        }
        foreach (array_values($data['ids']) as $i => $id) {
            $rows[$id]->update(['sort' => $i + 1]);
        }

        return response()->json(['message' => __('evaluation_criteria.saved')]);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name_ar' => [$req, 'string', 'max:120'],
            'name_en' => [$req, 'string', 'max:120'],
            'max_score' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'weight' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
        ];
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->can('evaluation_criteria.manage'), 403);
    }

    private static function row(EvaluationCriterion $c): array
    {
        return [
            'id' => $c->id, 'subject_id' => $c->subject_id, 'key' => $c->key, 'name_ar' => $c->name_ar, 'name_en' => $c->name_en, 'name' => $c->name(),
            'max_score' => $c->max_score, 'weight' => $c->weight, 'is_system' => $c->is_system, 'sort' => $c->sort, 'is_active' => $c->is_active,
        ];
    }
}
