<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Models\Subject;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Master data
 * @subgroup Subjects
 *
 * Taught subjects. Quran is a system subject: it can be renamed or reordered, not deleted, deactivated or re-coded.
 */
class SubjectController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('lessons.view') || $request->user()->can('subjects.manage'), 403);

        $subjects = Subject::query()
            ->when($request->boolean('active'), fn ($q) => $q->where('is_active', true))
            ->ordered()->get();

        return response()->json(['data' => $subjects->map(fn (Subject $s) => $this->present($s))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('subjects.manage'), 403);
        $subject = Subject::create($this->validated($request) + ['is_system' => false]);
        $this->audit->record('subject.created', $subject, [], $subject->only(['name_ar', 'code']));

        return response()->json(['message' => __('subjects.saved'), 'data' => $this->present($subject)], 201);
    }

    public function update(Request $request, Subject $subject): JsonResponse
    {
        abort_unless($request->user()->can('subjects.manage'), 403);
        $data = $this->validated($request, $subject);
        if ($subject->is_system) {
            if (array_key_exists('code', $data) && $data['code'] !== $subject->code) {
                throw ValidationException::withMessages(['code' => __('subjects.errors.system_code')]);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => __('subjects.errors.system_active')]);
            }
        }
        $old = $subject->only(['name_ar', 'name_en', 'code', 'is_active']);
        $subject->update($data);
        $this->audit->record('subject.updated', $subject, $old, $subject->only(array_keys($old)));

        return response()->json(['message' => __('subjects.saved'), 'data' => $this->present($subject)]);
    }

    public function destroy(Request $request, Subject $subject): JsonResponse
    {
        abort_unless($request->user()->can('subjects.manage'), 403);
        if ($subject->is_system) {
            throw ValidationException::withMessages(['subject' => __('subjects.errors.system_delete')]);
        }
        if (\App\Models\LevelSubject::where('subject_id', $subject->id)->exists() || \App\Models\SubjectLesson::where('subject_id', $subject->id)->exists()
            || \App\Models\TimetableSlot::where('subject_id', $subject->id)->exists()) {
            throw ValidationException::withMessages(['subject' => __('term_setup.errors.in_use')]);
        }
        $this->audit->record('subject.deleted', $subject, $subject->only(['name_ar', 'code']), []);
        $subject->delete();

        return response()->json(['message' => __('subjects.deleted')]);
    }

    private function validated(Request $request, ?Subject $subject = null): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:100', Rule::unique('subjects', 'name_ar')->ignore($subject?->id)],
            'name_en' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:30', 'alpha_dash', Rule::unique('subjects', 'code')->ignore($subject?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:999'],
            'is_active' => ['boolean'],
        ]);
    }

    private function present(Subject $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name(),
            'name_ar' => $s->name_ar,
            'name_en' => $s->name_en,
            'code' => $s->code,
            'description' => $s->description,
            'is_system' => $s->is_system,
            'sort' => $s->sort,
            'is_active' => $s->is_active,
        ];
    }
}
